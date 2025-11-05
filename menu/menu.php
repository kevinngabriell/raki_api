<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

header("Access-Control-Allow-Origin: *");
header("Vary: Origin");
header("Content-Type: application/json");

// Early CORS preflight handler (must run before auth checks)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, Content-Type");
    header("Access-Control-Allow-Credentials: true");
    http_response_code(204);
    exit();
}

function createMenu($conn, $input, $username){
    // Accept JSON body and multipart/form-data
    $menu_name   = $input['menu_name']   ?? ($_POST['menu_name']   ?? null);
    $category_id = $input['category_id'] ?? ($_POST['category_id'] ?? null);
    $price       = $input['price']       ?? ($_POST['price']       ?? null);
    if ($menu_name === null || $category_id === null) {
        jsonResponse(400, 'menu_name and category_id are required');
    }

    $menu_name = mysqli_real_escape_string($conn, $menu_name);
    $category_id = mysqli_real_escape_string($conn, $category_id);
    $price = mysqli_real_escape_string($conn, $price);

    // Normalize price for SQL
    if ($price === null || $price === '') {
        $price_sql = "NULL";
    } else {
        if (!is_numeric($price)) {
            jsonResponse(400, 'price must be numeric');
        }
        $price_sql = (string)(0 + $price); // numeric literal
    }

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

        $img_sql   = $image_url ? "'" . mysqli_real_escape_string($conn, $image_url) . "'" : "NULL";
        $thumb_sql = $thumb_url ? "'" . mysqli_real_escape_string($conn, $thumb_url) . "'" : "NULL";
        $menu_query = "
            INSERT INTO raki_dev.menu
                (menu_id, menu_name, category_id, price, image_url, thumb_url, created_by)
            VALUES
                ('$menuID', '$menu_name', '$category_id', $price_sql, $img_sql, $thumb_sql, '$username')
        ";

        if (mysqli_query($conn, $menu_query)) {
            jsonResponse(201, 'New menu has been created successfully', ['menu' => $menu_name]);
        } else {
            jsonResponse(500, 'Failed to create a new menu: ' . mysqli_error($conn));
        }
    }

}

function getAllMenu($conn, $params, $page = 1, $limit = 10){
    $offset = ($page - 1) * $limit;

    $params = mysqli_real_escape_string($conn, $params ?? '');

    $countQuery = "SELECT COUNT(*) as total FROM raki_dev.menu WHERE (menu_name LIKE '%$params%')";
    $countResult = mysqli_query($conn, $countQuery);
    $totalRow = mysqli_fetch_assoc($countResult);
    $total = $totalRow['total'];

    $query = "SELECT menu_id, menu_name, price, category_name, image_url, thumb_url
              FROM raki_dev.menu ME
              LEFT JOIN raki_dev.category_menu CM ON ME.category_id = CM.category_id 
              WHERE (menu_name LIKE '%$params%') LIMIT $limit OFFSET $offset";
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

function getDetailMenu($conn, $menu_id){
    $query = "SELECT menu_id, menu_name, price, category_name, image_url, thumb_url, ME.company_id, is_active, ME.created_at, ME.created_by, ME.updated_at, ME.updated_by
    FROM raki_dev.menu ME
    LEFT JOIN raki_dev.category_menu CM ON ME.category_id = CM.category_id WHERE  ME.menu_id = '$menu_id'";
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
        jsonResponse(200, 'Menu found', $response);
    } else {
        jsonResponse(404, 'Menu not found');
    }
}

function updateMenu(){

}

function deleteMenu(){
    
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);
if (!$authHeader) {
    jsonResponse(401, 'Authorization header not found');
}

try {
    $token = preg_replace('/^Bearer\s+/i', '', $authHeader);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn = DB::conn();

    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'POST':
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
            } else {
                // For form-data/x-www-form-urlencoded use $_POST
                $input = $_POST ?? [];
            }
            createMenu($conn, $input, $token_username);
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $menu_id = $_GET['menu_id'] ?? null;
            if($menu_id != null){
                getDetailMenu($conn, $menu_id);
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