<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once __DIR__ . '/account_rules.php';

use Firebase\JWT\JWT;

const RAKI_APP_ID = '06660e87-37e7-491b-92c3-c772130eb57c';

function login($conn, $input){
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $conn = DB::conn();

    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if ($username === '' || $password === '') {
        jsonResponse(400, 'Username and password are required');
    }

    $appId = RAKI_APP_ID;
    $stmt = $conn->prepare("SELECT user_id, username, password, company_id, app_role_id, account_status
                            FROM {$coreSchema}.app_user
                            WHERE username = ? AND app_id = ?");
    $stmt->bind_param('ss', $username, $appId);
    $stmt->execute();
    $user_result = $stmt->get_result();
    $stmt->close();

    if (!$user_result) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/login.php',
            'method'        => 'POST',
            'error_message' => 'DB query failed',
            'user_identifier' => $username,
        ]);
        jsonResponse(500, 'Internal Server Error');
    }

    if ($user_result->num_rows !== 1) {
        logApiError($conn, [
            'error_level'   => 'warning',
            'http_status'   => 401,
            'endpoint'      => '/account/login.php',
            'method'        => 'POST',
            'error_message' => 'Invalid credentials',
            'user_identifier' => $username,
        ]);
        jsonResponse(401, 'Invalid credentials');
    }

    $row = $user_result->fetch_assoc();

    $escapedPassword = mysqli_real_escape_string($conn, $password);
    if (!password_verify($escapedPassword, $row['password'])) {
        logApiError($conn, [
            'error_level'   => 'warning',
            'http_status'   => 401,
            'endpoint'      => '/account/login.php',
            'method'        => 'POST',
            'error_message' => 'Invalid credentials',
            'user_identifier' => $username,
        ]);
        jsonResponse(401, 'Invalid credentials');
    }

    $blockedMessage = accountStatusBlockMessage($row['account_status'] ?? null);
    if ($blockedMessage !== null) {
        logApiError($conn, [
            'error_level'   => 'warning',
            'http_status'   => 403,
            'endpoint'      => '/account/login.php',
            'method'        => 'POST',
            'error_message' => 'Login blocked, account_status=' . $row['account_status'],
            'user_identifier' => $username,
        ]);
        jsonResponse(403, $blockedMessage);
    }

    $issuedAt       = time();
    $expirationTime = $issuedAt + 8 * 60 * 60;

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

    RequestLogger::setActor([
        'username'   => $username,
        'company_id' => $company_id,
    ]);

    Response::success([
        'token' => $jwt,
        'expires_in' => $expirationTime,
        'user' => $payload,
    ], null, 200, 'Login successful');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
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
            logApiError($conn, [
                'error_level'   => 'warning',
                'http_status'   => 405,
                'endpoint'      => '/account/login.php',
                'method'        => $method,
                'error_message' => 'Method Not Allowed',
            ]);
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/account/login.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
