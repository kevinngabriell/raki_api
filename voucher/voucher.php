<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function createVoucher($conn, $schema, $input, $username, $company, $role){
    // ================= VALIDATION =================
    $requiredFields = ['voucher_code', 'voucher_name', 'discount_type', 'discount_value', 'usage_type', 'start_date', 'end_date', 'is_active' ];

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

    // enum validation
    if (!in_array($discount_type, ['nominal','percentage'])) {
        jsonResponse(400, 'Invalid discount_type');
    }

    if (!in_array($usage_type, ['one_time','multi_use'])) {
        jsonResponse(400, 'Invalid usage_type');
    }

    // numeric validation
    if ($discount_value <= 0) {
        jsonResponse(400, 'discount_value must be greater than 0');
    }

    if ($discount_type === 'percentage' && $discount_value > 100) {
        jsonResponse(400, 'percentage discount cannot be more than 100');
    }

    // date validation
    if (!strtotime($start_date) || !strtotime($end_date)) {
        jsonResponse(400, 'Invalid date format');
    }

    if ($start_date > $end_date) {
        jsonResponse(400, 'start_date cannot be greater than end_date');
    }

    $check_app_query = "SELECT * FROM {$schema}.voucher WHERE voucher_name = '$voucher_name' AND discount_value = '$discount_value'";
    $check_app_result = mysqli_query($conn, $check_app_query);

    if (mysqli_num_rows($check_app_result) > 0) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/voucher/voucher.php',
            'method'        => 'POST',
            'error_message' => 'Voucher has already exists in database !!',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Voucher has already exists in database !!');   
    } else {
        $voucher_id = "voucher" . uniqid();
        $now = getCurrentDateTimeJakarta();

        $insert_query = "INSERT INTO {$schema}.voucher (voucher_id, voucher_code, voucher_name, discount_type, discount_value, min_transaction, max_discount, usage_type, max_total_usage, start_date, end_date, is_active, company_id, created_by, created_at) VALUES ('$voucher_id', '$voucher_code', '$voucher_name', '$discount_type', $discount_value, $min_transaction, " . ($max_discount !== null ? $max_discount : "NULL") . ", '$usage_type', " . ($max_total_usage !== null ? $max_total_usage : "NULL") . ", '$start_date', '$end_date', $is_active, '$company', '$username', '$now')";

        if (mysqli_query($conn, $insert_query)) {
            jsonResponse(201, 'Voucher created successfully', [
                'voucher_id' => $voucher_id
            ]);
        } else {
            jsonResponse(500, 'Failed to create voucher: ' . mysqli_error($conn));
        }
    }

}

function getAllVoucher($conn, $params, $schema, $company, $page = 1, $limit = 10){

    $offset = ($page - 1) * $limit;

    $search = "";
    if (!empty($params['search'])) {
        $keyword = mysqli_real_escape_string($conn, $params['search']);
        $search = " AND (voucher_code LIKE '%$keyword%' OR voucher_name LIKE '%$keyword%')";
    }

    $companyFilter = !empty($company)
        ? "company_id = '" . mysqli_real_escape_string($conn, $company) . "'"
        : "1=1";

    $count_query = "SELECT COUNT(*) as total FROM {$schema}.voucher WHERE $companyFilter $search";
    $count_result = mysqli_query($conn, $count_query);
    $count_data = mysqli_fetch_assoc($count_result);
    $total_data = (int)$count_data['total'];

    $query = "SELECT * FROM {$schema}.voucher WHERE $companyFilter $search ORDER BY created_at DESC LIMIT $limit OFFSET $offset";

    $result = mysqli_query($conn, $query);

    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }

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

    $voucher_id = mysqli_real_escape_string($conn, $voucher_id);

    $companyFilter = !empty($company) ? "AND company_id = '" . mysqli_real_escape_string($conn, $company) . "'" : '';
    $query = "SELECT * FROM {$schema}.voucher WHERE voucher_id = '$voucher_id' $companyFilter LIMIT 1";

    $result = mysqli_query($conn, $query);

    if (mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Voucher not found');
    }

    $data = mysqli_fetch_assoc($result);

    jsonResponse(200, 'Voucher detail retrieved successfully', $data);
}

function updateVoucher($conn, $schema, $input, $company){

    $voucher_id = $input['voucher_id'] ?? null;

    if (!$voucher_id) {
        jsonResponse(400, 'voucher_id is required');
    }

    $voucher_id = mysqli_real_escape_string($conn, $voucher_id);

    $check_query = "SELECT * FROM {$schema}.voucher WHERE voucher_id = '$voucher_id' AND company_id = '$company'";
    $check_result = mysqli_query($conn, $check_query);

    if (mysqli_num_rows($check_result) === 0) {
        jsonResponse(404, 'Voucher not found');
    }

    $fields = [];

    if (isset($input['voucher_code'])) {
        $voucher_code = mysqli_real_escape_string($conn, trim($input['voucher_code']));
        $fields[] = "voucher_code = '$voucher_code'";
    }

    if (isset($input['voucher_name'])) {
        $voucher_name = mysqli_real_escape_string($conn, trim($input['voucher_name']));
        $fields[] = "voucher_name = '$voucher_name'";
    }

    if (isset($input['discount_type'])) {
        if (!in_array($input['discount_type'], ['nominal','percentage'])) {
            jsonResponse(400, 'Invalid discount_type');
        }
        $fields[] = "discount_type = '{$input['discount_type']}'";
    }

    if (isset($input['discount_value'])) {
        $discount_value = (int)$input['discount_value'];
        if ($discount_value <= 0) {
            jsonResponse(400, 'discount_value must be greater than 0');
        }
        $fields[] = "discount_value = $discount_value";
    }

    if (isset($input['min_transaction'])) {
        $fields[] = "min_transaction = " . (int)$input['min_transaction'];
    }

    if (array_key_exists('max_discount', $input)) {
        $fields[] = "max_discount = " . ($input['max_discount'] !== null ? (int)$input['max_discount'] : "NULL");
    }

    if (isset($input['usage_type'])) {
        if (!in_array($input['usage_type'], ['one_time','multi_use'])) {
            jsonResponse(400, 'Invalid usage_type');
        }
        $fields[] = "usage_type = '{$input['usage_type']}'";
    }

    if (array_key_exists('max_total_usage', $input)) {
        $fields[] = "max_total_usage = " . ($input['max_total_usage'] !== null ? (int)$input['max_total_usage'] : "NULL");
    }

    if (isset($input['start_date'])) {
        if (!strtotime($input['start_date'])) {
            jsonResponse(400, 'Invalid start_date');
        }
        $fields[] = "start_date = '{$input['start_date']}'";
    }

    if (isset($input['end_date'])) {
        if (!strtotime($input['end_date'])) {
            jsonResponse(400, 'Invalid end_date');
        }
        $fields[] = "end_date = '{$input['end_date']}'";
    }

    if (isset($input['is_active'])) {
        $is_active = (int)$input['is_active'];
        if (!in_array($is_active, [0,1])) {
            jsonResponse(400, 'is_active must be 0 or 1');
        }
        $fields[] = "is_active = $is_active";
    }

    if (empty($fields)) {
        jsonResponse(400, 'No fields provided for update');
    }

    $update_query = "UPDATE {$schema}.voucher SET " . implode(', ', $fields) . " WHERE voucher_id = '$voucher_id' AND company_id = '$company'";

    if (mysqli_query($conn, $update_query)) {
        jsonResponse(200, 'Voucher updated successfully');
    } else {
        jsonResponse(500, 'Failed to update voucher: ' . mysqli_error($conn));
    }
}

function deleteVoucher($conn, $schema, $voucher_id, $company){

    if (!$voucher_id) {
        jsonResponse(400, 'voucher_id is required');
    }

    $voucher_id = mysqli_real_escape_string($conn, $voucher_id);

    $check_query = "SELECT * FROM {$schema}.voucher WHERE voucher_id = '$voucher_id' AND company_id = '$company'";
    $check_result = mysqli_query($conn, $check_query);

    if (mysqli_num_rows($check_result) === 0) {
        jsonResponse(404, 'Voucher not found');
    }

    $delete_query = "DELETE FROM {$schema}.voucher WHERE voucher_id = '$voucher_id' AND company_id = '$company'";

    if (mysqli_query($conn, $delete_query)) {
        jsonResponse(200, 'Voucher deleted successfully');
    } else {
        jsonResponse(500, 'Failed to delete voucher: ' . mysqli_error($conn));
    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ── Public: GET (no auth required) ───────────────────────────────────────────
if ($method === 'GET') {
    $conn       = DB::conn();
    $schema     = DB_SCHEMA;
    $company_id = $_GET['company_id'] ?? null;
    $voucher_id = $_GET['voucher_id'] ?? null;

    if ($voucher_id) {
        getDetailVoucher($conn, $schema, $voucher_id, $company_id);
    } else {
        $page  = isset($_GET['page'])  ? (int)$_GET['page']  : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
        getAllVoucher($conn, $_GET, $schema, $company_id, $page, $limit);
    }
    exit;
}

// ── Protected: POST, PUT, DELETE (auth required) ──────────────────────────────
$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 401,
        'endpoint'        => '/voucher/voucher.php',
        'method'          => $method,
        'error_message'   => 'Authorization header not found',
        'user_identifier' => null,
        'company_id'      => null,
    ]);
    jsonResponse(401, 'Authorization header not found');
}

try {
    $token = str_replace('Bearer ', '', $headers['Authorization']);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn           = DB::conn();
    $schema         = DB_SCHEMA;
    $token_username = $decoded->username;
    $token_role     = $decoded->role;
    $token_company  = $decoded->company_id;

    switch ($method) {
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
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/voucher/voucher.php',
        'method'          => $method,
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>