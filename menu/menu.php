<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function menuError($conn, $status, $message, $method, $username) {
    logApiError($conn, [
        'error_level'   => $status >= 500 ? 'error' : 'warning',
        'http_status'   => $status,
        'endpoint'      => '/menu/menu.php',
        'method'        => $method,
        'error_message' => $message,
        'user_identifier' => $username ?? null,
        'company_id'      => null,
    ]);
    jsonResponse($status, $message);
}

function menuCategoryExists($conn, $schema, $category_id) {
    $stmt = $conn->prepare("SELECT category_id FROM {$schema}.category_menu WHERE category_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $category_id);
    $stmt->execute();
    $found = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $found;
}

function menuNameTaken($conn, $schema, $menu_name, $exclude_menu_id = null) {
    $stmt = $conn->prepare("SELECT menu_id FROM {$schema}.menu WHERE menu_name = ? AND menu_id <> ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $exclude = $exclude_menu_id ?? '';
    $stmt->bind_param('ss', $menu_name, $exclude);
    $stmt->execute();
    $taken = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $taken;
}

// Returns [image_url, thumb_url], or [null, null] when no file was sent.
function menuImageUpload() {
    if (empty($_FILES['image']) || !isset($_FILES['image']['error']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    return handle_menu_image_upload($_FILES['image'], 'menu');
}

function createMenu($conn, $schema, $input, $username) {
    $menu_name   = isset($input['menu_name']) ? trim((string)$input['menu_name']) : (isset($_POST['menu_name']) ? trim((string)$_POST['menu_name']) : null);
    $category_id = $input['category_id'] ?? ($_POST['category_id'] ?? null);
    $price       = $input['price']       ?? ($_POST['price']       ?? null);

    if ($menu_name === null || $menu_name === '' || $category_id === null || $category_id === '') {
        menuError($conn, 400, 'menu_name and category_id are required', 'POST', $username);
    }

    $price_val = null;
    if ($price !== null && $price !== '') {
        if (!is_numeric($price) || (float)$price < 0) {
            menuError($conn, 400, 'price must be a non-negative number', 'POST', $username);
        }
        $price_val = (float)$price;
    }

    if (!menuCategoryExists($conn, $schema, $category_id)) {
        menuError($conn, 404, 'Category not found', 'POST', $username);
    }

    if (menuNameTaken($conn, $schema, $menu_name)) {
        menuError($conn, 400, 'Menu already exists in database', 'POST', $username);
    }

    $menuID = "menu" . uniqid();

    [$image_url, $thumb_url] = menuImageUpload();

    // mysqli throws on failure in PHP 8.1+, so prepare/bind/execute all sit inside the cleanup.
    $stmt = null;
    try {
        $stmt = $conn->prepare("INSERT INTO {$schema}.menu (menu_id, menu_name, category_id, price, image_url, thumb_url, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new RuntimeException('Database query error');
        }
        $stmt->bind_param('sssdsss', $menuID, $menu_name, $category_id, $price_val, $image_url, $thumb_url, $username);
        $saved = $stmt->execute();
    } catch (Throwable $e) {
        if ($stmt) $stmt->close();
        delete_menu_image_urls([$image_url, $thumb_url]);
        throw $e;
    }
    $stmt->close();

    if (!$saved) {
        delete_menu_image_urls([$image_url, $thumb_url]);
        menuError($conn, 500, 'Failed to create a new menu', 'POST', $username);
    }

    jsonResponse(201, 'New menu has been created successfully', [
        'menu'      => $menu_name,
        'menu_id'   => $menuID,
        'image_url' => $image_url,
        'thumb_url' => $thumb_url,
    ]);
}

function getAllMenu($conn, $schema, $params, $page = 1, $limit = 50, $showAll = false) {
    $page = max(1, (int)$page);
    $limit = min(200, max(1, (int)$limit));
    $offset = ($page - 1) * $limit;
    $search = '%' . ($params ?? '') . '%';
    $activeFilter = $showAll ? '' : 'ME.is_active = 1 AND ';

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM {$schema}.menu ME WHERE {$activeFilter}ME.menu_name LIKE ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $search);
    $stmt->execute();
    $result = $stmt->get_result();
    $totalRow = $result->fetch_assoc();
    $total = (int)$totalRow['total'];
    $stmt->close();

    $query = "SELECT ME.menu_id, ME.menu_name, ME.price, ME.category_id, CM.category_name, ME.image_url, ME.thumb_url, ME.is_active
              FROM {$schema}.menu ME
              LEFT JOIN {$schema}.category_menu CM ON ME.category_id = CM.category_id
              WHERE {$activeFilter}ME.menu_name LIKE ?
              ORDER BY ME.created_at, ME.menu_id
              LIMIT ? OFFSET ?";
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
        jsonResponse(200, 'Menu found', $response);
    } else {
        $stmt->close();
        jsonResponse(404, 'Menu not found');
    }
}

function getDetailMenu($conn, $schema, $menu_id, $showAll = false) {
    $activeFilter = $showAll ? '' : 'ME.is_active = 1 AND ';
    $stmt = $conn->prepare("SELECT ME.menu_id, ME.menu_name, ME.price, ME.category_id, CM.category_name, ME.image_url, ME.thumb_url, ME.company_id, ME.is_active, ME.created_at, ME.created_by, ME.updated_at, ME.updated_by FROM {$schema}.menu ME LEFT JOIN {$schema}.category_menu CM ON ME.category_id = CM.category_id WHERE {$activeFilter}ME.menu_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $menu_id);
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
        jsonResponse(200, 'Menu found', $response);
    } else {
        $stmt->close();
        jsonResponse(404, 'Menu not found');
    }
}

function updateMenu($conn, $schema, $input, $username) {
    if (!isset($input['menu_id'])) {
        menuError($conn, 400, 'Missing required fields (menu_id)', 'PUT', $username);
    }

    $menu_id = $input['menu_id'];

    $stmt = $conn->prepare("SELECT menu_id, menu_name, image_url, thumb_url FROM {$schema}.menu WHERE menu_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $menu_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
        menuError($conn, 404, 'Menu not registered in systems', 'PUT', $username);
    }

    $updates = [];
    $types = '';
    $values = [];

    if (isset($input['menu_name'])) {
        $menu_name = trim((string)$input['menu_name']);
        if ($menu_name === '') {
            menuError($conn, 400, 'menu_name cannot be empty', 'PUT', $username);
        }
        if ($menu_name !== $existing['menu_name'] && menuNameTaken($conn, $schema, $menu_name, $menu_id)) {
            menuError($conn, 400, 'Menu already exists in database', 'PUT', $username);
        }
        $updates[] = "menu_name = ?";
        $types .= 's';
        $values[] = $menu_name;
    }
    if (isset($input['category_id'])) {
        if (!menuCategoryExists($conn, $schema, $input['category_id'])) {
            menuError($conn, 404, 'Category not found', 'PUT', $username);
        }
        $updates[] = "category_id = ?";
        $types .= 's';
        $values[] = $input['category_id'];
    }
    if (isset($input['price']) && $input['price'] !== '') {
        if (!is_numeric($input['price']) || (float)$input['price'] < 0) {
            menuError($conn, 400, 'price must be a non-negative number', 'PUT', $username);
        }
        $updates[] = "price = ?";
        $types .= 'd';
        $values[] = (float)$input['price'];
    }
    if (isset($input['is_active'])) {
        $flag = filter_var($input['is_active'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
        if ($flag === false) {
            menuError($conn, 400, 'is_active must be 0 or 1', 'PUT', $username);
        }
        $updates[] = "is_active = ?";
        $types .= 'i';
        $values[] = $flag;
    }

    [$image_url, $thumb_url] = menuImageUpload();
    if ($image_url !== null) {
        $updates[] = "image_url = ?";
        $types .= 's';
        $values[] = $image_url;
        $updates[] = "thumb_url = ?";
        $types .= 's';
        $values[] = $thumb_url;
    }

    if (empty($updates)) {
        menuError($conn, 400, 'No fields provided for update', 'PUT', $username);
    }

    $updates[] = "updated_by = ?";
    $types .= 's';
    $values[] = $username;
    $updates[] = "updated_at = ?";
    $types .= 's';
    $values[] = getCurrentDateTimeJakarta();

    $types .= 's';
    $values[] = $menu_id;

    $updateQuery = "UPDATE {$schema}.menu SET " . implode(', ', $updates) . " WHERE menu_id = ?";
    $stmt = null;
    try {
        $stmt = $conn->prepare($updateQuery);
        if (!$stmt) {
            throw new RuntimeException('Database query error');
        }
        $stmt->bind_param($types, ...$values);
        $saved = $stmt->execute();
    } catch (Throwable $e) {
        if ($stmt) $stmt->close();
        delete_menu_image_urls([$image_url, $thumb_url]);
        throw $e;
    }
    $stmt->close();

    if (!$saved) {
        delete_menu_image_urls([$image_url, $thumb_url]);
        menuError($conn, 500, 'Failed to update menu', 'PUT', $username);
    }

    if ($image_url !== null) {
        delete_menu_image_urls([$existing['image_url'], $existing['thumb_url']]);
    }

    jsonResponse(200, 'Menu updated successfully', ['menu_id' => $menu_id]);
}

function deleteMenu($conn, $schema, $menu_id, $username) {
    if ($menu_id === null || $menu_id === '') {
        menuError($conn, 400, 'Missing required fields (menu_id)', 'DELETE', $username);
    }

    $stmt = $conn->prepare("SELECT menu_id, image_url, thumb_url FROM {$schema}.menu WHERE menu_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $menu_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
        menuError($conn, 404, 'Menu is not registered in systems', 'DELETE', $username);
    }

    $stmt = $conn->prepare("DELETE FROM {$schema}.menu WHERE menu_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $menu_id);

    $deleted = $stmt->execute();
    $stmt->close();

    if (!$deleted) {
        menuError($conn, 500, 'Failed to delete menu', 'DELETE', $username);
    }

    delete_menu_image_urls([$existing['image_url'], $existing['thumb_url']]);

    jsonResponse(200, 'Menu deleted successfully');
}

$method = $_SERVER['REQUEST_METHOD'];
$conn = DB::conn();
$schema = DB_SCHEMA;

$token_username = null;

// Reading the active menu is public (landing page). Writes, and reading inactive menus, need a token.
$showAll = $method === 'GET' && in_array((string)($_GET['show_all'] ?? ''), ['1', 'true'], true);

if ($method !== 'GET' || $showAll) {
    $decoded = requireAuth();
    $token_username = $decoded->username;
    if (!isMenuManager($decoded)) {
        menuError($conn, 403, 'Only the Owner can manage menus', $method, $token_username);
    }
    if ($method === 'POST' || $method === 'PUT') {
        rejectOversizedBody();
    }
}

try {
    switch ($method) {
        case 'POST':
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
            } else {
                $input = $_POST ?? [];
            }

            if (!empty($input['menu_id'])) {
                updateMenu($conn, $schema, $input, $token_username);
            } else {
                createMenu($conn, $schema, $input, $token_username);
            }
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 50;
            $menu_id = $_GET['menu_id'] ?? null;
            if ($menu_id != null) {
                getDetailMenu($conn, $schema, $menu_id, $showAll);
            } else {
                getAllMenu($conn, $schema, $params, $page, $limit, $showAll);
            }
            break;
        case 'PUT':
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
            } else {
                $raw = file_get_contents('php://input');
                $input = [];
                parse_str($raw, $input);
            }
            updateMenu($conn, $schema, $input, $token_username);
            break;
        case 'DELETE':
            $menu_id = $_GET['menu_id'] ?? null;
            deleteMenu($conn, $schema, $menu_id, $token_username);
            break;
    }

} catch (ApiException $e) {
    throw $e;
} catch (Exception $e) {
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/menu/menu.php',
        'method'        => $method ?? '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $token_username ?? null,
        'company_id'      => null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
