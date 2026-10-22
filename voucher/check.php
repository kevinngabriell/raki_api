<?php
// POST /voucher/check.php
//
// Stateless preview for the POS: does this code work for this cart, and what is due after it?
// Body: { company_id?, code, channel: "outlet"|"abang", items: [{ menu_id|package_id, quantity, unit_price }],
//         transaction_date? }
// -> { valid, reason, voucher_name, discount_amount, total_amount, min_subtotal? }
// Runs the promo first (a voucher stacks with a promo), so total_amount is the amount due after both.
// Nothing is written; POST /transaction/index.php validates again and records the use.

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
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, 'Method Not Allowed');
}

$decoded = requireAuth();
$conn = null;

try {
    $conn = DB::conn();
    $schema = DB_SCHEMA;

    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
        jsonResponse(400, 'Invalid JSON body');
    }

    $companyId = $input['company_id'] ?? $decoded->company_id ?? null;
    if (!is_string($companyId) || $companyId === '') {
        jsonResponse(400, 'company_id is required (not found in payload or token).');
    }
    if ($companyId !== ($decoded->company_id ?? null)) {
        requireCompanyAccess($conn, $decoded, $companyId);
    }
    $code = voucherNormalizeCode($input['code'] ?? '');
    if ($code === '') {
        jsonResponse(400, 'code is required');
    }
    $channel = in_array($input['channel'] ?? null, ['outlet', 'abang'], true) ? $input['channel'] : null;

    $parsed = promoParseCartLines($input['items'] ?? null);
    if (isset($parsed['error'])) {
        jsonResponse(400, $parsed['error']);
    }

    $roleName = promoResolveRoleName($conn, $decoded->role ?? null);
    $promo = promoEvaluateRequest($conn, $schema, $companyId, $roleName, $input['transaction_date'] ?? null, $parsed['lines'],
        ['channel' => $channel, 'allow_past' => promoIsManagerRole($roleName)]);

    $voucher = voucherFindByCode($conn, $schema, $code);
    if (!$voucher) {
        jsonResponse(200, 'Voucher check', voucherRejected('not_found'));
    }

    $menuInfo = promoLoadMenuInfo($conn, $schema, array_column($parsed['lines'], 'menu_id'));
    $result = voucherEvaluate($conn, $schema, $voucher, $companyId, $channel, $promo['lines'], $menuInfo, $promo['sale_date']);
    unset($result['line_discounts']);
    if ($result['valid']) {
        $result['promo_discount_amount'] = $promo['discount_amount'];
    }

    jsonResponse(200, 'Voucher check', $result);
} catch (Throwable $e) {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/voucher/check.php',
        'method'          => 'POST',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error');
}
