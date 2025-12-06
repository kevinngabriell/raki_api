<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

function getAllCompany($conn){

    $query = "SELECT company_id, company_name
        FROM app_company
        WHERE app_id = '06660e87-37e7-491b-92c3-c772130eb57c' AND company_id != 'company691b31b41ea7b'";
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
        jsonResponse(404, 'Company not found');
    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
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
        case 'POST':
            jsonResponse(500, 'Internal Server Error', ['message' => 'Under development']);
            break;
        case 'GET':
            getAllCompany($conn);
            break;
        case 'PUT':
            jsonResponse(500, 'Internal Server Error', ['message' => 'Under development']);
            break;
        case 'DELETE':
            jsonResponse(500, 'Internal Server Error', ['message' => 'Under development']);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>