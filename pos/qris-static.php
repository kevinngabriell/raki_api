<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function showStaticQRIS($conn, $company_id, $username){
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $query = "SELECT * FROM {$coreSchema}.app_static_payment WHERE company_id = ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/pos/qris-static.php',
            'method'        => '',
            'error_message' => 'Prepare query failed: ' . $conn->error,
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Database error');
    }
    $stmt->bind_param('s', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && mysqli_num_rows($result) > 0) {
        $data = mysqli_fetch_all($result, MYSQLI_ASSOC);
        jsonResponse(200, 'Static QRIS found', $data);
    } else {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/pos/qris-static.php',
            'method'        => '',
            'error_message' => 'Static QRIS not found',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(404, 'Static QRIS not found');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$decoded = requireAuth();

try {
    $conn = DB::conn();

    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'GET':
            $company_id = $_GET['company_id'] ?? null;

            if (!$company_id || trim($company_id) === '') {
                jsonResponse(400, 'company_id query parameter is required');
            }

            showStaticQRIS($conn, $company_id, $token_username);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }

} catch (Exception $e){
    logApiError($conn ?? null, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/pos/qris-static.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error');
}

?>