<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function createBonusSchema($conn, $schema, $input, $username, $company){
    $schema_name = $input['schema_name'];
    $frequency = $input['frequency'];
    $qty = $input['qty'];
    $bonus_nominal = $input['bonus_nominal'];

    if ($schema_name === null || $schema_name === "" || $qty === null || $qty < 1 || $bonus_nominal === null || $bonus_nominal < 1 ) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/bonus/schema.php',
            'method'        => 'POST',
            'error_message' => 'schema_name, qty, bonus_nominal are required',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Schema name, qty, bonus nominal are required');
    }

    $schema_name = mysqli_real_escape_string($conn, $schema_name);
    $qty = (int)$input['qty'];
    $bonus_nominal = (int)$input['bonus_nominal'];

    $check_app_query = "SELECT * FROM {$schema}.bonus_schema WHERE schema_name = '$schema_name' OR qty = '$qty'";
    $check_app_result = mysqli_query($conn, $check_app_query);

    if (mysqli_num_rows($check_app_result) > 0) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/bonus/schema.php',
            'method'        => 'POST',
            'error_message' => 'Bonus schema has already exists in database !!',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Bonus schema has already exists in database !!');    
    } else {
        $schema_id = "schema" . uniqid();
        $now = getCurrentDateTimeJakarta();

        $bonus_query = "INSERT INTO {$schema}.bonus_schema (schema_id, schema_name, qty, bonus_nominal, frequency, is_active, company_id, created_by, created_at) 
        VALUES ('$schema_id', '$schema_name', $qty, $bonus_nominal, '$frequency', 1, '$company', '$username', '$now')";

        if (mysqli_query($conn, $bonus_query)) {
            jsonResponse(201, 'New bonus schema has been created successfully', ['schema_name' => $schema_name]);
        } else {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/bonus/schema.php',
                'method'        => 'POST',
                'error_message' => 'Failed to create a new schema: ' . mysqli_error($conn),
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(500, 'Failed to create a new schema: ' . mysqli_error($conn));
        }
    }
}

function getAllBonusSchema($conn, $params, $schema, $page = 1, $limit = 10){
    $offset = ($page - 1) * $limit;

    $countQuery = "SELECT COUNT(*) as total FROM {$schema}.bonus_schema WHERE (schema_name LIKE '%$params%')";
    $countResult = mysqli_query($conn, $countQuery);
    $totalRow = mysqli_fetch_assoc($countResult);
    $total = $totalRow['total'];

    $query = "SELECT * FROM {$schema}.bonus_schema WHERE (schema_name LIKE '%$params%') LIMIT $limit OFFSET $offset";
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
        jsonResponse(200, 'Bonus schema found', $response);
    } else {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/bonus/schema.php',
            'method'        => 'GET',
            'error_message' => 'Bonus schema not found',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(404, 'Bonus schema not found');
    }
}

function getDetailBonusSchema($conn, $schema, $schema_id){
    $query = "SELECT * FROM {$schema}.bonus_schema WHERE schema_id = '$schema_id'";
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
        jsonResponse(200, 'Bonus schema found', $response);
    } else {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/bonus/schema.php',
            'method'        => 'GET',
            'error_message' => 'Bonus schema not found',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(404, 'Bonus schema not found');
    }
}

function updateBonusSchema($conn, $schema, $input, $username, $company){
    $schema_id = $input['schema_id'] ?? null;
    $schema_name = isset($input['schema_name']) ? mysqli_real_escape_string($conn, $input['schema_name']) : null;
    $frequency = $input['frequency'] ?? null;
    $qty = isset($input['qty']) ? (int)$input['qty'] : null;
    $bonus_nominal = isset($input['bonus_nominal']) ? (int)$input['bonus_nominal'] : null;
    $is_active = isset($input['is_active']) ? (int)$input['is_active'] : null;

    if (!$schema_id) {
        jsonResponse(400, 'schema_id is required');
    }

    $checkQuery = "SELECT * FROM {$schema}.bonus_schema WHERE schema_id = '$schema_id' AND company_id = '$company'";
    $checkResult = mysqli_query($conn, $checkQuery);

    if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
        jsonResponse(404, 'Bonus schema not found');
    }

    $fields = [];

    if ($schema_name !== null) {
        $fields[] = "schema_name = '$schema_name'";
    }

    if ($qty !== null) {
        $fields[] = "qty = $qty";
    }

    if ($bonus_nominal !== null) {
        $fields[] = "bonus_nominal = $bonus_nominal";
    }

    if ($frequency !== null) {
        $fields[] = "frequency = '$frequency'";
    }

    if ($is_active !== null) {
        $fields[] = "is_active = $is_active";
    }

    if (empty($fields)) {
        jsonResponse(400, 'No fields provided for update');
    }

    $updateQuery = "UPDATE {$schema}.bonus_schema 
                    SET " . implode(', ', $fields) . "
                    WHERE schema_id = '$schema_id' AND company_id = '$company'";

    if (mysqli_query($conn, $updateQuery)) {
        jsonResponse(200, 'Bonus schema updated successfully');
    } else {
        jsonResponse(500, 'Failed to update schema: ' . mysqli_error($conn));
    }
}

function deleteBonusSchema($conn, $schema, $schema_id, $company){
    if (!$schema_id) {
        jsonResponse(400, 'schema_id is required');
    }

    $checkQuery = "SELECT * FROM {$schema}.bonus_schema WHERE schema_id = '$schema_id' AND company_id = '$company'";
    $checkResult = mysqli_query($conn, $checkQuery);

    if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
        jsonResponse(404, 'Bonus schema not found');
    }

    $deleteQuery = "DELETE FROM {$schema}.bonus_schema WHERE schema_id = '$schema_id' AND company_id = '$company'";

    if (mysqli_query($conn, $deleteQuery)) {
        jsonResponse(200, 'Bonus schema deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete schema: ' . mysqli_error($conn));
    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 401,
        'endpoint'      => '/menu/category.php',
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
    $schema = DB_SCHEMA;

    $token_username = $decoded->username;
    $token_company = $decoded->company_id;

    $method = $_SERVER['REQUEST_METHOD'];
    
    switch($method){
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            createBonusSchema($conn, $schema, $input, $token_username, $token_company);
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $schema_id = $_GET['schema_id'] ?? null;
            if($schema_id != null){
                getDetailBonusSchema($conn, $schema, $schema_id);
            } else {
                getAllBonusSchema($conn, $params, $schema, $page, $limit);
            }
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateBonusSchema($conn, $schema, $input, $token_username, $token_company);
            break;
        case 'DELETE':
            $schema_id = $_GET['schema_id'] ?? null;
            deleteBonusSchema($conn, $schema, $schema_id, $token_company);
            break;
    }

} catch (Exception $e){
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/account/register.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>