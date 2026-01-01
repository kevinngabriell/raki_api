<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function showStaticQRIS($conn, $company_id){

    $countQuery = "SELECT COUNT(*) as total FROM movira_core_dev.app_static_payment WHERE company_id = '$company_id'";
    $countResult = mysqli_query($conn, $countQuery);
    $totalRow = mysqli_fetch_assoc($countResult);
    $total = $totalRow['total'];

    $query = "SELECT * FROM movira_core_dev.app_static_payment WHERE company_id = '$company_id'";
    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        // $response = [
        //     'data' => $data,
        //     'pagination' => [
        //         'total' => (int)$total,
        //         'page' => 1,
        //         'limit' => 1,
        //         'total_pages' => 1,
        //     ]
        // ];
        jsonResponse(200, 'Static QRIS found', $data);
    } else {
        jsonResponse(404, 'Static QRIS not found');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");
    http_response_code(200);
    exit();
}

$headers = function_exists('getallheaders') ? getallheaders() : [];

// Case-insensitive Authorization lookup (some servers return 'authorization')
$authHeader = null;
foreach ($headers as $k => $v) {
    if (strtolower($k) === 'authorization') {
        $authHeader = $v;
        break;
    }
}

// Fallbacks for different SAPIs / proxies
if (!$authHeader) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
}
if (!$authHeader) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
}

if (!$authHeader) {
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
    $token = preg_replace('/^Bearer\s+/i', '', trim($authHeader));
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn = DB::conn();

    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'GET':
            // company_id now comes from query param (?company_id=...)
            $company_id = $_GET['company_id'] ?? null;

            if (!$company_id || trim($company_id) === '') {
                jsonResponse(400, 'company_id query parameter is required');
            }

            showStaticQRIS($conn, $company_id);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }

} catch (Exception $e){
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>