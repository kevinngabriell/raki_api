<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/**
 * Split [start, end] into Mon-Sat bonus weeks whose Saturday (payout day) falls
 * within that range. Weeks are continuous and never reset on the 1st of a month -
 * e.g. the week of 31 Aug - 5 Sep belongs to September, since its Saturday is 5 Sep.
 */
function getBonusWeekPeriods(string $start, string $end): array {
    $periods  = [];
    $rangeEnd = new DateTime($end);

    $cursor = new DateTime($start);
    $dow    = (int)$cursor->format('N');
    $cursor->modify('-' . ($dow - 1) . ' days');

    while (true) {
        $weekStart = clone $cursor;
        $weekEnd   = (clone $cursor)->modify('+5 days');
        if ($weekEnd > $rangeEnd) {
            break;
        }
        if ($weekEnd->format('Y-m-d') >= $start) {
            $periods[] = [
                'start' => $weekStart->format('Y-m-d'),
                'end'   => $weekEnd->format('Y-m-d'),
            ];
        }
        $cursor->modify('+7 days');
    }

    return $periods;
}

/**
 * Get all drivers' bonus achievement for a date range.
 */
function getAllDriverBonus($conn, $schema, $company_id, $start = null, $end = null) {
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $start ??= date('Y-m-d', strtotime('monday this week'));
    $end   ??= date('Y-m-d', strtotime('saturday this week'));

    // 1. Get all drivers for this company
    $stmtDrivers = $conn->prepare("SELECT username, first_name
                    FROM {$coreSchema}.app_user
                    WHERE company_id = ?
                    AND app_role_id = 'app_role6902bc0cbb991'
                    AND account_status NOT IN ('suspended', 'disabled', 'rejected')
                    ORDER BY username ASC");

    if (!$stmtDrivers) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/abang/bonus.php',
            'method'          => 'GET',
            'error_message'   => 'Failed to prepare statement for drivers',
            'user_identifier' => null,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Failed to prepare statement');
    }

    $stmtDrivers->bind_param('s', $company_id);
    $stmtDrivers->execute();
    $driverResult = $stmtDrivers->get_result();

    if (!$driverResult || $driverResult->num_rows === 0) {
        jsonResponse(404, 'No drivers found for this company');
    }

    $drivers = $driverResult->fetch_all(MYSQLI_ASSOC);
    $stmtDrivers->close();

    // 2. Get all active weekly bonus schemas for this company once (reused for every week)
    $stmtSchema = $conn->prepare("SELECT schema_id, schema_name, qty, bonus_nominal
         FROM {$schema}.bonus_schema
         WHERE company_id = ? AND frequency = 'weekly' AND is_active = 1
         ORDER BY qty ASC");

    if (!$stmtSchema) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/abang/bonus.php',
            'method'          => 'GET',
            'error_message'   => 'Failed to prepare statement for bonus schemas',
            'user_identifier' => null,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Failed to prepare statement');
    }

    $stmtSchema->bind_param('s', $company_id);
    $stmtSchema->execute();
    $schemaResult = $stmtSchema->get_result();
    $bonus_schemas = $schemaResult ? $schemaResult->fetch_all(MYSQLI_ASSOC) : [];
    $stmtSchema->close();

    // 3. Initialize per-driver accumulators
    $result = [];
    foreach ($drivers as $driver) {
        $result[$driver['username']] = [
            'username'            => $driver['username'],
            'first_name'          => $driver['first_name'],
            'current_total_item'  => 0,
            'total_bonus_nominal' => 0,
            'current_bonus'       => null,
            'next_target'         => null,
            'weekly_breakdown'    => [],
        ];
    }

    // 4. Walk each Mon-Sat week in the requested range and award that week's own tier
    $weekPeriods = getBonusWeekPeriods($start, $end);

    // Prepare the weekly transaction query once (reused for each week)
    $stmtTrx = $conn->prepare("SELECT created_by, COALESCE(SUM(total_item), 0) as total_item
             FROM {$schema}.transaction
             WHERE company_id = ?
             AND DATE(transaction_date) BETWEEN ? AND ?
             GROUP BY created_by");

    if (!$stmtTrx) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/abang/bonus.php',
            'method'          => 'GET',
            'error_message'   => 'Failed to prepare statement for weekly transactions',
            'user_identifier' => null,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Failed to prepare statement');
    }

    foreach ($weekPeriods as $i => $week) {
        $weekStart = $week['start'];
        $weekEnd   = $week['end'];

        $stmtTrx->bind_param('sss', $company_id, $weekStart, $weekEnd);
        $stmtTrx->execute();
        $trxResult = $stmtTrx->get_result();

        $weekItemsByDriver = [];
        while ($row = $trxResult->fetch_assoc()) {
            $weekItemsByDriver[mb_strtolower($row['created_by'])] = (int)$row['total_item'];
        }

        $isLastWeek = ($i === count($weekPeriods) - 1);

        foreach ($drivers as $driver) {
            $username        = $driver['username'];
            $week_total_item = $weekItemsByDriver[mb_strtolower($username)] ?? 0;

            $week_bonus = null;
            foreach ($bonus_schemas as $s) {
                if ((int)$s['qty'] <= $week_total_item) {
                    $week_bonus = [
                        'schema_id'     => $s['schema_id'],
                        'schema_name'   => $s['schema_name'],
                        'achieved_qty'  => (int)$s['qty'],
                        'bonus_nominal' => (int)$s['bonus_nominal'],
                    ];
                }
            }

            $result[$username]['current_total_item'] += $week_total_item;
            $result[$username]['total_bonus_nominal'] += $week_bonus['bonus_nominal'] ?? 0;
            $result[$username]['weekly_breakdown'][]   = [
                'week_start'    => $weekStart,
                'week_end'      => $weekEnd,
                'total_item'    => $week_total_item,
                'bonus_nominal' => $week_bonus['bonus_nominal'] ?? 0,
                'schema_name'   => $week_bonus['schema_name'] ?? null,
            ];

            if ($isLastWeek) {
                $result[$username]['current_bonus'] = $week_bonus;

                $next_target = null;
                foreach ($bonus_schemas as $s) {
                    if ((int)$s['qty'] > $week_total_item) {
                        $remaining  = (int)$s['qty'] - $week_total_item;
                        $percentage = round(($week_total_item / (int)$s['qty']) * 100);
                        $next_target = [
                            'schema_id'           => $s['schema_id'],
                            'schema_name'         => $s['schema_name'],
                            'target_qty'          => (int)$s['qty'],
                            'bonus_nominal'       => (int)$s['bonus_nominal'],
                            'remaining_item'      => $remaining,
                            'progress_percentage' => min(100, $percentage),
                        ];
                        break;
                    }
                }
                $result[$username]['next_target'] = $next_target;
            }
        }
    }
    $stmtTrx->close();

    $result = array_values($result);

    usort($result, fn($a, $b) => $b['total_bonus_nominal'] - $a['total_bonus_nominal']);

    $driversWithBonus = count(array_filter($result, fn($d) => $d['total_bonus_nominal'] > 0));
    $totalBonusPaid   = array_sum(array_column($result, 'total_bonus_nominal'));

    jsonResponse(200, 'Driver bonus summary', [
        'period' => [
            'start' => $start,
            'end'   => $end,
        ],
        'total_drivers'      => count($result),
        'drivers_with_bonus' => $driversWithBonus,
        'total_bonus_paid'   => $totalBonusPaid,
        'drivers'            => $result,
    ]);
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $decoded = requireAuth();

    $conn       = DB::conn();
    $schema     = DB_SCHEMA;
    $company_id = $decoded->company_id;

    switch ($method) {
        case 'GET':
            $start      = $_GET['start']      ?? null;
            $end        = $_GET['end']        ?? null;
            $company_id = $_GET['company_id'] ?? $company_id;
            getAllDriverBonus($conn, $schema, $company_id, $start, $end);
            break;

        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/abang/bonus.php',
                'method'          => $method,
                'error_message'   => 'Method Not Allowed',
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/abang/bonus.php',
        'method'          => $method ?? '',
        'error_message'   => $e->getMessage(),
        'user_identifier' => null,
        'company_id'      => null,
    ]);
    jsonResponse(500, 'Internal Server Error');
}
