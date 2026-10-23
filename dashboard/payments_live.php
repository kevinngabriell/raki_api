<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

// GET /dashboard/payments_live.php?company_id&date=YYYY-MM-DD
// Money taken per payment method for one day (default: today), so finance can check it
// against what the outlets report. Sums transaction_payment.amount, so a split payment
// (cash + QRIS) lands in each method once. edc_flazz is kept apart from cash.
// -> { date, total_amount, total_trx, methods: [{ payment_method, total_amount, trx_count }] }

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
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

    $schema = DB_SCHEMA;
    $stmt = $conn->prepare("SELECT tp.payment_method, COALESCE(SUM(tp.amount), 0) AS total_amount, COUNT(DISTINCT t.transaction_id) AS trx_count
        FROM {$schema}.transaction t
        JOIN {$schema}.transaction_payment tp ON tp.transaction_id = t.transaction_id
        WHERE t.company_id = ? AND t.transaction_date = ?
        GROUP BY tp.payment_method
        ORDER BY total_amount DESC");
    $stmt->bind_param('ss', $companyId, $date);
    $stmt->execute();
    $res = $stmt->get_result();
    $methods = [];
    $total = 0;
    while ($row = $res->fetch_assoc()) {
        $amount = (int)$row['total_amount'];
        $total += $amount;
        $methods[] = [
            'payment_method' => $row['payment_method'],
            'total_amount'   => $amount,
            'trx_count'      => (int)$row['trx_count'],
        ];
    }
    $stmt->close();

    $stmtTrx = $conn->prepare("SELECT COUNT(*) AS c FROM {$schema}.transaction WHERE company_id = ? AND transaction_date = ?");
    $stmtTrx->bind_param('ss', $companyId, $date);
    $stmtTrx->execute();
    $totalTrx = (int)($stmtTrx->get_result()->fetch_assoc()['c'] ?? 0);
    $stmtTrx->close();

    jsonResponse(200, 'Live payments fetched', [
        'date'         => $date,
        'total_amount' => $total,
        'total_trx'    => $totalTrx,
        'methods'      => $methods,
    ]);
} catch (Exception $e) {
    logApiError(DB::conn(), [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/dashboard/payments_live.php',
        'method'        => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(),
    ]);
    jsonResponse(500, 'Internal Server Error');
}
