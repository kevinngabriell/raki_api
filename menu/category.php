<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

function createCategory($conn, $input, $username){
    $category_name = $input['category_name'];
    // $company_id = $input['company_id'] ?? null;
    // if ($company_id === '' || $company_id === 'null' || $company_id === 0) {
    //     $company_id = null;
    // }

    $category_name = mysqli_real_escape_string($conn, $category_name);
    // $company_id = mysqli_real_escape_string($conn, $company_id);

    $check_app_query = "SELECT * FROM raki_dev.category_menu WHERE category_name = '$category_name'";
    $check_app_result = mysqli_query($conn, $check_app_query);

    if (mysqli_num_rows($check_app_result) > 0) {
        jsonResponse(400, 'Category has already exists in database !!');    
    } else {
        $categoryID = "category" . uniqid();
        $now = getCurrentDateTimeJakarta();

        $category_query = "INSERT INTO raki_dev.category_menu (category_id, category_name, company_id, created_by, created_at) 
        VALUES ('$categoryID', '$category_name', NULL, '$username', '$now')";

        if (mysqli_query($conn, $category_query)) {
            jsonResponse(201, 'New category has been created successfully', ['category' => $category_name]);
        } else {
            jsonResponse(500, 'Failed to create a new category: ' . mysqli_error($conn));
        }
    }
}

function getAllCategory($conn, $params, $page = 1, $limit = 10){
    $offset = ($page - 1) * $limit;

    $countQuery = "SELECT COUNT(*) as total FROM raki_dev.category_menu WHERE (category_name LIKE '%$params%')";
    $countResult = mysqli_query($conn, $countQuery);
    $totalRow = mysqli_fetch_assoc($countResult);
    $total = $totalRow['total'];

    $query = "SELECT category_id, category_name FROM raki_dev.category_menu WHERE (category_name LIKE '%$params%') LIMIT $limit OFFSET $offset";
    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        $response = [
            'data' => $data,
            'pagination' => [
                'total' => (int)$total,
                'page' => (int)$page,
                'limit' => (int)$limit,
                'total_pages' => ceil($total / $limit),
            ]
        ];
        jsonResponse(200, 'Category found', $response);
    } else {
        jsonResponse(404, 'Category not found');
    }
}

function getDetailCategory($conn, $category_id){
    $query = "SELECT * FROM raki_dev.category_menu WHERE category_id = '$category_id'";
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

function updateCategory(){

}

function deleteCategory(){

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
            $input = json_decode(file_get_contents('php://input'), true);
            createCategory($conn, $input, $token_username);
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $category_id = $_GET['category_id'] ?? null;
            if($category_id != null){
                getDetailCategory($conn, $category_id);
            } else {
                getAllCategory($conn, $params, $page, $limit);
             }
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