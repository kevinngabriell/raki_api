<?php
// POST /promo/check.php
//
// Stateless preview for the POS: given the cart as it stands, says whether a
// promo applies, how much it takes off, how the discount lands on each line and
// what is missing to unlock more (so the cashier sees the discount the moment the
// second eligible drink is added). Nothing is written.
//
// It runs the same evaluator as POST /transaction/index.php with apply_promo, so
// the preview and the saved sale cannot disagree.

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once 'engine.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, 'Method Not Allowed');
}

$decoded = promoAuthenticate();
$conn = null;

try {
    $conn = DB::conn();
    $schema = DB_SCHEMA;

    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
        jsonResponse(400, 'Invalid JSON body');
    }

    // Same resolution order as createTransaction(): payload first, then the token.
    $companyId = $input['company_id'] ?? $decoded->company_id ?? null;
    if (!is_string($companyId) || $companyId === '') {
        jsonResponse(400, 'company_id is required (not found in payload or token).');
    }

    $parsed = promoParseCartLines($input['items'] ?? null);
    if (isset($parsed['error'])) {
        jsonResponse(400, $parsed['error']);
    }

    $roleName = promoResolveRoleName($conn, $decoded->role ?? null);
    $result = promoEvaluateRequest($conn, $schema, $companyId, $roleName, $input['transaction_date'] ?? null, $parsed['lines']);

    jsonResponse(200, 'Promo check', $result);
} catch (Throwable $e) {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/promo/check.php',
        'method'          => 'POST',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
