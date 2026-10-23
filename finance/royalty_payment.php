<?php
// Royalti payments of a Mitra to pusat (Owner only; partial payments allowed, over-payment refused).
//   POST   { company_id, month: "YYYY-MM", amount, paid_on, method: tunai|transfer }
//   DELETE ?payment_id        remove a wrong payment
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once 'lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$decoded = requireAuth();
$conn = DB::conn();
$schema = DB_SCHEMA;

try {
    requireOwner($decoded);
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'POST':
            $in = json_decode(file_get_contents('php://input'), true);
            if (!is_array($in)) jsonResponse(400, 'Invalid JSON body');
            $companyId = trim((string)($in['company_id'] ?? ''));
            if ($companyId === '' || !accessCompanyIsMitra($conn, $companyId)) jsonResponse(400, 'company_id harus outlet Mitra');
            $month = (string)($in['month'] ?? '');
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) jsonResponse(400, 'month harus berformat YYYY-MM');
            if (!isset($in['amount']) || !is_numeric($in['amount']) || (int)$in['amount'] <= 0) jsonResponse(400, 'amount harus lebih dari 0');
            $amount = (int)$in['amount'];
            $paidOn = (string)($in['paid_on'] ?? date('Y-m-d'));
            $d = DateTime::createFromFormat('Y-m-d', $paidOn);
            if (!$d || $d->format('Y-m-d') !== $paidOn) jsonResponse(400, 'paid_on harus berformat YYYY-MM-DD');
            $method = (string)($in['method'] ?? '');
            if (!in_array($method, ['tunai', 'transfer'], true)) jsonResponse(400, 'method must be tunai or transfer');

            // What the Mitra owes for that whole month.
            $stmt = $conn->prepare("SELECT type, percent, fixed FROM {$schema}.finance_royalty_rule WHERE company_id = ?");
            $stmt->bind_param('s', $companyId);
            $stmt->execute();
            $rule = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$rule) jsonResponse(409, 'Atur aturan royalti outlet ini dulu', ['reason' => 'no_rule']);
            $first = $month . '-01';
            $last = $month . '-' . financeDaysInMonth($month);
            $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM {$schema}.transaction WHERE company_id = ? AND transaction_date BETWEEN ? AND ?");
            $stmt->bind_param('sss', $companyId, $first, $last);
            $stmt->execute();
            $owed = financeRoyalty($rule, (int)$stmt->get_result()->fetch_row()[0], $first, $last);
            $stmt->close();

            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM {$schema}.finance_royalty_payment WHERE company_id = ? AND period = ? FOR UPDATE");
                $stmt->bind_param('ss', $companyId, $month);
                $stmt->execute();
                $paid = (int)$stmt->get_result()->fetch_row()[0];
                $stmt->close();
                if ($paid + $amount > $owed) {
                    $conn->rollback();
                    jsonResponse(409, 'Jumlah melebihi sisa royalti bulan ini (Rp ' . number_format(max(0, $owed - $paid), 0, ',', '.') . ')', ['reason' => 'over_payment', 'remaining' => max(0, $owed - $paid)]);
                }
                $id = 'rpay' . uniqid();
                $by = $decoded->username;
                $stmt = $conn->prepare("INSERT INTO {$schema}.finance_royalty_payment (payment_id, company_id, period, amount, paid_on, method, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssisss', $id, $companyId, $month, $amount, $paidOn, $method, $by);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            jsonResponse(201, 'Pembayaran royalti dicatat', ['payment_id' => $id, 'remaining' => $owed - $paid - $amount]);
            break;

        case 'DELETE':
            $id = trim((string)($_GET['payment_id'] ?? ''));
            if ($id === '') jsonResponse(400, 'payment_id wajib diisi');
            $stmt = $conn->prepare("DELETE FROM {$schema}.finance_royalty_payment WHERE payment_id = ?");
            $stmt->bind_param('s', $id);
            $stmt->execute();
            $n = $stmt->affected_rows;
            $stmt->close();
            if ($n === 0) jsonResponse(404, 'Pembayaran tidak ditemukan');
            jsonResponse(200, 'Pembayaran dihapus');
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Throwable $e) {
    logApiError($conn, ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/finance/royalty_payment.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
