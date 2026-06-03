<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function getMemberCard($conn, $schema, $username, $company_id) {
    $username   = mysqli_real_escape_string($conn, $username);
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $today      = date('Y-m-d');

    // 1. Resolve user_id + display_name + valid_until (1 year from account creation)
    $userResult = mysqli_query($conn,
        "SELECT user_id, first_name,
                DATE_ADD(created_at, INTERVAL 1 YEAR) AS valid_until
         FROM movira_core_dev.app_user
         WHERE username = '$username'
         LIMIT 1"
    );

    if (!$userResult || mysqli_num_rows($userResult) === 0) {
        jsonResponse(404, 'User not found');
    }

    $user       = mysqli_fetch_assoc($userResult);
    $user_id    = $user['user_id'];
    $valid_until = $user['valid_until']; // e.g. "2026-03-15"

    // 2. Current points balance
    $balResult = mysqli_query($conn,
        "SELECT COALESCE(SUM(points), 0) AS balance
         FROM {$schema}.member_point_ledger
         WHERE user_id = '$user_id' AND company_id = '$company_id'"
    );
    $balance = (int) mysqli_fetch_assoc($balResult)['balance'];

    // 3. Points earned yesterday (positive entries only)
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $deltaResult = mysqli_query($conn,
        "SELECT COALESCE(SUM(points), 0) AS delta
         FROM {$schema}.member_point_ledger
         WHERE user_id = '$user_id'
           AND company_id = '$company_id'
           AND points > 0
           AND DATE(created_at) = '$yesterday'"
    );
    $recent_delta = (int) mysqli_fetch_assoc($deltaResult)['delta'];

    // 4. Redeemable catalog items the user can currently afford
    $redeemResult = mysqli_query($conn,
        "SELECT COUNT(*) AS cnt
         FROM {$schema}.reward_catalog
         WHERE company_id = '$company_id'
           AND is_active = 1
           AND point_cost <= $balance
           AND (valid_from IS NULL  OR valid_from  <= '$today')
           AND (valid_until IS NULL OR valid_until >= '$today')
           AND (stock IS NULL OR stock > 0)"
    );
    $redeemable_count = (int) mysqli_fetch_assoc($redeemResult)['cnt'];

    jsonResponse(200, 'Member card', [
        'member_id'            => $user_id,
        'display_name'         => $user['first_name'] ?? '',
        'points'               => $balance,
        'valid_until'          => $valid_until,
        'recent_points_delta'  => $recent_delta,
        'redeemable_count'     => $redeemable_count,
    ]);
}

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 401,
        'endpoint'        => '/member/card.php',
        'method'          => '',
        'error_message'   => 'Authorization header not found',
        'user_identifier' => null,
        'company_id'      => null,
    ]);
    jsonResponse(401, 'Authorization header not found');
}

try {
    $token   = str_replace('Bearer ', '', $headers['Authorization']);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

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
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>
