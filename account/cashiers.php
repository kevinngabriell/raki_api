<?php
// GET /account/cashiers.php?company_id
// The accounts of an outlet that make sales: outlet kasir and abang (for the Kasir filter on Transaksi).
// -> [{ username, full_name, kind: "outlet"|"abang", account_status }]
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

try {
    $decoded = requireAuth();
    $conn = DB::conn();
    requireManager($decoded);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(405, 'Method Not Allowed');
    $companyId = requireCompanyAccess($conn, $decoded, $_GET['company_id'] ?? null);

    $core = MOVIRA_CORE_SCHEMA;
    $app = ACCOUNT_RAKI_APP_ID;
    $outletRole = 'app_role6a2d1c8e22260';
    $abangRole = ACCESS_ROLE_ABANG;
    $stmt = $conn->prepare("SELECT username, first_name, app_role_id, account_status FROM {$core}.app_user
        WHERE company_id = ? AND app_id = ? AND app_role_id IN (?, ?) ORDER BY first_name, username");
    $stmt->bind_param('ssss', $companyId, $app, $outletRole, $abangRole);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result() as $u) {
        $out[] = ['username' => $u['username'], 'full_name' => $u['first_name'] ?: $u['username'],
                  'kind' => $u['app_role_id'] === $abangRole ? 'abang' : 'outlet', 'account_status' => $u['account_status']];
    }
    $stmt->close();
    jsonResponse(200, 'Cashiers fetched', $out);
} catch (Throwable $e) {
    logApiError(DB::conn(), ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/account/cashiers.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
