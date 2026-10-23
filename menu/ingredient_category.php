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

function createIngredientCategory($conn, $schema, $input, $username) {
    if (!isset($input['category_name'])) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/ingredient_category.php',
            'method'        => 'POST',
            'error_message' => 'Missing required fields (category_name)',
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'Missing required fields (category_name)');
    }

    $category_name = $input['category_name'];

    $stmt = $conn->prepare("SELECT category_id FROM {$schema}.ingredient_category WHERE category_name = ?");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/ingredient_category.php',
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
            'endpoint'      => '/menu/ingredient_category.php',
            'method'        => 'POST',
            'error_message' => 'Ingredients category already exists in database',
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'Ingredients category already exists in database');
    }
    $stmt->close();

    $categoryID = "category" . uniqid();
    $now = getCurrentDateTimeJakarta();

    $stmt = $conn->prepare("INSERT INTO {$schema}.ingredient_category (category_id, category_name, created_by, created_at) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/ingredient_category.php',
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
        jsonResponse(201, 'New ingredients category has been created successfully', ['category' => $category_name]);
    } else {
        $stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/ingredient_category.php',
            'method'        => 'POST',
            'error_message' => 'Failed to create a new ingredients category',
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Failed to create a new ingredients category');
    }
}

function getAllIngredientCategory($conn, $schema, $params, $username, $page = 1, $limit = 10) {
    $page = (int)$page;
    $limit = pageLimit($limit);
    $offset = ($page - 1) * $limit;
    $search = '%' . ($params ?? '') . '%';

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM {$schema}.ingredient_category WHERE category_name LIKE ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $search);
    $stmt->execute();
    $result = $stmt->get_result();
    $totalRow = $result->fetch_assoc();
    $total = (int)$totalRow['total'];
    $stmt->close();

    $query = "SELECT category_id, category_name FROM {$schema}.ingredient_category WHERE category_name LIKE ? LIMIT ? OFFSET ?";
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

function getDetailIngredientCategory($conn, $schema, $category_id) {
    $stmt = $conn->prepare("SELECT * FROM {$schema}.ingredient_category WHERE category_id = ?");
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

function updateIngredientCategory($conn, $schema, $input, $username) {
    $category_id   = trim((string)($input['category_id'] ?? ''));
    $category_name = trim((string)($input['category_name'] ?? ''));

    if ($category_id === '' || $category_name === '') {
        apiReject($conn, '/menu/ingredient_category.php', 'PUT', $username, 400, 'Missing required fields (category_id and category_name)');
    }

    if (referenceCount($conn, "SELECT COUNT(*) FROM {$schema}.ingredient_category WHERE category_id = ?", $category_id) === 0) {
        apiReject($conn, '/menu/ingredient_category.php', 'PUT', $username, 404, 'Ingredients category menu is not registered in systems');
    }

    $stmt = $conn->prepare("SELECT category_id FROM {$schema}.ingredient_category WHERE category_name = ? AND category_id <> ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('ss', $category_name, $category_id);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if ($taken) {
        apiReject($conn, '/menu/ingredient_category.php', 'PUT', $username, 400, 'Ingredients category already exists in database');
    }

    $stmt = $conn->prepare("UPDATE {$schema}.ingredient_category SET category_name = ? WHERE category_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('ss', $category_name, $category_id);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        apiReject($conn, '/menu/ingredient_category.php', 'PUT', $username, 500, 'Failed to update ingredients category menu');
    }
    jsonResponse(200, 'Ingredients category menu updated successfully', ['category_id' => $category_id]);
}

function deleteIngredientCategory($conn, $schema, $category_id, $username) {
    if ($category_id === null || $category_id === '') {
        apiReject($conn, '/menu/ingredient_category.php', 'DELETE', $username, 400, 'Missing required fields (category_id)');
    }

    if (referenceCount($conn, "SELECT COUNT(*) FROM {$schema}.ingredient_category WHERE category_id = ?", $category_id) === 0) {
        apiReject($conn, '/menu/ingredient_category.php', 'DELETE', $username, 404, 'Ingredients category menu is not registered in systems');
    }

    $items = referenceCount($conn, "SELECT COUNT(*) FROM {$schema}.ingredient WHERE ingredient_category = ?", $category_id);
    if ($items > 0) {
        apiReject($conn, '/menu/ingredient_category.php', 'DELETE', $username, 409,
            "Can't delete this ingredient category: $items ingredient(s) still use it. Move or delete those ingredients first.");
    }

    $stmt = $conn->prepare("DELETE FROM {$schema}.ingredient_category WHERE category_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $category_id);
    try {
        $ok = $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        $stmt->close();
        if ($e->getCode() === 1451) {
            apiReject($conn, '/menu/ingredient_category.php', 'DELETE', $username, 409, "Can't delete this ingredient category: it is still in use.");
        }
        throw $e;
    }
    $stmt->close();

    if (!$ok) {
        apiReject($conn, '/menu/ingredient_category.php', 'DELETE', $username, 500, 'Failed to delete ingredients category menu');
    }
    jsonResponse(200, 'Ingredients category menu deleted successfully');
}

$decoded = requireAuth();

$conn = DB::conn();
$schema = DB_SCHEMA;

$token_username = $decoded->username;
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && !isMenuManager($decoded)) {
    apiReject($conn, '/menu/ingredient_category.php', $method, $token_username, 403, 'Only the Owner can manage ingredient categories');
}

try {
    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createIngredientCategory($conn, $schema, $input, $token_username);
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $category_id = $_GET['category_id'] ?? null;
            if ($category_id != null) {
                getDetailIngredientCategory($conn, $schema, $category_id);
            } else {
                getAllIngredientCategory($conn, $schema, $params, $token_username, $page, $limit);
            }
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updateIngredientCategory($conn, $schema, $input, $token_username);
            break;
        case 'DELETE':
            $category_id = $_GET['category_id'] ?? null;
            deleteIngredientCategory($conn, $schema, $category_id, $token_username);
            break;
    }

} catch (Exception $e) {
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/menu/ingredient_category.php',
        'method'        => $method ?? '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $token_username ?? null,
        'company_id'      => null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
