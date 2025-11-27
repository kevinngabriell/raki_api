<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

function getCompanyDetails ($conn, $company_id){
    $query = "SELECT * FROM app_company WHERE company_id = '$company_id'";
    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        $response = [
            'data' => $data,
            'pagination' => [
                'total' => 1,
                'page' => 1,
                'limit' => 1,
                'total_pages' => 1,
            ]
        ];
        jsonResponse(200, 'Category found', $response);
    } else {
        jsonResponse(404, 'Category not found');
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
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");
    http_response_code(200);
    exit();
}

try {
    $token = str_replace('Bearer ', '', $headers['Authorization']);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn = DB::conn();
    
    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'POST':
            break;
        case 'GET':
            $company_id = $_GET['company_id'];
            getCompanyDetails($conn, $company_id);
            break;
        case 'PUT':
            break;
        case 'DELETE':
            break;
    }

} catch (Exception $e){
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>