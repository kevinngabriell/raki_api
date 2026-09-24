<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once __DIR__ . '/account_rules.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// Owner review of self-registered accounts (account/register.php creates them pending).
//   GET   ?page=1&limit=20                        list pending sign-ups
//   PATCH { user_id, action: approve, app_role_id, company_id? }
//   PATCH { user_id, action: reject, reason? }

function getPendingAccounts($conn, $page, $limit){
    $page  = max(1, (int)$page);
    $limit = min(100, max(1, (int)$limit));
    $offset = ($page - 1) * $limit;

    $appId  = ACCOUNT_RAKI_APP_ID;
    $status = ACCOUNT_STATUS_PENDING;

    $stmtCount = $conn->prepare("SELECT COUNT(*) AS total FROM movira_core_dev.app_user WHERE app_id = ? AND account_status = ?");
    $stmtCount->bind_param('ss', $appId, $status);
    $stmtCount->execute();
    $total = (int)$stmtCount->get_result()->fetch_assoc()['total'];

    // Oldest first: whoever has been waiting longest gets reviewed first.
    $stmt = $conn->prepare("SELECT user_id, username, first_name AS full_name, phone_number, email, created_at FROM movira_core_dev.app_user WHERE app_id = ? AND account_status = ? ORDER BY created_at ASC, user_id ASC LIMIT ?, ?");
    $stmt->bind_param('ssii', $appId, $status, $offset, $limit);
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    jsonResponse(200, 'Success', [
        'users' => $users,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

function reviewPendingAccount($conn, $input, $decoded){
    $userId = trim((string)($input['user_id'] ?? ''));
    $action = strtolower(trim((string)($input['action'] ?? '')));

    if ($userId === '') {
        jsonResponse(400, 'user_id wajib diisi.');
    }
    if (!in_array($action, ['approve', 'reject'], true)) {
        jsonResponse(400, "action harus 'approve' atau 'reject'.");
    }

    $appId = ACCOUNT_RAKI_APP_ID;
    $stmtUser = $conn->prepare("SELECT user_id, username, account_status FROM movira_core_dev.app_user WHERE user_id = ? AND app_id = ? LIMIT 1");
    $stmtUser->bind_param('ss', $userId, $appId);
    $stmtUser->execute();
    $user = $stmtUser->get_result()->fetch_assoc();

    if (!$user) {
        jsonResponse(404, 'Akun tidak ditemukan.');
    }
    if (strtolower((string)$user['account_status']) !== ACCOUNT_STATUS_PENDING) {
        jsonResponse(409, 'Akun ini tidak sedang menunggu persetujuan.');
    }

    if ($action === 'reject') {
        $newStatus = ACCOUNT_STATUS_REJECTED;
        $pending = ACCOUNT_STATUS_PENDING;
        $stmt = $conn->prepare("UPDATE movira_core_dev.app_user SET account_status = ?, updated_at = NOW() WHERE user_id = ? AND account_status = ?");
        $stmt->bind_param('sss', $newStatus, $userId, $pending);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            jsonResponse(409, 'Akun ini tidak sedang menunggu persetujuan.');
        }

        // There is no column for the reason yet; it is only echoed back.
        jsonResponse(200, 'Pendaftaran ditolak', [
            'user_id'        => $userId,
            'username'       => $user['username'],
            'account_status' => $newStatus,
            'reason'         => isset($input['reason']) ? trim((string)$input['reason']) : null,
        ]);
    }

    // approve
    $ownerCompanyId = $decoded->company_id ?? null;
    if (!$ownerCompanyId) {
        jsonResponse(400, 'Akun owner tidak terhubung ke perusahaan.');
    }
    $companyId = trim((string)($input['company_id'] ?? '')) ?: $ownerCompanyId;
    if ($companyId !== $ownerCompanyId) {
        jsonResponse(403, 'Hanya bisa menyetujui akun untuk perusahaan sendiri.');
    }

    $appRoleId = trim((string)($input['app_role_id'] ?? ''));
    if ($appRoleId === '') {
        jsonResponse(400, 'app_role_id wajib diisi.');
    }
    $roleName = accountRoleName($conn, $appRoleId);
    if ($roleName === null) {
        jsonResponse(400, 'app_role_id tidak valid.');
    }
    if (!accountIsGrantableRole($roleName)) {
        jsonResponse(403, "Role $roleName tidak bisa diberikan lewat persetujuan akun.");
    }

    $newStatus = ACCOUNT_STATUS_ACTIVE;
    $pending = ACCOUNT_STATUS_PENDING;
    $stmt = $conn->prepare("UPDATE movira_core_dev.app_user SET account_status = ?, app_role_id = ?, company_id = ?, updated_at = NOW() WHERE user_id = ? AND account_status = ?");
    $stmt->bind_param('sssss', $newStatus, $appRoleId, $companyId, $userId, $pending);
    $stmt->execute();
    if ($stmt->affected_rows !== 1) {
        jsonResponse(409, 'Akun ini tidak sedang menunggu persetujuan.');
    }

    jsonResponse(200, 'Akun disetujui', [
        'user_id'        => $userId,
        'username'       => $user['username'],
        'account_status' => $newStatus,
        'app_role_id'    => $appRoleId,
        'role_name'      => $roleName,
        'company_id'     => $companyId,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, PATCH, OPTIONS");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
    http_response_code(200);
    exit();
}

$headers = function_exists('getallheaders') ? getallheaders() : [];

// Case-insensitive Authorization lookup (some servers return 'authorization')
$authHeader = null;
foreach ($headers as $k => $v) {
    if (strtolower($k) === 'authorization') {
        $authHeader = $v;
        break;
    }
}
if (!$authHeader) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
}
if (!$authHeader) {
    jsonResponse(401, 'Authorization header not found');
}

try {
    $token = preg_replace('/^Bearer\s+/i', '', trim($authHeader));
    try {
        $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
    } catch (Exception $e) {
        jsonResponse(401, 'Invalid or expired token', ['error' => $e->getMessage()]);
    }

    $conn = DB::conn();

    if (accountRoleName($conn, $decoded->role ?? null) !== 'Owner') {
        jsonResponse(403, 'Hanya Owner yang bisa meninjau pendaftaran akun.');
    }

    $method = $_SERVER['REQUEST_METHOD'];
    switch ($method) {
        case 'GET':
            getPendingAccounts($conn, $_GET['page'] ?? 1, $_GET['limit'] ?? 20);
            break;

        case 'PATCH':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) {
                jsonResponse(400, 'Invalid JSON body');
            }
            reviewPendingAccount($conn, $input, $decoded);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }

} catch (Exception $e) {
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/account/pending.php',
        'method'        => $_SERVER['REQUEST_METHOD'] ?? null,
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
