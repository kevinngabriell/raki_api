<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

use Firebase\JWT\JWT;

function login($conn, $input){
    $conn = DB::conn();
    $username = $input['username'];
    $password = $input['password'];

    $username = mysqli_real_escape_string($conn, $username);
    $password = mysqli_real_escape_string($conn, $password);

    $user_query = "SELECT * FROM movira_core_dev.app_user WHERE username = '$username'";
    $user_result = mysqli_query($conn, $user_query);

    if (!$user_result) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/login.php',
            'method'        => 'POST',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($user_result) !== 1) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 401,
            'endpoint'      => '/account/login.php',
            'method'        => 'POST',
            'error_message' => 'Invalid credentials',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(401, 'Invalid credentials');
    }

    $row = mysqli_fetch_assoc($user_result);

    // verifikasi password
    if (!password_verify($password, $row['password'])) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 401,
            'endpoint'      => '/account/login.php',
            'method'        => 'POST',
            'error_message' => 'Invalid credentials',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(401, 'Invalid credentials');
    }

    $issuedAt       = time();
    $expirationTime = $issuedAt + 30 * 24 * 60 * 60; // 30 hari

    $company_id = $row['company_id'];
    $role = $row['app_role_id'];

    $payload = [
        'iat' => $issuedAt,
        'exp' => $expirationTime,
        'username' => $username,
        'company_id' => $company_id,
        'role' => $role
    ];

    $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');

    http_response_code(200); // OK
    echo json_encode([
        'status_code' => 200,
        'status_message' => 'Login Success',
        'token' => $jwt,
        'expires_in' => $expirationTime,
        'data' => $payload
    ]);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");
    http_response_code(200);
    exit();
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn = DB::conn();

    switch($method){
        case 'POST':   
            $input = json_decode(file_get_contents('php://input'), true);
            login($conn, $input);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/account/login.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>