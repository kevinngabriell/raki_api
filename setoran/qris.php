<?php
// PATCH /setoran/qris.php
//   { company_id, date, typed_total }                       - QRIS total typed from the bank mutation (null clears)
//   { company_id, transaction_id, status: "received"|"problem"|"open" } - mark an unmatched QRIS sale
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

try {
    $decoded = requireAuth();
    $conn = DB::conn();
    $schema = DB_SCHEMA;
    requireManager($decoded);
    if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') jsonResponse(405, 'Method Not Allowed');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) jsonResponse(400, 'Invalid JSON body');
    $companyId = requireCompanyAccess($conn, $decoded, $input['company_id'] ?? null);
    $by = $decoded->username;

    if (array_key_exists('typed_total', $input)) {
        $date = (string)($input['date'] ?? '');
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) jsonResponse(400, 'date harus berformat YYYY-MM-DD');
        $total = $input['typed_total'];
        if ($total !== null && (!is_numeric($total) || $total < 0)) jsonResponse(400, 'typed_total harus angka >= 0');
        $total = $total === null ? null : (int)$total;
        $stmt = $conn->prepare("INSERT INTO {$schema}.setoran_day (company_id, setoran_date, qris_typed_total) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE qris_typed_total = VALUES(qris_typed_total)");
        $stmt->bind_param('ssi', $companyId, $date, $total);
        $stmt->execute();
        $stmt->close();
        jsonResponse(200, 'Total QRIS disimpan');
    }

    if (isset($input['transaction_id'])) {
        $trx = (string)$input['transaction_id'];
        $status = (string)($input['status'] ?? '');
        if (!in_array($status, ['received', 'problem', 'open'], true)) jsonResponse(400, 'status must be received, problem or open');

        $stmt = $conn->prepare("SELECT 1 FROM {$schema}.transaction WHERE transaction_id = ? AND company_id = ?");
        $stmt->bind_param('ss', $trx, $companyId);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_row()) jsonResponse(404, 'Transaction not found');
        $stmt->close();

        if ($status === 'open') {
            $stmt = $conn->prepare("DELETE FROM {$schema}.setoran_qris_flag WHERE company_id = ? AND transaction_id = ?");
            $stmt->bind_param('ss', $companyId, $trx);
        } else {
            $stmt = $conn->prepare("INSERT INTO {$schema}.setoran_qris_flag (company_id, transaction_id, status, updated_by) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE status = VALUES(status), updated_by = VALUES(updated_by)");
            $stmt->bind_param('ssss', $companyId, $trx, $status, $by);
        }
        $stmt->execute();
        $stmt->close();
        jsonResponse(200, 'Status QRIS disimpan');
    }

    jsonResponse(400, 'Kirim typed_total (dengan date) atau transaction_id dengan status');
} catch (Throwable $e) {
    logApiError(DB::conn(), ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/setoran/qris.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
