<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

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
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($user_result) !== 1) {
        jsonResponse(401, 'Invalid credentials');
    }

    $row = mysqli_fetch_assoc($user_result);

    // verifikasi password
    if (!password_verify($password, $row['password'])) {
        jsonResponse(401, 'Invalid credentials');
    }

    $issuedAt       = time();
    $expirationTime = $issuedAt + 3 * 60 * 60; // 3 jam

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

    switch($method){
        case 'POST':   
            $input = json_decode(file_get_contents('php://input'), true);
            login($conn, $input);
            break;
        case 'GET':
            jsonResponse(500, 'Internal Server Error', ['message' => 'Under development']);
            break;
        case 'PUT':
            jsonResponse(500, 'Internal Server Error', ['message' => 'Under development']);
            break;
        case 'DELETE':
            jsonResponse(500, 'Internal Server Error', ['message' => 'Under development']);
            break;
    }

} catch (Exception $e){
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>