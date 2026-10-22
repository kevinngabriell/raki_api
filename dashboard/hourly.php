<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

// GET /dashboard/hourly.php?company_id&date=YYYY-MM-DD
// Cups per hour for the date and for the same weekday a week earlier (Jam Ramai).
// -> [{ hour, cups_today, cups_compare }] for every hour 0..23.

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function cupsByHour(mysqli $conn, string $schema, string $companyId, string $date): array {
    $stmt = $conn->prepare("SELECT HOUR(t.created_at) AS h, COALESCE(SUM(td.quantity), 0) AS cups
        FROM {$schema}.transaction t
        JOIN {$schema}.transaction_detail td ON td.transaction_id = t.transaction_id
        WHERE t.company_id = ? AND t.transaction_date = ?
        GROUP BY HOUR(t.created_at)");
    $stmt->bind_param('ss', $companyId, $date);
    $stmt->execute();
    $out = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['h']] = (int)$row['cups'];
    }
    $stmt->close();
    return $out;
}

try {
    $decoded = requireAuth();
    $conn = DB::conn();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(405, 'Method Not Allowed');
    }

    $companyId = requireCompanyAccess($conn, $decoded, $_GET['company_id'] ?? null);
    $date = $_GET['date'] ?? date('Y-m-d');
    $parsed = DateTime::createFromFormat('Y-m-d', (string)$date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        jsonResponse(400, 'date harus berformat YYYY-MM-DD');
    }
    $compareDate = (clone $parsed)->modify('-7 days')->format('Y-m-d');

    $today = cupsByHour($conn, DB_SCHEMA, $companyId, $date);
    $compare = cupsByHour($conn, DB_SCHEMA, $companyId, $compareDate);

    $rows = [];
    for ($h = 0; $h < 24; $h++) {
        $rows[] = ['hour' => $h, 'cups_today' => $today[$h] ?? 0, 'cups_compare' => $compare[$h] ?? 0];
    }

    jsonResponse(200, 'Hourly cups fetched', $rows);
} catch (Exception $e) {
    logApiError(DB::conn(), [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/dashboard/hourly.php',
        'method'        => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(),
    ]);
    jsonResponse(500, 'Internal Server Error');
}
