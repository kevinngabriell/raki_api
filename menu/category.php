<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function createCategory($conn, $schema, $input, $username) {
    $category_name = $input['category_name'] ?? null;

    if ($category_name === null || $category_name === '') {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/category.php',
            'method'        => 'POST',
            'error_message' => 'category_name is required',
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'Category name is required');
    }

    $stmt = $conn->prepare("SELECT category_id FROM {$schema}.category_menu WHERE category_name = ?");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/category.php',
            'method'        => 'POST',
            'error_message' => 'Database query error',
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $category_name);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/category.php',
            'method'        => 'POST',
            'error_message' => 'Category already exists in database',
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'Category already exists in database');
    }
    $stmt->close();

    $categoryID = "category" . uniqid();
    $now = getCurrentDateTimeJakarta();

    $stmt = $conn->prepare("INSERT INTO {$schema}.category_menu (category_id, category_name, company_id, created_by, created_at) VALUES (?, ?, NULL, ?, ?)");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/category.php',
            'method'        => 'POST',
            'error_message' => 'Database query error',
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Database query error');
    }

    $stmt->bind_param('ssss', $categoryID, $category_name, $username, $now);

    if ($stmt->execute()) {
        $stmt->close();
        jsonResponse(201, 'New category has been created successfully', ['category' => $category_name]);
    } else {
        $stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/category.php',
            'method'        => 'POST',
            'error_message' => 'Failed to create a new category',
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Failed to create a new category');
    }
}

function getAllCategory($conn, $schema, $params, $page = 1, $limit = 10) {
    $page = (int)$page;
    $limit = (int)$limit;
    $offset = ($page - 1) * $limit;
    $search = '%' . ($params ?? '') . '%';

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM {$schema}.category_menu WHERE category_name LIKE ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $search);
    $stmt->execute();
    $result = $stmt->get_result();
    $totalRow = $result->fetch_assoc();
    $total = (int)$totalRow['total'];
    $stmt->close();

    $query = "SELECT category_id, category_name FROM {$schema}.category_menu WHERE category_name LIKE ? LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('sii', $search, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $response = [
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ]
        ];
        jsonResponse(200, 'Category found', $response);
    } else {
        $stmt->close();
        jsonResponse(404, 'Category not found');
    }
}

function getDetailCategory($conn, $schema, $category_id) {
    $stmt = $conn->prepare("SELECT * FROM {$schema}.category_menu WHERE category_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $category_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
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
        $stmt->close();
        jsonResponse(404, 'Category not found');
    }
}

function updateCategory($conn, $schema, $input, $username) {
    $category_id   = trim((string)($input['category_id'] ?? ''));
    $category_name = trim((string)($input['category_name'] ?? ''));

    if ($category_id === '' || $category_name === '') {
        apiReject($conn, '/menu/category.php', 'PUT', $username, 400, 'Missing required fields (category_id and category_name)');
    }

    if (referenceCount($conn, "SELECT COUNT(*) FROM {$schema}.category_menu WHERE category_id = ?", $category_id) === 0) {
        apiReject($conn, '/menu/category.php', 'PUT', $username, 404, 'Category menu is not registered in systems');
    }

    $stmt = $conn->prepare("SELECT category_id FROM {$schema}.category_menu WHERE category_name = ? AND category_id <> ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('ss', $category_name, $category_id);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($taken) {
        apiReject($conn, '/menu/category.php', 'PUT', $username, 400, 'Category already exists in database');
    }

    $now = getCurrentDateTimeJakarta();
    $stmt = $conn->prepare("UPDATE {$schema}.category_menu SET category_name = ?, updated_by = ?, updated_at = ? WHERE category_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('ssss', $category_name, $username, $now, $category_id);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        apiReject($conn, '/menu/category.php', 'PUT', $username, 500, 'Failed to update category menu');
    }
    jsonResponse(200, 'Category menu updated successfully', ['category_id' => $category_id]);
}

function deleteCategory($conn, $schema, $category_id, $username) {
    if ($category_id === null || $category_id === '') {
        apiReject($conn, '/menu/category.php', 'DELETE', $username, 400, 'Missing required fields (category_id)');
    }

    if (referenceCount($conn, "SELECT COUNT(*) FROM {$schema}.category_menu WHERE category_id = ?", $category_id) === 0) {
        apiReject($conn, '/menu/category.php', 'DELETE', $username, 404, 'Category menu is not registered in systems');
    }

    $menus = referenceCount($conn, "SELECT COUNT(*) FROM {$schema}.menu WHERE category_id = ?", $category_id);
    if ($menus > 0) {
        apiReject($conn, '/menu/category.php', 'DELETE', $username, 409,
            "Can't delete this category: $menus menu(s) still use it. Move or delete those menus first.");
    }

    $promos = referenceCount($conn,
        "SELECT COUNT(DISTINCT promo_id) FROM {$schema}.promo_condition WHERE condition_type = 'category' AND condition_value = ?", $category_id);
    if ($promos > 0) {
        apiReject($conn, '/menu/category.php', 'DELETE', $username, 409,
            "Can't delete this category: $promos promo(s) still use it. Remove it from those promos first.");
    }

    $stmt = $conn->prepare("DELETE FROM {$schema}.category_menu WHERE category_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $category_id);
    try {
        $ok = $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        $stmt->close();
        if ($e->getCode() === 1451) { // a foreign key still points here
            apiReject($conn, '/menu/category.php', 'DELETE', $username, 409, "Can't delete this category: it is still in use.");
        }
        throw $e;
    }
    $stmt->close();

    if (!$ok) {
        apiReject($conn, '/menu/category.php', 'DELETE', $username, 500, 'Failed to delete category menu');
    }
    jsonResponse(200, 'Category menu deleted successfully');
}

$decoded = requireAuth();

$conn = DB::conn();
$schema = DB_SCHEMA;

$token_username = $decoded->username;
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && !isMenuManager($decoded)) {
    logApiError($conn, [
        'error_level'   => 'warning',
        'http_status'   => 403,
        'endpoint'      => '/menu/category.php',
        'method'        => $method,
        'error_message' => 'Only the Owner can manage categories',
        'user_identifier' => $token_username,
        'company_id'      => null,
    ]);
    jsonResponse(403, 'Only the Owner can manage categories');
}

try {
    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createCategory($conn, $schema, $input, $token_username);
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $category_id = $_GET['category_id'] ?? null;
            if ($category_id != null) {
                getDetailCategory($conn, $schema, $category_id);
            } else {
                getAllCategory($conn, $schema, $params, $page, $limit);
            }
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updateCategory($conn, $schema, $input, $token_username);
            break;
        case 'DELETE':
            $category_id = $_GET['category_id'] ?? null;
            deleteCategory($conn, $schema, $category_id, $token_username);
            break;
    }

} catch (Exception $e) {
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/menu/category.php',
        'method'        => $method ?? '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $token_username ?? null,
        'company_id'      => null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
