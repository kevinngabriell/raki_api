<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../log.php';

function register($conn, $input){
    $conn = DB::conn();
    $username = $input['username'];
    $password = $input['password'];
    $app_id = $input['app_id'];
    $app_role_id = $input['app_role_id'];

    $username = mysqli_real_escape_string($conn, $username);
    $password = mysqli_real_escape_string($conn, $password);
    $app_id = mysqli_real_escape_string($conn, $app_id);
    $app_role_id = mysqli_real_escape_string($conn, $app_role_id);

    $checkAppQuery = "SELECT * FROM movira_core_dev.app WHERE app_id = '$app_id'";
    $checkAppResult = mysqli_query($conn, $checkAppQuery);

    if (mysqli_num_rows($checkAppResult) === 0) {
        return ['success' => false, 'message' => 'App ID tidak valid.'];
    }

    $checkAppRoleQuery = "SELECT * FROM movira_core_dev.app_role WHERE app_role_id = '$app_role_id'";
    $checkAppRoleResult = mysqli_query($conn, $checkAppRoleQuery);
    
    if (mysqli_num_rows($checkAppRoleResult) === 0) {
        return ['success' => false, 'message' => 'App role ID tidak valid.'];
    }

    // Cek apakah username sudah ada
    $checkUserQuery = "SELECT user_id FROM movira_core_dev.app_user WHERE username = '$username'";
    $checkUserResult = mysqli_query($conn, $checkUserQuery);
    if (mysqli_num_rows($checkUserResult) > 0) {
        return ['success' => false, 'message' => 'Username sudah digunakan.'];
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $userID = "user" . uniqid();

    $insertQuery = "INSERT INTO movira_core_dev.app_user (user_id, username, password, app_id, app_role_id, created_at) VALUES ('$userID','$username', '$hashedPassword', '$app_id', '$app_role_id', NOW())";

    if (mysqli_query($conn, $insertQuery)) {
        $userId = mysqli_insert_id($conn);
        jsonResponse(201, 'New user has been created successfully', ['username' => $userId]);
    } else {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/register.php',
            'method'        => 'POST',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to create a new user: ' . mysqli_error($conn));
    }
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
            register($conn, $input);   
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/account/register.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}


?>