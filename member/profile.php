<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function calcProfileCompletion(array $user): int {
    $fields = ['first_name', 'phone_number', 'email', 'language'];
    $filled = 0;
    foreach ($fields as $f) {
        if (!empty($user[$f]) && $user[$f] !== '-') {
            $filled++;
        }
    }
    return (int) round(($filled / count($fields)) * 100);
}

function getMemberProfile($conn, $username) {
    $username = mysqli_real_escape_string($conn, $username);

    $query = "SELECT username, first_name, phone_number, email, language
              FROM movira_core_dev.app_user
              WHERE username = '$username'
              LIMIT 1";

    $result = mysqli_query($conn, $query);

    if (!$result) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/member/profile.php',
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

    jsonResponse(200, 'Member profile', [
        'username'               => $user['username'],
        'display_name'           => $user['first_name'] ?? '',
        'phone_number'           => $user['phone_number'] ?? '',
        'email'                  => $user['email'] ?? '',
        'language'               => $user['language'] ?? '',
        'profile_completion_pct' => calcProfileCompletion($user),
        'support_wa_number'      => $_ENV['SUPPORT_WA_NUMBER'] ?? '6285121951466',
    ]);
}

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 401,
        'endpoint'        => '/member/profile.php',
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
            getMemberProfile($conn, $decoded->username);
            break;
        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/member/profile.php',
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
        'endpoint'        => '/member/profile.php',
        'method'          => '',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>
