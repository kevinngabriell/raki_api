<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function getUserProfile($conn, $username) {
    $username = mysqli_real_escape_string($conn, $username);

    $query = "SELECT user_id, username, account_status, app_id, app_role_id, company_id,
                     phone_number, first_name, language, email, created_at, updated_at
              FROM movira_core_dev.app_user
              WHERE username = '$username'
              LIMIT 1";

    $result = mysqli_query($conn, $query);

    if (!$result) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/account/profile.php',
            'method'          => 'GET',
            'error_message'   => mysqli_error($conn),
            'user_identifier' => $username,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'User not found');
    }

    $user = mysqli_fetch_assoc($result);

    jsonResponse(200, 'User profile', $user);
}

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 401,
        'endpoint'        => '/account/profile.php',
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
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            getUserProfile($conn, $decoded->username);
            break;
        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/account/profile.php',
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
        'endpoint'        => '/account/profile.php',
        'method'          => '',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>
