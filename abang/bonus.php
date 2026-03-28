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
 * Get all drivers' bonus achievement for the current week.
 *
 * - Drivers are fetched from movira_core_dev.app_user by company_id + role
 * - For each driver, sum total_item from transaction this week
 * - Find the highest bonus tier they have achieved (qty <= total_item)
 * - Find the next bonus tier they are working toward (qty > total_item)
 */
function getAllDriverBonus($conn, $schema, $company_id, $start = null, $end = null) {
    $start ??= date('Y-m-d', strtotime('monday this week'));
    $end   ??= date('Y-m-d', strtotime('sunday this week'));

    // 1. Get all drivers for this company
    $driverQuery = "SELECT username, first_name
                    FROM movira_core_dev.app_user
                    WHERE company_id = '$company_id'
                    AND app_role_id = 'app_role6902bc0cbb991'
                    ORDER BY username ASC";

    $driverResult = mysqli_query($conn, $driverQuery);

    if (!$driverResult || mysqli_num_rows($driverResult) === 0) {
        jsonResponse(404, 'No drivers found for this company');
    }

    $drivers = mysqli_fetch_all($driverResult, MYSQLI_ASSOC);

    // 2. Get all active weekly bonus schemas once (reuse for every driver)
    $schemaResult = mysqli_query($conn,
        "SELECT schema_id, schema_name, qty, bonus_nominal
         FROM {$schema}.bonus_schema
         WHERE frequency = 'weekly' AND is_active = 1
         ORDER BY qty ASC"
    );
    $bonus_schemas = $schemaResult ? mysqli_fetch_all($schemaResult, MYSQLI_ASSOC) : [];

    // 3. For each driver, compute their bonus status
    $result = [];

    foreach ($drivers as $driver) {
        $username = mysqli_real_escape_string($conn, $driver['username']);

        // Sum total_item for current week
        $trxResult = mysqli_query($conn,
            "SELECT COALESCE(SUM(total_item), 0) as total_item
             FROM {$schema}.transaction
             WHERE created_by = '$username'
             AND transaction_date BETWEEN '$start' AND '$end'"
        );
        $trxRow      = mysqli_fetch_assoc($trxResult);
        $total_item  = (int)$trxRow['total_item'];

        // Find current achieved bonus (highest tier where qty <= total_item)
        $current_bonus = null;
        $total_bonus_nominal = 0;
        foreach ($bonus_schemas as $s) {
            if ((int)$s['qty'] <= $total_item) {
                $current_bonus = [
                    'schema_id'     => $s['schema_id'],
                    'schema_name'   => $s['schema_name'],
                    'achieved_qty'  => (int)$s['qty'],
                    'bonus_nominal' => (int)$s['bonus_nominal'],
                ];
                $total_bonus_nominal = (int)$s['bonus_nominal'];
            }
        }

        // Find next bonus target (lowest tier where qty > total_item)
        $next_target = null;
        foreach ($bonus_schemas as $s) {
            if ((int)$s['qty'] > $total_item) {
                $remaining  = (int)$s['qty'] - $total_item;
                $percentage = round(($total_item / (int)$s['qty']) * 100);
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

        $result[] = [
            'username'            => $driver['username'],
            'first_name'           => $driver['first_name'],
            'current_total_item'  => $total_item,
            'total_bonus_nominal' => $total_bonus_nominal,
            'current_bonus'       => $current_bonus,
            'next_target'         => $next_target,
        ];
    }

    // Sort by total_item descending (top performer first)
    usort($result, fn($a, $b) => $b['current_total_item'] - $a['current_total_item']);

    jsonResponse(200, 'Driver bonus summary', [
        'period' => [
            'start' => $start,
            'end'   => $end,
        ],
        'total_drivers' => count($result),
        'drivers'       => $result,
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
