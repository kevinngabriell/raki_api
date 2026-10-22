<?php
// POST /supply/payment.php (multipart) { supply_order_id, amount, paid_on (YYYY-MM-DD), method: tunai|transfer, proof? (image) }
// Payments of a Mitra's completed supply order to pusat. Partial payments are allowed; paying more than the
// remaining bill is refused. recorded_by comes from the token. The payments come back on order.php (list + detail).
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$decoded = requireAuth();
$conn = DB::conn();
$schema = DB_SCHEMA;
$proofUrl = $proofThumb = null;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(405, 'Method Not Allowed');
    requireManager($decoded);
    rejectOversizedBody();

    $in = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);
    $orderId = trim((string)($in['supply_order_id'] ?? ''));
    if ($orderId === '') jsonResponse(400, 'supply_order_id wajib diisi');
    $amount = $in['amount'] ?? null;
    if (!is_numeric($amount) || (int)$amount <= 0) jsonResponse(400, 'amount harus lebih dari 0');
    $amount = (int)$amount;
    $paidOn = (string)($in['paid_on'] ?? date('Y-m-d'));
    $d = DateTime::createFromFormat('Y-m-d', $paidOn);
    if (!$d || $d->format('Y-m-d') !== $paidOn) jsonResponse(400, 'paid_on harus berformat YYYY-MM-DD');
    $method = (string)($in['method'] ?? '');
    if (!in_array($method, ['tunai', 'transfer'], true)) jsonResponse(400, 'method must be tunai or transfer');

    $stmt = $conn->prepare("SELECT supply_order_id, from_company_id, to_company_id, status, total_amount, COALESCE(shipping_cost, 0) AS shipping_cost FROM {$schema}.supply_order WHERE supply_order_id = ?");
    $stmt->bind_param('s', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $mine = accessCompanyIds($conn, $decoded);
    if (!$order || (!in_array($order['from_company_id'], $mine, true) && !in_array($order['to_company_id'], $mine, true))) {
        jsonResponse(404, 'Supply order not found');
    }
    if ($order['status'] !== 'completed' || !accessCompanyIsMitra($conn, $order['from_company_id'])) {
        jsonResponse(409, 'Pembayaran hanya untuk order Mitra yang sudah diterima', ['reason' => 'not_billable']);
    }

    $conn->begin_transaction();
    try {
        // Lock the order so two payments cannot both pass the "remaining" check.
        $stmt = $conn->prepare("SELECT supply_order_id FROM {$schema}.supply_order WHERE supply_order_id = ? FOR UPDATE");
        $stmt->bind_param('s', $orderId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM {$schema}.supply_payment WHERE supply_order_id = ?");
        $stmt->bind_param('s', $orderId);
        $stmt->execute();
        $paid = (int)$stmt->get_result()->fetch_row()[0];
        $stmt->close();
        $remaining = (int)$order['total_amount'] + (int)$order['shipping_cost'] - $paid;
        if ($amount > $remaining) {
            $conn->rollback();
            jsonResponse(409, 'Jumlah melebihi sisa tagihan (Rp ' . number_format($remaining, 0, ',', '.') . ')', ['reason' => 'over_payment', 'remaining' => $remaining]);
        }

        if (!empty($_FILES['proof']) && ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$proofUrl, $proofThumb] = handle_menu_image_upload($_FILES['proof'], 'supply-payment');
            delete_menu_image_urls([$proofThumb]);
        }

        $id = 'spay' . uniqid();
        $by = $decoded->username;
        $stmt = $conn->prepare("INSERT INTO {$schema}.supply_payment (payment_id, supply_order_id, amount, paid_on, method, proof_url, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssissss', $id, $orderId, $amount, $paidOn, $method, $proofUrl, $by);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        if ($proofUrl) delete_menu_image_urls([$proofUrl]);
        throw $e;
    }

    jsonResponse(201, 'Pembayaran dicatat', ['payment_id' => $id, 'remaining' => $remaining - $amount]);
} catch (ApiException $e) {
    throw $e;
} catch (Throwable $e) {
    logApiError($conn, ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/supply/payment.php', 'method' => 'POST',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
