<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function createVoucher($conn, $schema, $input, $username, $company, $role){
    $requiredFields = ['voucher_code', 'voucher_name', 'discount_type', 'discount_value', 'usage_type', 'start_date', 'end_date', 'is_active'];

    foreach ($requiredFields as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $voucher_code = trim($input['voucher_code']);
    $voucher_name = trim($input['voucher_name']);
    $discount_type = $input['discount_type'];
    $discount_value = (int)$input['discount_value'];
    $min_transaction = isset($input['min_transaction']) ? (int)$input['min_transaction'] : 0;
    $max_discount = isset($input['max_discount']) ? (int)$input['max_discount'] : null;
    $usage_type = $input['usage_type'];
    $max_total_usage = isset($input['max_total_usage']) ? (int)$input['max_total_usage'] : null;
    $start_date = $input['start_date'];
    $end_date = $input['end_date'];
    $is_active = (int)$input['is_active'];

    if (!in_array($is_active, [0,1])) {
        jsonResponse(400, 'is_active must be 0 or 1');
    }

    if (!in_array($discount_type, ['nominal','percentage'])) {
        jsonResponse(400, 'Invalid discount_type');
    }

    if (!in_array($usage_type, ['one_time','multi_use'])) {
        jsonResponse(400, 'Invalid usage_type');
    }

    if ($discount_value <= 0) {
        jsonResponse(400, 'discount_value must be greater than 0');
    }

    if ($discount_type === 'percentage' && $discount_value > 100) {
        jsonResponse(400, 'percentage discount cannot be more than 100');
    }

    if (!strtotime($start_date) || !strtotime($end_date)) {
        jsonResponse(400, 'Invalid date format');
    }

    if ($start_date > $end_date) {
        jsonResponse(400, 'start_date cannot be greater than end_date');
    }

    $checkStmt = $conn->prepare(
        "SELECT * FROM {$schema}.voucher WHERE voucher_name = ? AND discount_value = ?"
    );
    if (!$checkStmt) jsonResponse(500, 'Internal Server Error');
    $checkStmt->bind_param('si', $voucher_name, $discount_value);
    $checkStmt->execute();
    $checkRes = $checkStmt->get_result();

    if ($checkRes && $checkRes->num_rows > 0) {
        $checkStmt->close();
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 400,
            'endpoint'        => '/voucher/voucher.php',
            'method'          => 'POST',
            'error_message'   => 'Voucher has already exists in database !!',
            'user_identifier' => $username ?? null,
            'company_id'      => $company ?? null,
        ]);
        jsonResponse(400, 'Voucher has already exists in database !!');
    }
    $checkStmt->close();

    $voucher_id = "voucher" . uniqid();
    $now = getCurrentDateTimeJakarta();

    $stmt = $conn->prepare(
        "INSERT INTO {$schema}.voucher (voucher_id, voucher_code, voucher_name, discount_type, discount_value, min_transaction, max_discount, usage_type, max_total_usage, start_date, end_date, is_active, company_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) jsonResponse(500, 'Internal Server Error');
    $stmt->bind_param(
        'ssssiiisississs',
        $voucher_id,
        $voucher_code,
        $voucher_name,
        $discount_type,
        $discount_value,
        $min_transaction,
        $max_discount,
        $usage_type,
        $max_total_usage,
        $start_date,
        $end_date,
        $is_active,
        $company,
        $username,
        $now
    );
    if (!$stmt->execute()) {
        $stmt->close();
        jsonResponse(500, 'Failed to create voucher');
    }
    $stmt->close();

    jsonResponse(201, 'Voucher created successfully', [
        'voucher_id' => $voucher_id
    ]);
}

function getAllVoucher($conn, $params, $schema, $company, $page = 1, $limit = 10){
    $offset = ($page - 1) * $limit;

    $searchSql = '';
    $types     = 's';
    $bindVals  = [$company];

    if (!empty($params['search'])) {
        $kw = '%' . $params['search'] . '%';
        $searchSql = " AND (voucher_code LIKE ? OR voucher_name LIKE ?)";
        $types .= 'ss';
        $bindVals[] = $kw;
        $bindVals[] = $kw;
    }

    $whereClause = "WHERE company_id = ? $searchSql";

    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM {$schema}.voucher $whereClause");
    if (!$countStmt) jsonResponse(500, 'Internal Server Error');
    $countStmt->bind_param($types, ...$bindVals);
    $countStmt->execute();
    $countRes = $countStmt->get_result();
    $total_data = (int)$countRes->fetch_assoc()['total'];
    $countStmt->close();

    $dataTypes = $types . 'ii';
    $dataVals  = array_merge($bindVals, [$limit, $offset]);

    $dataStmt = $conn->prepare("SELECT * FROM {$schema}.voucher $whereClause ORDER BY created_at DESC LIMIT ? OFFSET ?");
    if (!$dataStmt) jsonResponse(500, 'Internal Server Error');
    $dataStmt->bind_param($dataTypes, ...$dataVals);
    $dataStmt->execute();
    $result = $dataStmt->get_result();

    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }
    $dataStmt->close();

    jsonResponse(200, 'Voucher list retrieved successfully', [
        'data' => $data,
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_data' => $total_data,
            'total_page' => ceil($total_data / $limit)
        ]
    ]);
}

function getDetailVoucher($conn, $schema, $voucher_id, $company){
    if (!$voucher_id) {
        jsonResponse(400, 'voucher_id is required');
    }

    $stmt = $conn->prepare(
        "SELECT * FROM {$schema}.voucher WHERE voucher_id = ? AND company_id = ? LIMIT 1"
    );
    if (!$stmt) jsonResponse(500, 'Internal Server Error');
    $stmt->bind_param('ss', $voucher_id, $company);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        jsonResponse(404, 'Voucher not found');
    }

    $data = $result->fetch_assoc();
    $stmt->close();
    jsonResponse(200, 'Voucher detail retrieved successfully', $data);
}

function updateVoucher($conn, $schema, $input, $company){
    $voucher_id = $input['voucher_id'] ?? null;

    if (!$voucher_id) {
        jsonResponse(400, 'voucher_id is required');
    }

    $checkStmt = $conn->prepare(
        "SELECT * FROM {$schema}.voucher WHERE voucher_id = ? AND company_id = ?"
    );
    if (!$checkStmt) jsonResponse(500, 'Internal Server Error');
    $checkStmt->bind_param('ss', $voucher_id, $company);
    $checkStmt->execute();
    $checkRes = $checkStmt->get_result();

    if ($checkRes->num_rows === 0) {
        $checkStmt->close();
        jsonResponse(404, 'Voucher not found');
    }
    $checkStmt->close();

    $setParts = [];
    $types    = '';
    $values   = [];

    if (isset($input['voucher_code'])) {
        $setParts[] = "voucher_code = ?";
        $types .= 's';
        $values[] = trim($input['voucher_code']);
    }

    if (isset($input['voucher_name'])) {
        $setParts[] = "voucher_name = ?";
        $types .= 's';
        $values[] = trim($input['voucher_name']);
    }

    if (isset($input['discount_type'])) {
        if (!in_array($input['discount_type'], ['nominal','percentage'])) {
            jsonResponse(400, 'Invalid discount_type');
        }
        $setParts[] = "discount_type = ?";
        $types .= 's';
        $values[] = $input['discount_type'];
    }

    if (isset($input['discount_value'])) {
        $discount_value = (int)$input['discount_value'];
        if ($discount_value <= 0) {
            jsonResponse(400, 'discount_value must be greater than 0');
        }
        $setParts[] = "discount_value = ?";
        $types .= 'i';
        $values[] = $discount_value;
    }

    if (isset($input['min_transaction'])) {
        $setParts[] = "min_transaction = ?";
        $types .= 'i';
        $values[] = (int)$input['min_transaction'];
    }

    if (array_key_exists('max_discount', $input)) {
        $setParts[] = "max_discount = ?";
        $types .= 'i';
        $values[] = $input['max_discount'] !== null ? (int)$input['max_discount'] : null;
    }

    if (isset($input['usage_type'])) {
        if (!in_array($input['usage_type'], ['one_time','multi_use'])) {
            jsonResponse(400, 'Invalid usage_type');
        }
        $setParts[] = "usage_type = ?";
        $types .= 's';
        $values[] = $input['usage_type'];
    }

    if (array_key_exists('max_total_usage', $input)) {
        $setParts[] = "max_total_usage = ?";
        $types .= 'i';
        $values[] = $input['max_total_usage'] !== null ? (int)$input['max_total_usage'] : null;
    }

    if (isset($input['start_date'])) {
        if (!strtotime($input['start_date'])) {
            jsonResponse(400, 'Invalid start_date');
        }
        $setParts[] = "start_date = ?";
        $types .= 's';
        $values[] = $input['start_date'];
    }

    if (isset($input['end_date'])) {
        if (!strtotime($input['end_date'])) {
            jsonResponse(400, 'Invalid end_date');
        }
        $setParts[] = "end_date = ?";
        $types .= 's';
        $values[] = $input['end_date'];
    }

    if (isset($input['is_active'])) {
        $is_active = (int)$input['is_active'];
        if (!in_array($is_active, [0,1])) {
            jsonResponse(400, 'is_active must be 0 or 1');
        }
        $setParts[] = "is_active = ?";
        $types .= 'i';
        $values[] = $is_active;
    }

    if (empty($setParts)) {
        jsonResponse(400, 'No fields provided for update');
    }

    $types .= 'ss';
    $values[] = $voucher_id;
    $values[] = $company;

    $setClause = implode(', ', $setParts);
    $updateSql = "UPDATE {$schema}.voucher SET {$setClause} WHERE voucher_id = ? AND company_id = ?";

    $updateStmt = $conn->prepare($updateSql);
    if (!$updateStmt) jsonResponse(500, 'Internal Server Error');
    $updateStmt->bind_param($types, ...$values);
    if (!$updateStmt->execute()) {
        $updateStmt->close();
        jsonResponse(500, 'Failed to update voucher');
    }
    $updateStmt->close();

    jsonResponse(200, 'Voucher updated successfully');
}

function deleteVoucher($conn, $schema, $voucher_id, $company){
    if (!$voucher_id) {
        jsonResponse(400, 'voucher_id is required');
    }

    $checkStmt = $conn->prepare(
        "SELECT * FROM {$schema}.voucher WHERE voucher_id = ? AND company_id = ?"
    );
    if (!$checkStmt) jsonResponse(500, 'Internal Server Error');
    $checkStmt->bind_param('ss', $voucher_id, $company);
    $checkStmt->execute();
    $checkRes = $checkStmt->get_result();

    if ($checkRes->num_rows === 0) {
        $checkStmt->close();
        jsonResponse(404, 'Voucher not found');
    }
    $checkStmt->close();

    $deleteStmt = $conn->prepare(
        "DELETE FROM {$schema}.voucher WHERE voucher_id = ? AND company_id = ?"
    );
    if (!$deleteStmt) jsonResponse(500, 'Internal Server Error');
    $deleteStmt->bind_param('ss', $voucher_id, $company);
    if (!$deleteStmt->execute()) {
        $deleteStmt->close();
        jsonResponse(500, 'Failed to delete voucher');
    }
    $deleteStmt->close();

    jsonResponse(200, 'Voucher deleted successfully');
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
$token_role     = $decoded->role;
$token_company  = $decoded->company_id;

try {
    switch ($method) {
        case 'GET':
            $voucher_id = $_GET['voucher_id'] ?? null;
            if ($voucher_id) {
                getDetailVoucher($conn, $schema, $voucher_id, $token_company);
            } else {
                $page  = isset($_GET['page'])  ? (int)$_GET['page']  : 1;
                $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
                getAllVoucher($conn, $_GET, $schema, $token_company, $page, $limit);
            }
            break;
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            createVoucher($conn, $schema, $input, $token_username, $token_company, $token_role);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateVoucher($conn, $schema, $input, $token_company);
            break;
        case 'DELETE':
            $voucher_id = $_GET['voucher_id'] ?? null;
            deleteVoucher($conn, $schema, $voucher_id, $token_company);
            break;
        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/voucher/voucher.php',
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
        'endpoint'        => '/voucher/voucher.php',
        'method'          => $method,
        'error_message'   => $e->getMessage(),
        'user_identifier' => null,
        'company_id'      => null,
    ]);
    jsonResponse(500, 'Internal Server Error');
}

?>
