<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, Content-Type");
    header("Access-Control-Allow-Credentials: true");
    http_response_code(204);
    exit();
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Split [start, end] into Mon-Sat bonus weeks whose Saturday (payout day) falls
 * within that range. Weeks are continuous and never reset on the 1st of a month -
 * e.g. the week of 31 Aug - 5 Sep belongs to September, since its Saturday is 5 Sep.
 */
function getBonusWeekPeriods(string $start, string $end): array {
    $periods  = [];
    $rangeEnd = new DateTime($end);

    $cursor = new DateTime($start);
    $dow    = (int)$cursor->format('N'); // 1=Mon ... 7=Sun
    $cursor->modify('-' . ($dow - 1) . ' days'); // back up to Monday on/before $start

    while (true) {
        $weekStart = clone $cursor;
        $weekEnd   = (clone $cursor)->modify('+5 days'); // Saturday
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
 *
 * Bonus weeks run Mon-Sat (paid every Saturday). A range spanning several weeks
 * (e.g. a full month) is NOT a single tier lookup against the range's item total -
 * each Mon-Sat week awards its own tier independently, and those are summed.
 * This also means the current-week default case (start=Monday, end=Saturday of
 * the same week) still behaves exactly as before: exactly one week is produced.
 *
 * - Drivers are fetched from movira_core_dev.app_user by company_id + role
 * - Bonus tiers are fetched from bonus_schema, scoped to this company
 * - For each Mon-Sat week in range, each driver's tier is the highest qty <= that week's total_item
 * - current_bonus / next_target reflect the most recent week in the requested range
 */
function getAllDriverBonus($conn, $schema, $company_id, $start = null, $end = null) {
    $start ??= date('Y-m-d', strtotime('monday this week'));
    $end   ??= date('Y-m-d', strtotime('saturday this week'));

    $company_id_esc = mysqli_real_escape_string($conn, $company_id);

    // 1. Get all drivers for this company
    $driverQuery = "SELECT username, first_name
                    FROM movira_core_dev.app_user
                    WHERE company_id = '$company_id_esc'
                    AND app_role_id = 'app_role6902bc0cbb991'
                    ORDER BY username ASC";

    $driverResult = mysqli_query($conn, $driverQuery);

    if (!$driverResult || mysqli_num_rows($driverResult) === 0) {
        jsonResponse(404, 'No drivers found for this company');
    }

    $drivers = mysqli_fetch_all($driverResult, MYSQLI_ASSOC);

    // 2. Get all active weekly bonus schemas for this company once (reused for every week)
    $schemaResult = mysqli_query($conn,
        "SELECT schema_id, schema_name, qty, bonus_nominal
         FROM {$schema}.bonus_schema
         WHERE company_id = '$company_id_esc' AND frequency = 'weekly' AND is_active = 1
         ORDER BY qty ASC"
    );
    $bonus_schemas = $schemaResult ? mysqli_fetch_all($schemaResult, MYSQLI_ASSOC) : [];

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

    foreach ($weekPeriods as $i => $week) {
        $weekStart = $week['start'];
        $weekEnd   = $week['end'];

        $trxResult = mysqli_query($conn,
            "SELECT created_by, COALESCE(SUM(total_item), 0) as total_item
             FROM {$schema}.transaction
             WHERE company_id = '$company_id_esc'
             AND DATE(transaction_date) BETWEEN '$weekStart' AND '$weekEnd'
             GROUP BY created_by"
        );
        $weekItemsByDriver = [];
        while ($row = mysqli_fetch_assoc($trxResult)) {
            $weekItemsByDriver[mb_strtolower($row['created_by'])] = (int)$row['total_item'];
        }

        $isLastWeek = ($i === count($weekPeriods) - 1);

        foreach ($drivers as $driver) {
            $username        = $driver['username'];
            $week_total_item = $weekItemsByDriver[mb_strtolower($username)] ?? 0;

            // Highest tier achieved THIS week (qty <= week_total_item)
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

    $result = array_values($result);

    // Sort by total bonus earned in the period descending (top performer first)
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

// ─────────────────────────────────────────────
// ROUTING
// ─────────────────────────────────────────────
$method  = $_SERVER['REQUEST_METHOD'];
$conn    = DB::conn();
$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);

if (!$authHeader) {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 401,
        'endpoint'        => '/abang/bonus.php',
        'method'          => $method,
        'error_message'   => 'Authorization header not found',
        'user_identifier' => null,
        'company_id'      => null,
    ]);
    jsonResponse(401, 'Authorization header not found');
}

try {
    $token   = preg_replace('/^Bearer\s+/i', '', $authHeader);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

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
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/abang/bonus.php',
        'method'          => $method ?? '',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
