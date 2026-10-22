<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function createBonusSchema($conn, $schema, $input, $username, $company_id){
    $schema_name = $input['schema_name'] ?? null;
    $frequency = $input['frequency'] ?? null;
    $qty = $input['qty'] ?? null;
    $bonus_nominal = $input['bonus_nominal'] ?? null;

    $allowed_frequencies = ['daily', 'weekly', 'monthly'];

    if ($schema_name === null || $schema_name === "" || $qty === null || $qty < 1 || $bonus_nominal === null || $bonus_nominal < 1) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/bonus/schema.php',
            'method'        => 'POST',
            'error_message' => 'schema_name, qty, bonus_nominal are required',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(400, 'Schema name, qty, bonus nominal are required');
    }

    if (!in_array($frequency, $allowed_frequencies, true)) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/bonus/schema.php',
            'method'        => 'POST',
            'error_message' => 'frequency must be one of: daily, weekly, monthly',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(400, 'frequency must be one of: daily, weekly, monthly');
    }

    $qty = (int)$qty;
    $bonus_nominal = (int)$bonus_nominal;

    $check_stmt = $conn->prepare("SELECT schema_id FROM {$schema}.bonus_schema WHERE schema_name = ? OR qty = ?");
    $check_stmt->bind_param('si', $schema_name, $qty);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $check_stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/bonus/schema.php',
            'method'        => 'POST',
            'error_message' => 'Bonus schema has already exists in database',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(400, 'Bonus schema has already exists in database');
    }
    $check_stmt->close();

    $schema_id = "schema" . uniqid();
    $now = getCurrentDateTimeJakarta();
    $is_active = 1;

    $insert_stmt = $conn->prepare("INSERT INTO {$schema}.bonus_schema (schema_id, schema_name, qty, bonus_nominal, frequency, is_active, company_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insert_stmt->bind_param('ssiisisss', $schema_id, $schema_name, $qty, $bonus_nominal, $frequency, $is_active, $company_id, $username, $now);

    if ($insert_stmt->execute()) {
        $insert_stmt->close();
        jsonResponse(201, 'New bonus schema has been created successfully', ['schema_name' => $schema_name]);
    } else {
        $insert_stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/bonus/schema.php',
            'method'        => 'POST',
            'error_message' => 'Failed to create a new schema',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to create a new schema');
    }
}

function getAllBonusSchema($conn, $schema, $params, $company_id, $page = 1, $limit = 10){
    $limit = pageLimit($limit);
    $offset = ($page - 1) * $limit;
    $search = '%' . ($params ?? '') . '%';

    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM {$schema}.bonus_schema WHERE company_id = ? AND (schema_name LIKE ?)");
    $count_stmt->bind_param('ss', $company_id, $search);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $totalRow = $count_result->fetch_assoc();
    $total = $totalRow['total'];
    $count_stmt->close();

    $query_stmt = $conn->prepare("SELECT * FROM {$schema}.bonus_schema WHERE company_id = ? AND (schema_name LIKE ?) LIMIT ? OFFSET ?");
    $query_stmt->bind_param('ssii', $company_id, $search, $limit, $offset);
    $query_stmt->execute();
    $result = $query_stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $query_stmt->close();
        $response = [
            'data' => $data,
            'pagination' => [
                'total' => (int)$total,
                'page' => (int)$page,
                'limit' => (int)$limit,
                'total_pages' => ceil($total / $limit),
            ]
        ];
        jsonResponse(200, 'Bonus schema found', $response);
    } else {
        $query_stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/bonus/schema.php',
            'method'        => 'GET',
            'error_message' => 'Bonus schema not found',
            'user_identifier' => null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(404, 'Bonus schema not found');
    }
}

function getDetailBonusSchema($conn, $schema, $schema_id, $company_id){
    $stmt = $conn->prepare("SELECT * FROM {$schema}.bonus_schema WHERE schema_id = ? AND company_id = ?");
    $stmt->bind_param('ss', $schema_id, $company_id);
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
        jsonResponse(200, 'Bonus schema found', $response);
    } else {
        $stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/bonus/schema.php',
            'method'        => 'GET',
            'error_message' => 'Bonus schema not found',
            'user_identifier' => null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(404, 'Bonus schema not found');
    }
}

function updateBonusSchema($conn, $schema, $input, $username, $company_id){
    $schema_id = $input['schema_id'] ?? null;
    $schema_name = $input['schema_name'] ?? null;
    $frequency = $input['frequency'] ?? null;
    $qty = isset($input['qty']) ? (int)$input['qty'] : null;
    $bonus_nominal = isset($input['bonus_nominal']) ? (int)$input['bonus_nominal'] : null;
    $is_active = isset($input['is_active']) ? (int)$input['is_active'] : null;

    if (!$schema_id) {
        jsonResponse(400, 'schema_id is required');
    }

    $allowed_frequencies = ['daily', 'weekly', 'monthly'];
    if ($frequency !== null && !in_array($frequency, $allowed_frequencies, true)) {
        jsonResponse(400, 'frequency must be one of: daily, weekly, monthly');
    }

    $check_stmt = $conn->prepare("SELECT schema_id FROM {$schema}.bonus_schema WHERE schema_id = ? AND company_id = ?");
    $check_stmt->bind_param('ss', $schema_id, $company_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if (!$check_result || $check_result->num_rows === 0) {
        $check_stmt->close();
        jsonResponse(404, 'Bonus schema not found');
    }
    $check_stmt->close();

    $setFields = [];
    $setTypes = '';
    $setValues = [];

    if ($schema_name !== null) {
        $setFields[] = "schema_name = ?";
        $setTypes .= 's';
        $setValues[] = $schema_name;
    }
    if ($qty !== null) {
        $setFields[] = "qty = ?";
        $setTypes .= 'i';
        $setValues[] = $qty;
    }
    if ($bonus_nominal !== null) {
        $setFields[] = "bonus_nominal = ?";
        $setTypes .= 'i';
        $setValues[] = $bonus_nominal;
    }
    if ($frequency !== null) {
        $setFields[] = "frequency = ?";
        $setTypes .= 's';
        $setValues[] = $frequency;
    }
    if ($is_active !== null) {
        $setFields[] = "is_active = ?";
        $setTypes .= 'i';
        $setValues[] = $is_active;
    }

    if (empty($setFields)) {
        jsonResponse(400, 'No fields provided for update');
    }

    $setClause = implode(', ', $setFields);
    $allTypes = $setTypes . 'ss';
    $allValues = array_merge($setValues, [$schema_id, $company_id]);

    $update_stmt = $conn->prepare("UPDATE {$schema}.bonus_schema SET {$setClause} WHERE schema_id = ? AND company_id = ?");
    $update_stmt->bind_param($allTypes, ...$allValues);

    if ($update_stmt->execute()) {
        $update_stmt->close();
        jsonResponse(200, 'Bonus schema updated successfully');
    } else {
        $update_stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/bonus/schema.php',
            'method'        => 'PUT',
            'error_message' => 'Failed to update schema',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to update schema');
    }
}

function deleteBonusSchema($conn, $schema, $schema_id, $company_id){
    if (!$schema_id) {
        jsonResponse(400, 'schema_id is required');
    }

    $check_stmt = $conn->prepare("SELECT schema_id FROM {$schema}.bonus_schema WHERE schema_id = ? AND company_id = ?");
    $check_stmt->bind_param('ss', $schema_id, $company_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if (!$check_result || $check_result->num_rows === 0) {
        $check_stmt->close();
        jsonResponse(404, 'Bonus schema not found');
    }
    $check_stmt->close();

    $delete_stmt = $conn->prepare("DELETE FROM {$schema}.bonus_schema WHERE schema_id = ? AND company_id = ?");
    $delete_stmt->bind_param('ss', $schema_id, $company_id);

    if ($delete_stmt->execute()) {
        $delete_stmt->close();
        jsonResponse(200, 'Bonus schema deleted successfully');
    } else {
        $delete_stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/bonus/schema.php',
            'method'        => 'DELETE',
            'error_message' => 'Failed to delete schema',
            'user_identifier' => null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to delete schema');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$decoded = requireAuth();
$conn = DB::conn();
$schema = DB_SCHEMA;

$token_username = $decoded->username;
$token_company = $decoded->company_id;

$method = $_SERVER['REQUEST_METHOD'];

try {
    // The outlet a request is about: ?company_id / body company_id, else the token's own. An Owner may pick any
    // of their outlets, a Mitra only their own; only Owner / Mitra may change a scheme.
    $body = in_array($method, ['POST', 'PUT'], true) ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
    $target = $_GET['company_id'] ?? ($body['company_id'] ?? $token_company);
    if ($target !== $token_company) {
        requireCompanyAccess($conn, $decoded, $target);
    }
    if ($method !== 'GET') {
        requireManager($decoded);
    }
    $token_company = $target;

    switch($method){
        case 'POST':
            $input = $body;
            createBonusSchema($conn, $schema, $input, $token_username, $token_company);
            break;
        case 'GET':
            $params = $_GET['params'] ?? '';
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 10);
            $schema_id = $_GET['schema_id'] ?? null;
            if($schema_id != null){
                getDetailBonusSchema($conn, $schema, $schema_id, $token_company);
            } else {
                getAllBonusSchema($conn, $schema, $params, $token_company, $page, $limit);
            }
            break;
        case 'PUT':
            $input = $body;
            updateBonusSchema($conn, $schema, $input, $token_username, $token_company);
            break;
        case 'DELETE':
            $schema_id = $_GET['schema_id'] ?? null;
            deleteBonusSchema($conn, $schema, $schema_id, $token_company);
            break;
        default:
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 405,
                'endpoint'      => '/bonus/schema.php',
                'method'        => $method,
                'error_message' => 'Method Not Allowed',
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/bonus/schema.php',
        'method'        => $method ?? '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
