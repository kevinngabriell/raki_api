<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function getCatalog($conn, $schema, $company_id, $params) {
    $today = date('Y-m-d');

    $page   = isset($params['page'])  ? max(1, (int)$params['page'])  : 1;
    $limit  = isset($params['limit']) ? max(1, (int)$params['limit']) : 10;
    $offset = ($page - 1) * $limit;

    $searchSql = '';
    $types     = 'sss';
    $bindVals  = [$company_id, $today, $today];

    if (!empty($params['search'])) {
        $kw = '%' . $params['search'] . '%';
        $searchSql = " AND (reward_name LIKE ? OR description LIKE ?)";
        $types .= 'ss';
        $bindVals[] = $kw;
        $bindVals[] = $kw;
    }

    $whereClause = "WHERE company_id = ?
               AND is_active = 1
               AND (valid_from IS NULL OR valid_from <= ?)
               AND (valid_until IS NULL OR valid_until >= ?)
               $searchSql";

    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM {$schema}.reward_catalog $whereClause");
    if (!$countStmt) jsonResponse(500, 'Internal Server Error');
    $countStmt->bind_param($types, ...$bindVals);
    $countStmt->execute();
    $countRes = $countStmt->get_result();
    $total_data = (int)$countRes->fetch_assoc()['total'];
    $countStmt->close();

    $dataTypes = $types . 'ii';
    $dataVals  = array_merge($bindVals, [$limit, $offset]);

    $dataStmt = $conn->prepare("SELECT * FROM {$schema}.reward_catalog $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?");
    if (!$dataStmt) jsonResponse(500, 'Internal Server Error');
    $dataStmt->bind_param($dataTypes, ...$dataVals);
    $dataStmt->execute();
    $result = $dataStmt->get_result();

    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    $dataStmt->close();

    jsonResponse(200, 'Reward catalog', [
        'data' => $data,
        'pagination' => [
            'current_page' => $page,
            'limit'        => $limit,
            'total_data'   => $total_data,
            'total_page'   => (int)ceil($total_data / $limit),
        ],
    ]);
}

function getCatalogDetail($conn, $schema, $reward_id, $company_id) {
    $stmt = $conn->prepare(
        "SELECT * FROM {$schema}.reward_catalog
         WHERE reward_id = ? AND company_id = ?
         LIMIT 1"
    );
    if (!$stmt) jsonResponse(500, 'Internal Server Error');
    $stmt->bind_param('ss', $reward_id, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        jsonResponse(404, 'Reward not found');
    }

    $data = $result->fetch_assoc();
    $stmt->close();
    jsonResponse(200, 'Reward detail', $data);
}

function createCatalog($conn, $schema, $input, $username, $company_id) {
    $required = ['reward_name', 'point_cost'];
    foreach ($required as $f) {
        if (!isset($input[$f]) || $input[$f] === '') {
            jsonResponse(400, "$f is required");
        }
    }

    $reward_name = trim($input['reward_name']);
    $description = isset($input['description']) ? $input['description'] : null;
    $point_cost  = (int)$input['point_cost'];
    $image_url   = isset($input['image_url'])  ? $input['image_url']  : null;
    $stock       = isset($input['stock'])      ? (int)$input['stock'] : null;
    $is_active   = isset($input['is_active'])  ? (int)$input['is_active'] : 1;
    $valid_from  = isset($input['valid_from'])  && $input['valid_from']  !== '' ? $input['valid_from']  : null;
    $valid_until = isset($input['valid_until']) && $input['valid_until'] !== '' ? $input['valid_until'] : null;

    if ($point_cost <= 0) jsonResponse(400, 'point_cost must be greater than 0');
    if (!in_array($is_active, [0, 1])) jsonResponse(400, 'is_active must be 0 or 1');

    $reward_id = 'reward' . uniqid();
    $now       = getCurrentDateTimeJakarta();

    $stmt = $conn->prepare(
        "INSERT INTO {$schema}.reward_catalog
            (reward_id, company_id, reward_name, description, point_cost, image_url, stock, is_active, valid_from, valid_until, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) jsonResponse(500, 'Internal Server Error');
    $stmt->bind_param(
        'ssssisiissss',
        $reward_id,
        $company_id,
        $reward_name,
        $description,
        $point_cost,
        $image_url,
        $stock,
        $is_active,
        $valid_from,
        $valid_until,
        $username,
        $now
    );
    if (!$stmt->execute()) {
        $stmt->close();
        jsonResponse(500, 'Failed to create reward');
    }
    $stmt->close();

    jsonResponse(201, 'Reward created successfully', ['reward_id' => $reward_id]);
}

function updateCatalog($conn, $schema, $input, $username, $company_id) {
    $reward_id = $input['reward_id'] ?? null;
    if (!$reward_id) jsonResponse(400, 'reward_id is required');

    $checkStmt = $conn->prepare(
        "SELECT reward_id FROM {$schema}.reward_catalog
         WHERE reward_id = ? AND company_id = ? LIMIT 1"
    );
    if (!$checkStmt) jsonResponse(500, 'Internal Server Error');
    $checkStmt->bind_param('ss', $reward_id, $company_id);
    $checkStmt->execute();
    $checkRes = $checkStmt->get_result();
    if (!$checkRes || $checkRes->num_rows === 0) {
        $checkStmt->close();
        jsonResponse(404, 'Reward not found');
    }
    $checkStmt->close();

    $setParts = [];
    $types    = '';
    $values   = [];

    if (isset($input['reward_name'])) {
        $setParts[] = "reward_name = ?";
        $types .= 's';
        $values[] = trim($input['reward_name']);
    }
    if (isset($input['description'])) {
        $setParts[] = "description = ?";
        $types .= 's';
        $values[] = $input['description'];
    }
    if (isset($input['point_cost'])) {
        $pc = (int)$input['point_cost'];
        if ($pc <= 0) jsonResponse(400, 'point_cost must be greater than 0');
        $setParts[] = "point_cost = ?";
        $types .= 'i';
        $values[] = $pc;
    }
    if (isset($input['image_url'])) {
        $setParts[] = "image_url = ?";
        $types .= 's';
        $values[] = $input['image_url'];
    }
    if (array_key_exists('stock', $input)) {
        $setParts[] = "stock = ?";
        $types .= 'i';
        $values[] = $input['stock'] !== null ? (int)$input['stock'] : null;
    }
    if (isset($input['is_active'])) {
        $ia = (int)$input['is_active'];
        if (!in_array($ia, [0, 1])) jsonResponse(400, 'is_active must be 0 or 1');
        $setParts[] = "is_active = ?";
        $types .= 'i';
        $values[] = $ia;
    }
    if (isset($input['valid_from'])) {
        $setParts[] = "valid_from = ?";
        $types .= 's';
        $values[] = $input['valid_from'] !== '' ? $input['valid_from'] : null;
    }
    if (isset($input['valid_until'])) {
        $setParts[] = "valid_until = ?";
        $types .= 's';
        $values[] = $input['valid_until'] !== '' ? $input['valid_until'] : null;
    }

    if (empty($setParts)) jsonResponse(400, 'No fields provided for update');

    $setParts[] = "updated_by = ?";
    $types .= 's';
    $values[] = $username;

    $types .= 'ss';
    $values[] = $reward_id;
    $values[] = $company_id;

    $setClause = implode(', ', $setParts);
    $updateSql = "UPDATE {$schema}.reward_catalog SET {$setClause} WHERE reward_id = ? AND company_id = ?";

    $updateStmt = $conn->prepare($updateSql);
    if (!$updateStmt) jsonResponse(500, 'Internal Server Error');
    $updateStmt->bind_param($types, ...$values);
    if (!$updateStmt->execute()) {
        $updateStmt->close();
        jsonResponse(500, 'Failed to update reward');
    }
    $updateStmt->close();

    jsonResponse(200, 'Reward updated successfully');
}

function deleteCatalog($conn, $schema, $reward_id, $company_id) {
    if (!$reward_id) jsonResponse(400, 'reward_id is required');

    $checkStmt = $conn->prepare(
        "SELECT reward_id FROM {$schema}.reward_catalog
         WHERE reward_id = ? AND company_id = ? LIMIT 1"
    );
    if (!$checkStmt) jsonResponse(500, 'Internal Server Error');
    $checkStmt->bind_param('ss', $reward_id, $company_id);
    $checkStmt->execute();
    $checkRes = $checkStmt->get_result();
    if (!$checkRes || $checkRes->num_rows === 0) {
        $checkStmt->close();
        jsonResponse(404, 'Reward not found');
    }
    $checkStmt->close();

    $deleteStmt = $conn->prepare(
        "DELETE FROM {$schema}.reward_catalog WHERE reward_id = ? AND company_id = ?"
    );
    if (!$deleteStmt) jsonResponse(500, 'Internal Server Error');
    $deleteStmt->bind_param('ss', $reward_id, $company_id);
    if (!$deleteStmt->execute()) {
        $deleteStmt->close();
        jsonResponse(500, 'Failed to delete reward');
    }
    $deleteStmt->close();

    jsonResponse(200, 'Reward deleted successfully');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$decoded = requireAuth();
$conn    = DB::conn();
$schema  = DB_SCHEMA;
$method  = $_SERVER['REQUEST_METHOD'];

$token_username = $decoded->username;
$token_company  = $decoded->company_id;

try {
    switch ($method) {
        case 'GET':
            $reward_id = $_GET['reward_id'] ?? null;
            if ($reward_id) {
                getCatalogDetail($conn, $schema, $reward_id, $token_company);
            } else {
                getCatalog($conn, $schema, $token_company, $_GET);
            }
            break;
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            createCatalog($conn, $schema, $input, $token_username, $token_company);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateCatalog($conn, $schema, $input, $token_username, $token_company);
            break;
        case 'DELETE':
            $reward_id = $_GET['reward_id'] ?? null;
            deleteCatalog($conn, $schema, $reward_id, $token_company);
            break;
        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/reward/catalog.php',
                'method'          => $method,
                'error_message'   => 'Method Not Allowed',
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
            break;
    }
} catch (Exception $e) {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/reward/catalog.php',
        'method'          => $method,
        'error_message'   => $e->getMessage(),
        'user_identifier' => null,
        'company_id'      => null,
    ]);
    jsonResponse(500, 'Internal Server Error');
}

?>
