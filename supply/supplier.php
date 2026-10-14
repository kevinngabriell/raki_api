<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function createSupplier($conn, $schema, $input, $token_username, $company_id){
    if (!isset($input['supplier_name']) || !isset($input['contact_person'])) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/supply/supplier.php',
            'method'        => 'POST',
            'error_message' => 'Missing required fields (supplier_name and contact_person)',
            'user_identifier' => $token_username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(400, 'Missing required fields (supplier_name and contact_person)');
    }

    $supplier_name = $input['supplier_name'];
    $contact_person = $input['contact_person'];
    $phone = $input['phone'] ?? null;
    $email = $input['email'] ?? null;
    $address = $input['address'] ?? null;
    $is_active = isset($input['is_active']) ? ($input['is_active'] ? 1 : 0) : 1;

    $check_stmt = $conn->prepare("SELECT supplier_id FROM {$schema}.supplier WHERE supplier_name = ? AND company_id = ?");
    $check_stmt->bind_param('ss', $supplier_name, $company_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $check_stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/supply/supplier.php',
            'method'        => 'POST',
            'error_message' => 'Supplier data has already exists in database',
            'user_identifier' => $token_username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(400, 'Supplier data has already exists in database');
    }
    $check_stmt->close();

    $supplier_id = "supplier" . uniqid();
    $now = getCurrentDateTimeJakarta();

    $insert_stmt = $conn->prepare("INSERT INTO {$schema}.supplier (supplier_id, supplier_name, contact_person, phone, email, address, company_id, is_active, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $insert_stmt->bind_param('ssssssiiss', $supplier_id, $supplier_name, $contact_person, $phone, $email, $address, $company_id, $is_active, $now, $token_username);

    if ($insert_stmt->execute()) {
        $insert_stmt->close();
        jsonResponse(201, 'New supplier has been created successfully', ['supplier_name' => $supplier_name]);
    } else {
        $insert_stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/supply/supplier.php',
            'method'        => 'POST',
            'error_message' => 'Failed to create a new supplier',
            'user_identifier' => $token_username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to create a new supplier');
    }
}

function getAllSupplier($conn, $schema, $params, $company_id, $page = 1, $limit = 10){
    $offset = ($page - 1) * $limit;
    $search = '%' . ($params ?? '') . '%';

    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM {$schema}.supplier WHERE company_id = ? AND (supplier_name LIKE ?)");
    $count_stmt->bind_param('ss', $company_id, $search);
    $count_stmt->execute();
    $count_result = $count_stmt->get_result();
    $totalRow = $count_result->fetch_assoc();
    $total = $totalRow['total'];
    $count_stmt->close();

    $query_stmt = $conn->prepare("SELECT supplier_id, supplier_name, contact_person, phone, is_active FROM {$schema}.supplier WHERE company_id = ? AND (supplier_name LIKE ?) LIMIT ? OFFSET ?");
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
        jsonResponse(200, 'Supplier found', $response);
    } else {
        $query_stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/supply/supplier.php',
            'method'        => 'GET',
            'error_message' => 'Supplier not found',
            'user_identifier' => null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(404, 'Supplier not found');
    }
}

function getDetailSupplier($conn, $schema, $supplier_id, $company_id){
    $stmt = $conn->prepare("SELECT supplier_id, supplier_name, contact_person, phone, email, address, is_active FROM {$schema}.supplier WHERE supplier_id = ? AND company_id = ?");
    $stmt->bind_param('ss', $supplier_id, $company_id);
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
        jsonResponse(200, 'Supplier found', $response);
    } else {
        $stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/supply/supplier.php',
            'method'        => 'GET',
            'error_message' => 'Supplier not found',
            'user_identifier' => null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(404, 'Supplier not found');
    }
}

function updateSupplier($conn, $schema, $input, $company_id){
    if (!isset($input['supplier_id'])) {
        jsonResponse(400, 'Missing required fields (supplier_id)');
    }

    $supplier_id = $input['supplier_id'];

    $check_stmt = $conn->prepare("SELECT supplier_id FROM {$schema}.supplier WHERE supplier_id = ? AND company_id = ?");
    $check_stmt->bind_param('ss', $supplier_id, $company_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if (!$check_result || $check_result->num_rows === 0) {
        $check_stmt->close();
        jsonResponse(404, 'Supplier not found');
    }
    $check_stmt->close();

    $setFields = [];
    $setTypes = '';
    $setValues = [];

    if (isset($input['supplier_name'])) {
        $setFields[] = "supplier_name = ?";
        $setTypes .= 's';
        $setValues[] = $input['supplier_name'];
    }
    if (isset($input['contact_person'])) {
        $setFields[] = "contact_person = ?";
        $setTypes .= 's';
        $setValues[] = $input['contact_person'];
    }
    if (isset($input['phone'])) {
        $setFields[] = "phone = ?";
        $setTypes .= 's';
        $setValues[] = $input['phone'];
    }
    if (isset($input['email'])) {
        $setFields[] = "email = ?";
        $setTypes .= 's';
        $setValues[] = $input['email'];
    }
    if (isset($input['address'])) {
        $setFields[] = "address = ?";
        $setTypes .= 's';
        $setValues[] = $input['address'];
    }
    if (isset($input['is_active'])) {
        $setFields[] = "is_active = ?";
        $setTypes .= 'i';
        $setValues[] = $input['is_active'] ? 1 : 0;
    }

    if (empty($setFields)) {
        jsonResponse(400, 'No fields provided for update');
    }

    $setClause = implode(', ', $setFields);
    $allTypes = $setTypes . 'ss';
    $allValues = array_merge($setValues, [$supplier_id, $company_id]);

    $update_stmt = $conn->prepare("UPDATE {$schema}.supplier SET {$setClause} WHERE supplier_id = ? AND company_id = ?");
    $update_stmt->bind_param($allTypes, ...$allValues);

    if ($update_stmt->execute()) {
        $update_stmt->close();
        jsonResponse(200, 'Supplier updated successfully', ['supplier_id' => $supplier_id]);
    } else {
        $update_stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/supply/supplier.php',
            'method'        => 'PUT',
            'error_message' => 'Failed to update supplier',
            'user_identifier' => null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to update supplier');
    }
}

function deleteSupplier($conn, $schema, $supplier_id, $company_id){
    if ($supplier_id === null || $supplier_id === '') {
        jsonResponse(400, 'Missing required fields (supplier_id)');
    }

    $check_stmt = $conn->prepare("SELECT supplier_id FROM {$schema}.supplier WHERE supplier_id = ? AND company_id = ?");
    $check_stmt->bind_param('ss', $supplier_id, $company_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if (!$check_result || $check_result->num_rows === 0) {
        $check_stmt->close();
        jsonResponse(404, 'Supplier not found');
    }
    $check_stmt->close();

    $delete_stmt = $conn->prepare("DELETE FROM {$schema}.supplier WHERE supplier_id = ? AND company_id = ?");
    $delete_stmt->bind_param('ss', $supplier_id, $company_id);

    if ($delete_stmt->execute()) {
        $delete_stmt->close();
        jsonResponse(200, 'Supplier deleted successfully');
    } else {
        $delete_stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/supply/supplier.php',
            'method'        => 'DELETE',
            'error_message' => 'Failed to delete supplier',
            'user_identifier' => null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to delete supplier');
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
    switch($method){
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            createSupplier($conn, $schema, $input, $token_username, $token_company);
            break;
        case 'GET':
            $params = $_GET['params'] ?? '';
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 10);
            $supplier_id = $_GET['supplier_id'] ?? null;
            if($supplier_id != null){
                getDetailSupplier($conn, $schema, $supplier_id, $token_company);
            } else {
                getAllSupplier($conn, $schema, $params, $token_company, $page, $limit);
            }
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateSupplier($conn, $schema, $input, $token_company);
            break;
        case 'DELETE':
            $supplier_id = $_GET['supplier_id'] ?? null;
            deleteSupplier($conn, $schema, $supplier_id, $token_company);
            break;
        default:
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 405,
                'endpoint'      => '/supply/supplier.php',
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
        'endpoint'      => '/supply/supplier.php',
        'method'        => $method ?? '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
