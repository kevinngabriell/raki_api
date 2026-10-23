<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function getMemberCard($conn, $schema, $username, $company_id) {
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $today = date('Y-m-d');

    // 1. Resolve user_id + display_name + valid_until (1 year from account creation)
    $stmtUser = $conn->prepare("SELECT user_id, first_name,
                DATE_ADD(created_at, INTERVAL 1 YEAR) AS valid_until
         FROM {$coreSchema}.app_user
         WHERE username = ?
         LIMIT 1");

    if (!$stmtUser) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/member/card.php',
            'method'          => 'GET',
            'error_message'   => 'Failed to prepare statement for user lookup',
            'user_identifier' => $username,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Failed to prepare statement');
    }

    $stmtUser->bind_param('s', $username);
    $stmtUser->execute();
    $userResult = $stmtUser->get_result();

    if (!$userResult || $userResult->num_rows === 0) {
        jsonResponse(404, 'User not found');
    }

    $user        = $userResult->fetch_assoc();
    $user_id     = $user['user_id'];
    $valid_until = $user['valid_until'];
    $stmtUser->close();

    // 2. Current points balance
    $stmtBal = $conn->prepare("SELECT COALESCE(SUM(points), 0) AS balance
         FROM {$schema}.member_point_ledger
         WHERE user_id = ? AND company_id = ?");

    if (!$stmtBal) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/member/card.php',
            'method'          => 'GET',
            'error_message'   => 'Failed to prepare statement for points balance',
            'user_identifier' => $username,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Failed to prepare statement');
    }

    $stmtBal->bind_param('ss', $user_id, $company_id);
    $stmtBal->execute();
    $balance = (int) $stmtBal->get_result()->fetch_assoc()['balance'];
    $stmtBal->close();

    // 3. Points earned yesterday (positive entries only)
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmtDelta = $conn->prepare("SELECT COALESCE(SUM(points), 0) AS delta
         FROM {$schema}.member_point_ledger
         WHERE user_id = ?
           AND company_id = ?
           AND points > 0
           AND DATE(created_at) = ?");

    if (!$stmtDelta) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/member/card.php',
            'method'          => 'GET',
            'error_message'   => 'Failed to prepare statement for points delta',
            'user_identifier' => $username,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Failed to prepare statement');
    }

    $stmtDelta->bind_param('sss', $user_id, $company_id, $yesterday);
    $stmtDelta->execute();
    $recent_delta = (int) $stmtDelta->get_result()->fetch_assoc()['delta'];
    $stmtDelta->close();

    // 4. Redeemable catalog items the user can currently afford
    $stmtRedeem = $conn->prepare("SELECT COUNT(*) AS cnt
         FROM {$schema}.reward_catalog
         WHERE company_id = ?
           AND is_active = 1
           AND point_cost <= ?
           AND (valid_from IS NULL  OR valid_from  <= ?)
           AND (valid_until IS NULL OR valid_until >= ?)
           AND (stock IS NULL OR stock > 0)");

    if (!$stmtRedeem) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/member/card.php',
            'method'          => 'GET',
            'error_message'   => 'Failed to prepare statement for redeemable count',
            'user_identifier' => $username,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Failed to prepare statement');
    }

    $stmtRedeem->bind_param('siss', $company_id, $balance, $today, $today);
    $stmtRedeem->execute();
    $redeemable_count = (int) $stmtRedeem->get_result()->fetch_assoc()['cnt'];
    $stmtRedeem->close();

    jsonResponse(200, 'Member card', [
        'member_id'            => $user_id,
        'display_name'         => $user['first_name'] ?? '',
        'points'               => $balance,
        'valid_until'          => $valid_until,
        'recent_points_delta'  => $recent_delta,
        'redeemable_count'     => $redeemable_count,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $decoded = requireAuth();

    $conn   = DB::conn();
    $schema = DB_SCHEMA;
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            getMemberCard($conn, $schema, $decoded->username, $decoded->company_id);
            break;
        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/member/card.php',
                'method'          => $method,
                'error_message'   => 'Method Not Allowed',
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e) {
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/member/card.php',
        'method'          => '',
        'error_message'   => $e->getMessage(),
        'user_identifier' => null,
        'company_id'      => null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
