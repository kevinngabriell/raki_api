<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once __DIR__ . '/weekly_report_data.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function getWeeklyReport($conn, $schema, $company_id, $start_date, $end_date) {
    $data = buildWeeklyReportData($conn, $schema, $company_id, $start_date, $end_date);
    jsonResponse(200, 'Weekly report fetched', $data);
}

// ── Auth & routing ────────────────────────────────────────────────────────────

$headers = getallheaders();

if (!isset($headers['Authorization'])) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 401,
        'endpoint'        => '/dashboard/weekly_report.php',
        'method'          => 'GET',
        'error_message'   => 'Authorization header not found',
        'user_identifier' => null,
        'company_id'      => null,
    ]);
    jsonResponse(401, 'Authorization header not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
    http_response_code(200);
    exit();
}

try {
    $token   = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn   = DB::conn();
    $schema = DB_SCHEMA;

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            $company_id = $_GET['company_id'] ?? null;
            $start_date = $_GET['start_date'] ?? null;
            $end_date   = $_GET['end_date']   ?? null;
            getWeeklyReport($conn, $schema, $company_id, $start_date, $end_date);
            break;

        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/dashboard/weekly_report.php',
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
        'endpoint'        => '/dashboard/weekly_report.php',
        'method'          => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
?>
