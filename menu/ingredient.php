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

function createIngredient($conn, $schema, $input, $username, $company_id) {
    $ingredient_name = $input['ingredient_name'] ?? null;
    $ingredient_category = $input['ingredient_category'] ?? null;
    $uom = $input['uom'] ?? null;
    $sku = $input['sku'] ?? null;
    $is_active = $input['is_active'] ?? null;
    $price = $input['price'] ?? null;

    if ($ingredient_name === null || $ingredient_name === '' ||
        $ingredient_category === null || $ingredient_category === '' ||
        $uom === null || $uom === '' ||
        $company_id === null || $company_id === '' ||
        $price === null || $price === '') {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/ingredient.php',
            'method'        => 'POST',
            'error_message' => 'ingredient_name, ingredient_category, uom, company_id, price are required',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(400, 'ingredient_name, ingredient_category, uom, company_id, price are required');
    }

    if (!is_numeric($price)) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/ingredient.php',
            'method'        => 'POST',
            'error_message' => 'price must be numeric',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(400, 'price must be numeric');
    }

    $price_val = (float)$price;
    $is_active_val = $is_active ? 1 : 0;

    $stmt = $conn->prepare("SELECT ingredient_id FROM {$schema}.ingredient WHERE ingredient_name = ? AND company_id = ?");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/ingredient.php',
            'method'        => 'POST',
            'error_message' => 'Database query error',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('ss', $ingredient_name, $company_id);
    $stmt->execute();
    $check_app_result = $stmt->get_result();

    $stmt = $conn->prepare("SELECT category_id FROM {$schema}.ingredient_category WHERE category_id = ?");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/ingredient.php',
            'method'        => 'POST',
            'error_message' => 'Database query error',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $ingredient_category);
    $stmt->execute();
    $check_category_result = $stmt->get_result();

    $stmt = $conn->prepare("SELECT uom_id FROM {$schema}.unit_of_measurement WHERE uom_id = ?");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/ingredient.php',
            'method'        => 'POST',
            'error_message' => 'Database query error',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $uom);
    $stmt->execute();
    $check_uom_result = $stmt->get_result();

    if ($check_app_result && $check_app_result->num_rows > 0) {
        $stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/ingredient.php',
            'method'        => 'POST',
            'error_message' => 'Ingredient already exists in database',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(400, 'Ingredient already exists in database');
    }

    if ($check_category_result && $check_category_result->num_rows < 1) {
        $stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/ingredient.php',
            'method'        => 'POST',
            'error_message' => 'Ingredients category does not exist in database',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(400, 'Ingredients category does not exist in database');
    }

    if ($check_uom_result && $check_uom_result->num_rows < 1) {
        $stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/menu/ingredient.php',
            'method'        => 'POST',
            'error_message' => 'UOM does not exist in database',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(400, 'UOM does not exist in database');
    }

    $stmt->close();

    $ingredientsID = "ingredients" . uniqid();
    $now = getCurrentDateTimeJakarta();

    $stmt = $conn->prepare("INSERT INTO {$schema}.ingredient (ingredient_id, ingredient_name, ingredient_category, uom, sku, company_id, is_active, created_at, created_by, price) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/ingredient.php',
            'method'        => 'POST',
            'error_message' => 'Database query error',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Database query error');
    }

    $stmt->bind_param('sssssiissd', $ingredientsID, $ingredient_name, $ingredient_category, $uom, $sku, $company_id, $is_active_val, $now, $username, $price_val);

    if ($stmt->execute()) {
        $stmt->close();
        jsonResponse(201, 'New ingredients has been created successfully', ['ingredients' => $ingredient_name]);
    } else {
        $stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/ingredient.php',
            'method'        => 'POST',
            'error_message' => 'Failed to create a new ingredients',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to create a new ingredients');
    }
}

function getAllIngredient($conn, $schema, $params, $page = 1, $limit = 10) {
    $page = (int)$page;
    $limit = (int)$limit;
    $offset = ($page - 1) * $limit;
    $search = '%' . ($params ?? '') . '%';

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM {$schema}.ingredient WHERE ingredient_name LIKE ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $search);
    $stmt->execute();
    $result = $stmt->get_result();
    $totalRow = $result->fetch_assoc();
    $total = (int)$totalRow['total'];
    $stmt->close();

    $query = "SELECT ingredient_id, ingredient_name, UOM.uom_name, IC.category_name, price FROM {$schema}.ingredient I LEFT JOIN {$schema}.unit_of_measurement UOM ON I.uom = UOM.uom_id LEFT JOIN {$schema}.ingredient_category IC ON I.ingredient_category = IC.category_id WHERE ingredient_name LIKE ? LIMIT ? OFFSET ?";
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
        jsonResponse(200, 'Ingredient found', $response);
    } else {
        $stmt->close();
        jsonResponse(404, 'Ingredient not found');
    }
}

function getDetailIngredient($conn, $schema, $ingredient_id) {
    $query = "SELECT ingredient_id, ingredient_name, IC.category_id, IC.category_name, I.sku, UOM.uom_id, UOM.uom_name, price FROM {$schema}.ingredient I LEFT JOIN {$schema}.ingredient_category IC ON I.ingredient_category = IC.category_id LEFT JOIN {$schema}.unit_of_measurement UOM ON I.uom = UOM.uom_id WHERE ingredient_id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $ingredient_id);
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
        jsonResponse(200, 'Ingredient found', $response);
    } else {
        $stmt->close();
        jsonResponse(404, 'Ingredient not found');
    }
}

function updateIngredient($conn, $schema, $input, $username, $company_id) {
    $ingredient_id = trim((string)($input['ingredient_id'] ?? ''));
    if ($ingredient_id === '') {
        apiReject($conn, '/menu/ingredient.php', 'PUT', $username, 400, 'Missing required fields (ingredient_id)');
    }

    $stmt = $conn->prepare("SELECT ingredient_name, company_id FROM {$schema}.ingredient WHERE ingredient_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $ingredient_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$existing) {
        apiReject($conn, '/menu/ingredient.php', 'PUT', $username, 404, 'Ingredient not registered in systems');
    }

    $updates = [];
    $types = '';
    $values = [];

    if (isset($input['ingredient_name'])) {
        $name = trim((string)$input['ingredient_name']);
        if ($name === '') {
            apiReject($conn, '/menu/ingredient.php', 'PUT', $username, 400, 'ingredient_name cannot be empty');
        }
        if ($name !== $existing['ingredient_name']) {
            $stmt = $conn->prepare("SELECT ingredient_id FROM {$schema}.ingredient WHERE ingredient_name = ? AND company_id <=> ? AND ingredient_id <> ?");
            if (!$stmt) {
                jsonResponse(500, 'Database query error');
            }
            $stmt->bind_param('sss', $name, $existing['company_id'], $ingredient_id);
            $stmt->execute();
            $taken = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($taken) {
                apiReject($conn, '/menu/ingredient.php', 'PUT', $username, 400, 'Ingredient already exists in database');
            }
        }
        $updates[] = "ingredient_name = ?";
        $types .= 's';
        $values[] = $name;
    }
    if (isset($input['ingredient_category'])) {
        if (referenceCount($conn, "SELECT COUNT(*) FROM {$schema}.ingredient_category WHERE category_id = ?", (string)$input['ingredient_category']) === 0) {
            apiReject($conn, '/menu/ingredient.php', 'PUT', $username, 400, 'Ingredients category does not exist in database');
        }
        $updates[] = "ingredient_category = ?";
        $types .= 's';
        $values[] = $input['ingredient_category'];
    }
    if (isset($input['uom'])) {
        if (referenceCount($conn, "SELECT COUNT(*) FROM {$schema}.unit_of_measurement WHERE uom_id = ?", (string)$input['uom']) === 0) {
            apiReject($conn, '/menu/ingredient.php', 'PUT', $username, 400, 'UOM does not exist in database');
        }
        $updates[] = "uom = ?";
        $types .= 's';
        $values[] = $input['uom'];
    }
    if (isset($input['sku'])) {
        $updates[] = "sku = ?";
        $types .= 's';
        $values[] = trim((string)$input['sku']);
    }
    if (isset($input['price'])) {
        if (!is_numeric($input['price']) || (float)$input['price'] < 0) {
            apiReject($conn, '/menu/ingredient.php', 'PUT', $username, 400, 'price must be a non-negative number');
        }
        $updates[] = "price = ?";
        $types .= 'd';
        $values[] = (float)$input['price'];
    }
    if (isset($input['is_active'])) {
        $flag = filter_var($input['is_active'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
        if ($flag === false) {
            apiReject($conn, '/menu/ingredient.php', 'PUT', $username, 400, 'is_active must be 0 or 1');
        }
        $updates[] = "is_active = ?";
        $types .= 'i';
        $values[] = $flag;
    }

    if (empty($updates)) {
        apiReject($conn, '/menu/ingredient.php', 'PUT', $username, 400, 'No fields provided for update');
    }

    $types .= 's';
    $values[] = $ingredient_id;

    $stmt = $conn->prepare("UPDATE {$schema}.ingredient SET " . implode(', ', $updates) . " WHERE ingredient_id = ?");
    if (!$stmt) {
        apiReject($conn, '/menu/ingredient.php', 'PUT', $username, 500, 'Database query error');
    }
    $stmt->bind_param($types, ...$values);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        apiReject($conn, '/menu/ingredient.php', 'PUT', $username, 500, 'Failed to update ingredient');
    }
    jsonResponse(200, 'Ingredient updated successfully', ['ingredient_id' => $ingredient_id]);
}

function deleteIngredient($conn, $schema, $ingredient_id, $username, $company_id) {
    if ($ingredient_id === null || $ingredient_id === '') {
        apiReject($conn, '/menu/ingredient.php', 'DELETE', $username, 400, 'Missing required fields (ingredient_id)');
    }

    if (referenceCount($conn, "SELECT COUNT(*) FROM {$schema}.ingredient WHERE ingredient_id = ?", $ingredient_id) === 0) {
        apiReject($conn, '/menu/ingredient.php', 'DELETE', $username, 404, 'Ingredient is not registered in systems');
    }

    $orders = referenceCount($conn,
        "SELECT COUNT(DISTINCT supply_order_id) FROM {$schema}.supply_order_detail WHERE ingredient_id = ?", $ingredient_id);
    if ($orders > 0) {
        apiReject($conn, '/menu/ingredient.php', 'DELETE', $username, 409,
            "Can't delete this ingredient: it is used in $orders supply order(s).");
    }

    $stmt = $conn->prepare("DELETE FROM {$schema}.ingredient WHERE ingredient_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $ingredient_id);
    try {
        $ok = $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        $stmt->close();
        if ($e->getCode() === 1451) {
            apiReject($conn, '/menu/ingredient.php', 'DELETE', $username, 409, "Can't delete this ingredient: it is still in use.");
        }
        throw $e;
    }
    $stmt->close();

    if (!$ok) {
        apiReject($conn, '/menu/ingredient.php', 'DELETE', $username, 500, 'Failed to delete ingredient');
    }
    jsonResponse(200, 'Ingredient deleted successfully');
}

$decoded = requireAuth();

$conn = DB::conn();
$schema = DB_SCHEMA;

$token_username = $decoded->username;
$token_company_id = $decoded->company_id;
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET' && !isMenuManager($decoded)) {
    apiReject($conn, '/menu/ingredient.php', $method, $token_username, 403, 'Only the Owner can manage ingredients');
}

try {
    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createIngredient($conn, $schema, $input, $token_username, $token_company_id);
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $ingredient_id = $_GET['ingredient_id'] ?? null;
            if ($ingredient_id != null) {
                getDetailIngredient($conn, $schema, $ingredient_id);
            } else {
                getAllIngredient($conn, $schema, $params, $page, $limit);
            }
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updateIngredient($conn, $schema, $input, $token_username, $token_company_id);
            break;
        case 'DELETE':
            $ingredient_id = $_GET['ingredient_id'] ?? null;
            deleteIngredient($conn, $schema, $ingredient_id, $token_username, $token_company_id);
            break;
    }

} catch (Exception $e) {
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/menu/ingredient.php',
        'method'        => $method ?? '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $token_username ?? null,
        'company_id'      => $token_company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
