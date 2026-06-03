<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function getPointsHistory($conn, $schema, $username, $company_id, array $params) {
    $username   = mysqli_real_escape_string($conn, $username);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $userResult = mysqli_query($conn,
        "SELECT user_id FROM movira_core_dev.app_user
         WHERE username = '$username' LIMIT 1"
    );

    if (!$userResult || mysqli_num_rows($userResult) === 0) {
        jsonResponse(404, 'User not found');
    }

    $user_id = mysqli_fetch_assoc($userResult)['user_id'];
    $user_id = mysqli_real_escape_string($conn, $user_id);

    $page   = isset($params['page'])  ? max(1, (int)$params['page'])  : 1;
    $limit  = isset($params['limit']) ? min(100, max(1, (int)$params['limit'])) : 20;
    $offset = ($page - 1) * $limit;

    $where = "WHERE user_id = '$user_id' AND company_id = '$company_id'";

    // optional filter by type: earn | redeem | adjustment
    if (!empty($params['type'])) {
        $type    = mysqli_real_escape_string($conn, $params['type']);
        $where  .= " AND type = '$type'";
    }

    $countResult = mysqli_query($conn,
        "SELECT COUNT(*) AS total FROM {$schema}.member_point_ledger $where"
    );
    $total = (int) mysqli_fetch_assoc($countResult)['total'];

    $rows = mysqli_query($conn,
        "SELECT ledger_id   AS id,
                notes       AS description,
                points,
                type,
                created_at
         FROM {$schema}.member_point_ledger
         $where
         ORDER BY created_at DESC
         LIMIT $limit OFFSET $offset"
    );

    $data = [];
    while ($row = mysqli_fetch_assoc($rows)) {
        $row['points'] = (int) $row['points'];
        $data[] = $row;
    }

    jsonResponse(200, 'Points history', [
        'data' => $data,
        'pagination' => [
            'current_page' => $page,
            'limit'        => $limit,
            'total_data'   => $total,
            'total_page'   => (int) ceil($total / $limit),
        ],
    ]);
}

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 401,
        'endpoint'        => '/member/points-history.php',
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
            getPointsHistory($conn, $schema, $decoded->username, $decoded->company_id, $_GET);
            break;
        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/member/points-history.php',
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
        'endpoint'        => '/member/points-history.php',
        'method'          => '',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>
