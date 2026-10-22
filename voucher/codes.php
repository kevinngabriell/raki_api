<?php
// GET /voucher/codes.php?voucher_id
// The codes of a unique batch -> [{ code, used_at, used_by, total_amount }]

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once 'engine.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, 'Method Not Allowed');
}

$decoded = requireAuth();
$conn = DB::conn();

try {
    requireManager($decoded);
    $schema = DB_SCHEMA;
    $voucherId = trim((string)($_GET['voucher_id'] ?? ''));
    if ($voucherId === '') {
        jsonResponse(400, 'voucher_id is required');
    }

    $companies = accessCompanyIds($conn, $decoded) ?: ['-'];
    [$in, $types] = accessInList($companies);
    $stmt = $conn->prepare("SELECT 1 FROM {$schema}.voucher WHERE voucher_id = ? AND company_id IN ($in)");
    $params = array_merge([$voucherId], $companies);
    $stmt->bind_param('s' . $types, ...$params);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_row()) {
        jsonResponse(404, 'Voucher not found');
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT vc.code, vc.used_at, vc.used_by, t.total_amount
        FROM {$schema}.voucher_code vc LEFT JOIN {$schema}.transaction t ON t.transaction_id = vc.transaction_id
        WHERE vc.voucher_id = ? ORDER BY vc.used_at IS NULL, vc.used_at DESC, vc.code");
    $stmt->bind_param('s', $voucherId);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->get_result() as $r) {
        $rows[] = ['code' => $r['code'], 'used_at' => $r['used_at'], 'used_by' => $r['used_by'], 'total_amount' => $r['total_amount'] === null ? null : (int)$r['total_amount']];
    }
    $stmt->close();

    jsonResponse(200, 'Voucher codes fetched', $rows);
} catch (Throwable $e) {
    logApiError($conn, ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/voucher/codes.php', 'method' => 'GET',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
