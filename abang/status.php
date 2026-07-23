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
 * Online/offline + last-seen status for every driver belonging to a company.
 *
 * "Online" means the driver currently has a work_session with status='active'.
 * For offline drivers, last_seen is the end of their most recent session
 * (or its start, if it was never properly closed).
 */
function getDriverStatus($conn, $schema, $company_id) {
    $stmtRoster = $conn->prepare("SELECT username, first_name, phone_number FROM movira_core_dev.app_user WHERE company_id = ? AND app_role_id = 'app_role6902bc0cbb991' ORDER BY first_name ASC, username ASC");

    if (!$stmtRoster) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/abang/status.php',
            'method'          => 'GET',
            'error_message'   => 'Failed to prepare statement for driver roster: ' . $conn->error,
            'user_identifier' => null,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Failed to prepare statement', ['error' => $conn->error]);
    }

    $stmtRoster->bind_param('s', $company_id);
    $stmtRoster->execute();
    $drivers = $stmtRoster->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmtRoster->close();

    if (empty($drivers)) {
        jsonResponse(200, 'Driver status fetched', [
            'company_id'    => $company_id,
            'total_drivers' => 0,
            'online_count'  => 0,
            'drivers'       => [],
        ]);
    }

    // Latest work_session per driver. work_session.user_id may hold a username,
    // phone_number, or user_id depending on how the session was opened (same
    // ambiguity handled in session/active-drivers.php and nearby-abang.php), so
    // resolve it back to app_user.username before matching against the roster.
    $stmtSessions = $conn->prepare(
        "SELECT COALESCE(au.username, ws.user_id) AS driver_username, ws.session_id, ws.status, ws.started_at, ws.ended_at
         FROM {$schema}.work_session ws
         INNER JOIN (
             SELECT user_id, MAX(started_at) AS max_started
             FROM {$schema}.work_session
             WHERE company_id = ?
             GROUP BY user_id
         ) latest ON latest.user_id = ws.user_id AND latest.max_started = ws.started_at
         LEFT JOIN movira_core_dev.app_user au ON au.username = ws.user_id OR au.phone_number = ws.user_id OR au.user_id = ws.user_id
         WHERE ws.company_id = ?"
    );

    if (!$stmtSessions) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/abang/status.php',
            'method'          => 'GET',
            'error_message'   => 'Failed to prepare statement for latest sessions: ' . $conn->error,
            'user_identifier' => null,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Failed to prepare statement', ['error' => $conn->error]);
    }

    $stmtSessions->bind_param('ss', $company_id, $company_id);
    $stmtSessions->execute();

    $sessionsByUser = [];
    $resSessions = $stmtSessions->get_result();
    while ($row = $resSessions->fetch_assoc()) {
        $sessionsByUser[$row['driver_username']] = $row;
    }
    $stmtSessions->close();

    $online_count = 0;
    $result = [];

    foreach ($drivers as $driver) {
        $session  = $sessionsByUser[$driver['username']] ?? null;
        $isOnline = $session && $session['status'] === 'active';

        if ($isOnline) {
            $online_count++;
        }

        $result[] = [
            'username'      => $driver['username'],
            'first_name'    => $driver['first_name'],
            'phone_number'  => $driver['phone_number'] ?? null,
            'status'        => $isOnline ? 'online' : 'offline',
            'session_id'    => $session['session_id'] ?? null,
            'last_seen'     => $isOnline ? $session['started_at'] : ($session['ended_at'] ?? $session['started_at'] ?? null),
        ];
    }

    // Online drivers first
    usort($result, fn($a, $b) => ($b['status'] === 'online') <=> ($a['status'] === 'online'));

    jsonResponse(200, 'Driver status fetched', [
        'company_id'    => $company_id,
        'total_drivers' => count($result),
        'online_count'  => $online_count,
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
        'endpoint'        => '/abang/status.php',
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
            $company_id = $_GET['company_id'] ?? $company_id;

            if (!$company_id) {
                logApiError($conn, [
                    'error_level'     => 'error',
                    'http_status'     => 400,
                    'endpoint'        => '/abang/status.php',
                    'method'          => $method,
                    'error_message'   => 'company_id is required',
                    'user_identifier' => $decoded->username ?? null,
                    'company_id'      => null,
                ]);
                jsonResponse(400, 'company_id is required');
            }

            getDriverStatus($conn, $schema, $company_id);
            break;

        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/abang/status.php',
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
        'endpoint'        => '/abang/status.php',
        'method'          => $method ?? '',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
