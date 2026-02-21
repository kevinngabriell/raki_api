<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

function createUOM($conn, $schema, $input, $token_username){
    if (!isset($input['uom_name'])) {
        jsonResponse(400, 'Missing required fields (uom_name)');
    }

    $uom_name = mysqli_real_escape_string($conn, $input['uom_name']);
    $now = getCurrentDateTimeJakarta();

    $check_app_query = "SELECT * FROM {$schema}.unit_of_measurement WHERE uom_name = '$uom_name'";
    $check_app_result = mysqli_query($conn, $check_app_query);

    if (mysqli_num_rows($check_app_result) > 0) {
        jsonResponse(400, 'Unit of measurement has already exists in database !!');    
    } else {
        $uomID = "uom" . uniqid();
        $now = getCurrentDateTimeJakarta();

        $category_query = "INSERT INTO {$schema}.unit_of_measurement (uom_id, uom_name, created_by, created_at) 
        VALUES ('$uomID', '$uom_name', '$token_username', '$now')";

        if (mysqli_query($conn, $category_query)) {
            jsonResponse(201, 'New unit of measurement has been created successfully', ['uom_name' => $uom_name]);
        } else {
            jsonResponse(500, 'Failed to create a new unit of measurement: ' . mysqli_error($conn));
        }
    }
}

function getAllUOM($conn, $schema, $params, $page = 1, $limit = 10){
    $offset = ($page - 1) * $limit;

    $countQuery = "SELECT COUNT(*) as total FROM {$schema}.unit_of_measurement WHERE (uom_name LIKE '%$params%')";
    $countResult = mysqli_query($conn, $countQuery);
    $totalRow = mysqli_fetch_assoc($countResult);
    $total = $totalRow['total'];

    $query = "SELECT uom_id, uom_name FROM {$schema}.unit_of_measurement WHERE (uom_name LIKE '%$params%') LIMIT $limit OFFSET $offset";
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
        jsonResponse(200, 'Unit of measurement found', $response);
    } else {
        jsonResponse(404, 'Unit of measurement not found');
    }
}

function getDetailUOM($conn, $schema, $uom_id){
    $query = "SELECT * FROM {$schema}.unit_of_measurement WHERE uom_id = '$uom_id'";
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
        jsonResponse(200, 'Unit of measurement found', $response);
    } else {
        jsonResponse(404, 'Unit of measurement not found');
    }
}

function updateUOM($conn, $schema, $input){
    if (!isset($input['uom_id']) || !isset($input['uom_name'])) {
        jsonResponse(400, 'Missing required fields (uom_id and uom_name)');
    }

    $uom_id = mysqli_real_escape_string($conn, $input['uom_id']);
    $uom_name = mysqli_real_escape_string($conn, $input['uom_name']);

    $query = "SELECT * FROM {$schema}.unit_of_measurement WHERE uom_id = '$uom_id'";
    $result = mysqli_query($conn, $query);

    if(mysqli_num_rows($result) > 0) {

        $updateQuery = "UPDATE {$schema}.unit_of_measurement SET uom_name = '$uom_name' WHERE uom_id = '$uom_id'";

        if (mysqli_query($conn, $updateQuery)) {
            jsonResponse(200, 'Unit of measurement updated successfully', ['uom_id' => $uom_id]);
        } else {
            jsonResponse(500, 'Failed to update unit of measurement');
        }

    } else {
        jsonResponse(404, 'Unit of measurement is not registered in systems');
    }

}

function deleteUOM($conn, $schema, $uom_id){
    if($uom_id === null || $uom_id === ''){
        jsonResponse(400, 'Missing required fields (uom_id)');
    }

    $query = "SELECT * FROM {$schema}.unit_of_measurement WHERE uom_id = '$uom_id'";
    $result = mysqli_query($conn, $query);

    if(mysqli_num_rows($result) > 0) {
        $query = "DELETE FROM {$schema}.unit_of_measurement WHERE uom_id = '$uom_id'";

        if (mysqli_query($conn, $query)) {
            jsonResponse(200, 'Unit of measurement deleted successfully');
        } else {
            jsonResponse(500, 'Failed to delete unit of measurement');
        }

    } else {
        jsonResponse(404, 'Unit of measurement is not registered in systems');
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
    $schema = DB_SCHEMA;

    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            createUOM($conn, $schema, $input, $token_username);
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $uom_id = $_GET['uom_id'] ?? null;
            if($uom_id != null){
                getDetailUOM($conn, $schema, $uom_id);
            } else {
                getAllUOM($conn, $schema, $params, $page, $limit);
            }
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateUOM($conn, $schema, $input);
            break;
        case 'DELETE':
            $uom_id = $_GET['uom_id'] ?? null;
            deleteUOM($conn, $schema, $uom_id);
            break;
    }

} catch (Exception $e){
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>