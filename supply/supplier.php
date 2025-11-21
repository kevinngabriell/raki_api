<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

function createSupplier($conn, $input, $token_username){
    if (!isset($input['supplier_name']) || !isset($input['contact_person']) || !isset($input['company_id'])) {
        jsonResponse(400, 'Missing required fields (supplier_name, contact_person, and company_id)');
    }

    $supplier_name = $input['supplier_name'];
    $contact_person = $input['contact_person'];
    $phone = $input['phone'];
    $email = $input['email'];
    $address = $input['address'];
    $company_id = $input['company_id'];
    $is_active = $input['is_active'];

    $supplier_name = mysqli_real_escape_string($conn, $input['supplier_name']);
    $contact_person = mysqli_real_escape_string($conn, $input['contact_person']);
    $phone = mysqli_real_escape_string($conn, $input['phone']);
    $email = mysqli_real_escape_string($conn, $input['email']);
    $address = mysqli_real_escape_string($conn, $input['address']);
    $company_id = mysqli_real_escape_string($conn, $input['company_id']);
    $is_active = $is_active ? 1 : 0;

    $check_app_query = "SELECT * FROM raki_dev.supplier WHERE supplier_name = '$supplier_name' AND company_id = '$company_id'";
    $check_app_result = mysqli_query($conn, $check_app_query);

    if (mysqli_num_rows($check_app_result) > 0) {
        jsonResponse(400, 'Supplier data has already exists in database !!');    
    } else {
        $supplier_id = "supplier" . uniqid();
        $now = getCurrentDateTimeJakarta();

        $category_query = "INSERT INTO raki_dev.supplier (supplier_id, supplier_name, contact_person, phone, email, address, company_id, is_active, created_at, created_by) 
        VALUES ('$supplier_id', '$supplier_name', '$contact_person', '$phone', '$email', '$address', '$company_id', '$is_active', '$now','$token_username')";

        if (mysqli_query($conn, $category_query)) {
            jsonResponse(201, 'New supplier has been created successfully', ['ingredients' => $supplier_name]);
        } else {
            jsonResponse(500, 'Failed to create a new supplier: ' . mysqli_error($conn));
        }

    }
}

function getAllSupplier($conn, $params, $page = 1, $limit = 10){
    $offset = ($page - 1) * $limit;

    $countQuery = "SELECT COUNT(*) as total FROM raki_dev.supplier WHERE (supplier_name LIKE '%$params%')";
    $countResult = mysqli_query($conn, $countQuery);
    $totalRow = mysqli_fetch_assoc($countResult);
    $total = $totalRow['total'];

    $query = "SELECT supplier_id, supplier_name, contact_person, phone, is_active FROM raki_dev.supplier WHERE (supplier_name LIKE '%$params%') LIMIT $limit OFFSET $offset";
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
        jsonResponse(200, 'Supplier found', $response);
    } else {
        jsonResponse(404, 'Supplier not found');
    }
}

function getDetailSupplier($conn, $supplier_id){
    $query = "SELECT supplier_id, supplier_name, contact_person, phone, email, address, is_active
        FROM raki_dev.supplier 
        WHERE supplier_id = '$supplier_id'";
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
        jsonResponse(200, 'Supplier found', $response);
    } else {
        jsonResponse(404, 'Supplier not found');
    }
}

function updateSupplier($conn, $input){
    if (!isset($input['supplier_id'])) {
        jsonResponse(400, 'Missing required fields (supplier_id)');
    }

    $supplier_id = mysqli_real_escape_string($conn, $input['supplier_id']);

    $query = "SELECT * FROM raki_dev.supplier WHERE supplier_id = '$supplier_id'";
    $result = mysqli_query($conn, $query);

    $updates = [];
    if (isset($input['supplier_name'])) {
        $updates[] = "supplier_name = '" . mysqli_real_escape_string($conn, $input['supplier_name']) . "'";
    }
    if (isset($input['contact_person'])) {
        $updates[] = "contact_person = '" . mysqli_real_escape_string($conn, $input['contact_person']) . "'";
    }
    if (isset($input['phone'])) {
        $updates[] = "phone = '" . mysqli_real_escape_string($conn, $input['phone']) . "'";
    }
    if (isset($input['email'])) {
        $updates[] = "email = '" . mysqli_real_escape_string($conn, $input['email']) . "'";
    }
    if (isset($input['address'])) {
        $updates[] = "address = '" . mysqli_real_escape_string($conn, $input['address']) . "'";
    }
    if (isset($input['company_id'])) {
        $updates[] = "company_id = '" . mysqli_real_escape_string($conn, $input['company_id']) . "'";
    }

    if (empty($updates)) {
        jsonResponse(400, 'No fields provided for update');
    }

    if(mysqli_num_rows($result) > 0) {
        $updateQuery = "UPDATE raki_dev.supplier SET " . implode(', ', $updates) . " WHERE supplier_id = '$supplier_id'";

        if (mysqli_query($conn, $updateQuery)) {
            jsonResponse(200, 'Supplier updated successfully', ['supplier_id' => $supplier_id]);
        } else {
            jsonResponse(500, 'Failed to update supplier');
        }
    } else {
        jsonResponse(404, message: 'Supplier not registered in systems');
    }

}

function deleteSupplier($conn, $supplier_id){
    if($supplier_id === null || $supplier_id === ''){
        jsonResponse(400, 'Missing required fields (supplier_id)');
    }

    $query = "SELECT * FROM raki_dev.supplier WHERE supplier_id = '$supplier_id'";
    $result = mysqli_query($conn, $query);

    if(mysqli_num_rows($result) > 0) {
        $query = "DELETE FROM raki_dev.supplier WHERE supplier_id = '$supplier_id'";

        if (mysqli_query($conn, $query)) {
            jsonResponse(200, 'Supplier deleted successfully');
        } else {
            jsonResponse(500, 'Failed to delete supplier');
        }

    } else {
        jsonResponse(404, 'Supplier is not registered in systems');
    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
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
    
    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            createSupplier($conn, $input, $token_username);
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $supplier_id = $_GET['supplier_id'] ?? null;
            if($supplier_id != null){
                getDetailSupplier($conn, $supplier_id);
            } else {
                getAllSupplier($conn, $params, $page, $limit);
            }
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateSupplier($conn, $input);
            break;
        case 'DELETE':
            $supplier_id = $_GET['supplier_id'] ?? null;
            deleteSupplier($conn, $supplier_id);
            break;
    }

} catch (Exception $e){
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>