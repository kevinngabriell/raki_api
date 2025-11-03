<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

function createMenu($conn, $input, $username){
    $menu_name = $input['menu_name'];
    $category_id = $input['category_id'];
    $price = $input['price'];

    $menu_name = mysqli_real_escape_string($conn, $menu_name);
    $category_id = mysqli_real_escape_string($conn, $category_id);
    $price = mysqli_real_escape_string($conn, $price);

    $check_app_query = "SELECT * FROM raki_dev.menu WHERE menu_name = '$menu_name'";
    $check_app_result = mysqli_query($conn, $check_app_query);

    if (mysqli_num_rows($check_app_result) > 0) {
        jsonResponse(400, 'Menu has already exists in database !!');    
    } else {
        $menuID = "menu" . uniqid();
        $now = getCurrentDateTimeJakarta();

        // Handle upload (optional)
        $image_url = null;
        $thumb_url = null;
        if (!empty($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            // Uses helper from general.php
            [$image_url, $thumb_url] = handle_menu_image_upload($_FILES['image'], 'menu');
        }

        $menu_query = "INSERT INTO raki_dev.menu (menu_id, menu_name, category_id, price, image_url, thumb_url, created_by) 
        VALUES ('$menuID', '$menu_name', '$category_id', '$price', " . 
        ($image_url ? "'" . mysqli_real_escape_string($conn, $image_url) . "'" : "NULL") . ", " .
        ($thumb_url ? "'" . mysqli_real_escape_string($conn, $thumb_url) . "'" : "NULL") . ", '$username')";

        if (mysqli_query($conn, $menu_query)) {
            jsonResponse(201, 'New menu has been created successfully', ['menu' => $menu_name]);
        } else {
            jsonResponse(500, 'Failed to create a new menu: ' . mysqli_error($conn));
        }
    }

}

function getAllMenu($conn, $params, $page = 1, $limit = 10){
    $offset = ($page - 1) * $limit;

    $countQuery = "SELECT COUNT(*) as total FROM raki_dev.menu WHERE (menu_name LIKE '%$params%')";
    $countResult = mysqli_query($conn, $countQuery);
    $totalRow = mysqli_fetch_assoc($countResult);
    $total = $totalRow['total'];

    $query = "SELECT menu_id, menu_name, price FROM raki_dev.menu WHERE (menu_name LIKE '%$params%') LIMIT $limit OFFSET $offset";
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
        jsonResponse(200, 'Menu found', $response);
    } else {
        jsonResponse(404, 'Menu not found');
    }
}

function getDetailMenu(){

}

function updateMenu(){

}

function deleteMenu(){
    
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
            createMenu($conn, $input, $token_username);
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $menu_id = $_GET['menu_id'] ?? null;
            if($menu_id != null){
                // getDetailCategory($conn, $category_id);
            } else {
                getAllMenu($conn, $params, $page, $limit);
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