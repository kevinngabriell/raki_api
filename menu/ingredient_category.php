<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function createIngredientCategory($conn, $input, $username){
    if (!isset($input['category_name'])) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/ingredient_category.php',
            'method'        => 'POST',
            'error_message' => 'Missing required fields (category_name)',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Missing required fields (category_name)');
    }

    $category_name = mysqli_real_escape_string($conn, $input['category_name']);
    $now = getCurrentDateTimeJakarta();

    $check_app_query = "SELECT * FROM raki_dev.ingredient_category WHERE category_name = '$category_name'";
    $check_app_result = mysqli_query($conn, $check_app_query);

    if (mysqli_num_rows($check_app_result) > 0) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/ingredient_category.php',
            'method'        => 'POST',
            'error_message' => 'Ingredients category has already exists in database !',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Ingredients category has already exists in database !!');    
    } else {
        $categoryID = "category" . uniqid();
        $now = getCurrentDateTimeJakarta();

        $category_query = "INSERT INTO raki_dev.ingredient_category (category_id, category_name, created_by, created_at) VALUES ('$categoryID', '$category_name', '$username', '$now')";

        if (mysqli_query($conn, $category_query)) {
            jsonResponse(201, 'New ingredients category has been created successfully', ['category' => $category_name]);
        } else {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/menu/ingredient_category.php',
                'method'        => 'POST',
                'error_message' => 'Failed to create a new ingredients category: ' . mysqli_error($conn),
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(500, 'Failed to create a new ingredients category: ' . mysqli_error($conn));
        }
    }
}

function getAllIngredientCategory($conn, $params, $username, $page = 1, $limit = 10){
    $offset = ($page - 1) * $limit;

    $countQuery = "SELECT COUNT(*) as total FROM raki_dev.ingredient_category WHERE (category_name LIKE '%$params%')";
    $countResult = mysqli_query($conn, $countQuery);
    $totalRow = mysqli_fetch_assoc($countResult);
    $total = $totalRow['total'];

    $query = "SELECT category_id, category_name FROM raki_dev.ingredient_category WHERE (category_name LIKE '%$params%') LIMIT $limit OFFSET $offset";
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
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/menu/ingredient_category.php',
            'method'        => 'GET',
            'error_message' => 'Category not found',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(404, 'Category not found');
    }
}

function getDetailIngredientCategory($conn, $category_id, $username){
    $query = "SELECT * FROM raki_dev.ingredient_category WHERE category_id = '$category_id'";
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
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/menu/ingredient_category.php',
            'method'        => 'GET',
            'error_message' => 'Category not found',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(404, 'Category not found');
    }
}

function updateIngredientCategory($conn, $input, $username){
    if (!isset($input['category_id']) || !isset($input['category_name'])) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/ingredient_category.php',
            'method'        => 'PUT',
            'error_message' => 'Missing required fields (category_id and category_name)',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Missing required fields (category_id and category_name)');
    }

    $category_id = mysqli_real_escape_string($conn, $input['category_id']);
    $category_name = mysqli_real_escape_string($conn, $input['category_name']);

    $query = "SELECT * FROM raki_dev.ingredient_category WHERE category_id = '$category_id'";
    $result = mysqli_query($conn, $query);

    if(mysqli_num_rows($result) > 0) {

        $updateQuery = "UPDATE raki_dev.ingredient_category SET category_name = '$category_name' WHERE category_id = '$category_id'";

        if (mysqli_query($conn, $updateQuery)) {
            jsonResponse(200, 'Ingredients category menu updated successfully', ['category_id' => $category_id]);
        } else {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/menu/ingredient_category.php',
                'method'        => 'PUT',
                'error_message' => 'Failed to update ingredients category menu : ' . mysqli_error($conn),
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(500, 'Failed to update ingredients category menu');
        }

    } else {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/menu/ingredient_category.php',
            'method'        => 'PUT',
            'error_message' => 'Ingredients category menu is not registered in systems',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(404, 'Ingredients category menu is not registered in systems');
    }
}

function deleteIngredientCategory($conn, $category_id, $username){
    if($category_id === null || $category_id === ''){
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/ingredient_category.php',
            'method'        => 'DELETE',
            'error_message' => 'Missing required fields (category_id)',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Missing required fields (category_id)');
    }

    $query = "SELECT * FROM raki_dev.ingredient_category WHERE category_id = '$category_id'";
    $result = mysqli_query($conn, $query);

    if(mysqli_num_rows($result) > 0) {
        $query = "DELETE FROM raki_dev.ingredient_category WHERE category_id = '$category_id'";

        if (mysqli_query($conn, $query)) {
            jsonResponse(200, 'Ingredient category menu deleted successfully');
        } else {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/menu/ingredient_category.php',
                'method'        => 'DELETE',
                'error_message' => 'Failed to delete ingredient category menu : ' . mysqli_error($conn),
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(500, 'Failed to delete ingredient category menu');
        }

    } else {
        jsonResponse(404, 'Ingredient category menu is not registered in systems');
    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 401,
        'endpoint'      => '/menu/ingredient_category.php',
        'method'        => 'DELETE',
        'error_message' => 'Authorization header not found',
        'user_identifier' => $username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
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
            createIngredientCategory($conn, $input, $token_username);
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $category_id = $_GET['category_id'] ?? null;
            if($category_id != null){
                getDetailIngredientCategory($conn, $category_id, $token_username);
            } else {
                getAllIngredientCategory($conn, $params, $token_username,$page, $limit);
            }
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateIngredientCategory($conn, $input, $token_username);
            break;
        case 'DELETE':
            $category_id = $_GET['category_id'] ?? null;
            deleteIngredientCategory($conn, $category_id, $token_username);
            break;
    }

} catch (Exception $e){
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/menu/ingredient_category.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>