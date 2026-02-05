<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function getAllCompany($conn){

    $query = "SELECT company_id, company_name FROM app_company WHERE app_id = '06660e87-37e7-491b-92c3-c772130eb57c' AND company_id != 'company691b31b41ea7b'";
    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        $response = [
            'data' => $data,
            // 'pagination' => [
            //     'total' => (int)$total,
            //     'page' => (int)$page,
            //     'limit' => (int)$limit,
            //     'total_pages' => ceil($total / $limit),
            // ]
        ];
        jsonResponse(200, 'Company found', $response);
    } else {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/dashoard/company.php',
            'method'        => 'GET',
            'error_message' => 'Company not found',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(404, 'Company not found');
    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 401,
        'endpoint'      => '/dashoard/company.php',
        'method'        => 'GET',
        'error_message' => 'Authorization header not found',
        'user_identifier' => $username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(401, 'Authorization header not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
    http_response_code(200);
    exit();
}

try {
    $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
    if (!$token) {
        jsonResponse(401, 'Token not provided');
    }

    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn = DB::conn();

    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'GET':
            getAllCompany($conn);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/dashboard/company.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>