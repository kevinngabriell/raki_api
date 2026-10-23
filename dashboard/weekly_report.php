<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once __DIR__ . '/weekly_report_data.php';

function getWeeklyReport($conn, $schema, $company_id, $start_date, $end_date) {
    $data = buildWeeklyReportData($conn, $schema, $company_id, $start_date, $end_date);
    jsonResponse(200, 'Weekly report fetched', $data);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $decoded = requireAuth();

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
        'user_identifier' => null,
        'company_id'      => null,
    ]);
    jsonResponse(500, 'Internal Server Error');
}
?>
