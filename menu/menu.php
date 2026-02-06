<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

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
    $menu_name   = $input['menu_name']   ?? ($_POST['menu_name']   ?? null);
    $category_id = $input['category_id'] ?? ($_POST['category_id'] ?? null);
    $price       = $input['price']       ?? ($_POST['price']       ?? null);

    if ($menu_name === null || $category_id === null) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/menu.php',
            'method'        => 'POST',
            'error_message' => 'menu_name and category_id are required',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
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
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 400,
                'endpoint'      => '/menu/menu.php',
                'method'        => 'POST',
                'error_message' => 'price must be numeric',
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(400, 'price must be numeric');
        }
        $price_sql = (string)(0 + $price); // numeric literal
    }

    $check_app_query = "SELECT * FROM raki_dev.menu WHERE menu_name = '$menu_name'";
    $check_app_result = mysqli_query($conn, $check_app_query);

    if (mysqli_num_rows($check_app_result) > 0) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/menu.php',
            'method'        => 'POST',
            'error_message' => 'Menu has already exists in database !!',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Menu has already exists in database !!');    
    } else {
        $menuID = "menu" . uniqid();
        $now = getCurrentDateTimeJakarta();

        // Handle upload (optional)
        $image_url = null;
        $thumb_url = null;

        error_log("CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'N/A'));
        error_log("FILES: " . print_r($_FILES, true));
        error_log("POST: " . print_r($_POST, true));

        if (!empty($_FILES['image'])) {
            if ($_FILES['image']['error'] === UPLOAD_ERR_OK) {
                [$image_url, $thumb_url] = handle_menu_image_upload($_FILES['image'], 'menu');
            } else {
                logApiError($conn, [
                    'error_level'   => 'error',
                    'http_status'   => 400,
                    'endpoint'      => '/menu/menu.php',
                    'method'        => 'POST',
                    'error_message' => 'Upload error code: ' . $_FILES['image']['error'],
                    'user_identifier' => $username ?? null,
                    'company_id'      => $decoded->company_id ?? null,
                ]);
                jsonResponse(400, 'Upload error code: ' . $_FILES['image']['error']);
            }
        }

        $img_sql   = $image_url ? "'" . mysqli_real_escape_string($conn, $image_url) . "'" : "NULL";
        $thumb_sql = $thumb_url ? "'" . mysqli_real_escape_string($conn, $thumb_url) . "'" : "NULL";

        $menu_query = "INSERT INTO raki_dev.menu (menu_id, menu_name, category_id, price, image_url, thumb_url, created_by) VALUES ('$menuID', '$menu_name', '$category_id', $price_sql, $img_sql, $thumb_sql, '$username')";

        if (mysqli_query($conn, $menu_query)) {
            jsonResponse(201, 'New menu has been created successfully', ['menu' => $menu_name]);
        } else {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/menu/menu.php',
                'method'        => 'POST',
                'error_message' => 'Failed to create a new menu: ' . mysqli_error($conn),
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(500, 'Failed to create a new menu: ' . mysqli_error($conn));
        }
    }
}

function getAllMenu($conn, $params, $page = 1, $limit = 50){
    $offset = ($page - 1) * $limit;

    $params = mysqli_real_escape_string($conn, $params ?? '');

    $countQuery = "SELECT COUNT(*) as total FROM raki_dev.menu WHERE is_active = 1 AND menu_name LIKE '%$params%'";
    $countResult = mysqli_query($conn, $countQuery);
    $totalRow = mysqli_fetch_assoc($countResult);
    $total = $totalRow['total'];

    $query = "SELECT menu_id, menu_name, price, category_name, image_url, thumb_url FROM raki_dev.menu ME LEFT JOIN raki_dev.category_menu CM ON ME.category_id = CM.category_id  WHERE is_active = 1 AND menu_name LIKE '%$params%' LIMIT $limit OFFSET $offset";
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
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/menu/menu.php',
            'method'        => 'GET',
            'error_message' => 'Menu not found: ' . mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(404, 'Menu not found');
    }
}

function getDetailMenu($conn, $menu_id){
    $query = "SELECT menu_id, menu_name, price, category_name, image_url, thumb_url, ME.company_id, is_active, ME.created_at, ME.created_by, ME.updated_at, ME.updated_by FROM raki_dev.menu ME LEFT JOIN raki_dev.category_menu CM ON ME.category_id = CM.category_id WHERE is_active = 1 AND ME.menu_id = '$menu_id'";
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
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/menu/menu.php',
            'method'        => 'GET',
            'error_message' => 'Menu not found: ' . mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(404, 'Menu not found');
    }
}

function updateMenu($conn, $input, $username){
    if (!isset($input['menu_id'])) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/menu.php',
            'method'        => 'PUT',
            'error_message' => 'Missing required fields (menu_id)',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Missing required fields (menu_id)');
    }

    $menu_id = mysqli_real_escape_string($conn, $input['menu_id']);

    $query = "SELECT * FROM raki_dev.menu WHERE menu_id = '$menu_id'";
    $result = mysqli_query($conn, $query);

    $now = getCurrentDateTimeJakarta();

    $updates = [];
    if (isset($input['menu_name'])) {
        $updates[] = "menu_name = '" . mysqli_real_escape_string($conn, $input['menu_name']) . "'";
    }
    if (isset($input['category_id'])) {
        $updates[] = "category_id = '" . mysqli_real_escape_string($conn, $input['category_id']) . "'";
    }
    if (isset($input['price'])) {
        $updates[] = "price = '" . mysqli_real_escape_string($conn, $input['price']) . "'";
    }

    if (!empty($_FILES['image']) && isset($_FILES['image']['error'])) {
        if ($_FILES['image']['error'] === UPLOAD_ERR_OK) {
            [$image_url, $thumb_url] = handle_menu_image_upload($_FILES['image'], 'menu');

            if ($image_url) {
                $img_sql = mysqli_real_escape_string($conn, $image_url);
                $updates[] = "image_url = '$img_sql'";
            }

            if ($thumb_url) {
                $thumb_sql = mysqli_real_escape_string($conn, $thumb_url);
                $updates[] = "thumb_url = '$thumb_sql'";
            }
        } elseif ($_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 400,
                'endpoint'      => '/menu/menu.php',
                'method'        => 'PUT',
                'error_message' => 'Upload error code: ' . $_FILES['image']['error'],
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(400, 'Upload error code: ' . $_FILES['image']['error']);
        }
    }

    if (empty($updates)) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/menu.php',
            'method'        => 'PUT',
            'error_message' => 'No fields provided for update',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'No fields provided for update');
    }

    if(mysqli_num_rows($result) > 0) {
        $updateQuery = "UPDATE raki_dev.menu SET " . implode(', ', $updates) . " WHERE menu_id = '$menu_id'";

        if (mysqli_query($conn, $updateQuery)) {
            jsonResponse(200, 'Menu updated successfully', ['menu_id' => $menu_id]);
        } else {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 404,
                'endpoint'      => '/menu/menu.php',
                'method'        => 'PUT',
                'error_message' => 'Failed to update menu : ' . mysqli_error($conn),
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(500, 'Failed to update menu');
        }
    } else {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/menu/menu.php',
            'method'        => 'PUT',
            'error_message' => 'Menu not registered in systems',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(404, message: 'Menu not registered in systems');
    }

}

function deleteMenu($conn, $menu_id, $username){
    if($menu_id === null || $menu_id === ''){
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/menu.php',
            'method'        => 'DELETE',
            'error_message' => 'Missing required fields (menu_id)',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Missing required fields (menu_id)');
    }

    $query = "SELECT * FROM raki_dev.menu WHERE menu_id = '$menu_id'";
    $result = mysqli_query($conn, $query);

    if(mysqli_num_rows($result) > 0) {
        $query = "DELETE FROM raki_dev.menu WHERE menu_id = '$menu_id'";

        if (mysqli_query($conn, $query)) {
            jsonResponse(200, 'Menu deleted successfully');
        } else {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/menu/menu.php',
                'method'        => 'DELETE',
                'error_message' => 'Failed to delete menu : ' . mysqli_error($conn),
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(500, 'Failed to delete menu');
        }

    } else {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/menu/menu.php',
            'method'        => 'DELETE',
            'error_message' => 'Menu not registered in systems',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(404, 'Menu is not registered in systems');
    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$method = $_SERVER['REQUEST_METHOD'];
$conn = DB::conn();

$token_username = null;

// ONLY REQUIRE TOKEN FOR NON-GET REQUESTS
if ($method !== 'GET') {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);

    if (!$authHeader) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 401,
            'endpoint'      => '/menu/menu.php',
            'method'        => '',
            'error_message' => 'Authorization header not found',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(401, 'Authorization header not found');
    }

    try {
        $token = preg_replace('/^Bearer\s+/i', '', $authHeader);
        $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
        $token_username = $decoded->username;
    } catch (Exception $e) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 401,
            'endpoint'      => '/menu/menu.php',
            'method'        => '',
            'error_message' => $e->getMessage(),
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(401, 'Invalid or expired token', ['error' => $e->getMessage()]);
    }
}

try {

    switch($method){
        case 'POST':
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
            } else {
                $input = $_POST ?? [];
            }

            if (!empty($input['menu_id'])) {
                // UPDATE + boleh ada $_FILES['image']
                updateMenu($conn, $input, $token_username);
            } else {
                // CREATE
                createMenu($conn, $input, $token_username);
            }
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 50;
            $menu_id = $_GET['menu_id'] ?? null;
            if($menu_id != null){
                getDetailMenu($conn, $menu_id);
            } else {
                getAllMenu($conn, $params, $page, $limit);
             }
            break;
        case 'PUT':
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
            } else {
                $raw = file_get_contents('php://input');
                $input = [];
                parse_str($raw, $input); // x-www-form-urlencoded (tanpa file)
            }
            updateMenu($conn, $input, $token_username);
            break;
        case 'DELETE':
            $menu_id = $_GET['menu_id'] ?? null;
            deleteMenu($conn, $menu_id, $token_username);
            break;
    }

} catch (Exception $e){
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/menu/menu.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>