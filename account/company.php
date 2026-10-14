<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function getCompanyDetails($conn, $company_id, $username){
    $stmt = $conn->prepare("SELECT * FROM app_company WHERE company_id = ?");
    $stmt->bind_param('s', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_assoc();
        $response = [
            'data' => $data,
            'pagination' => [
                'total' => 1,
                'page' => 1,
                'limit' => 1,
                'total_pages' => 1,
            ]
        ];
        jsonResponse(200, 'Company found', $response);
    } else {
        logApiError($conn, [
            'error_level'   => 'warning',
            'http_status'   => 404,
            'endpoint'      => '/account/company.php',
            'method'        => 'GET',
            'error_message' => 'Company not found',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(404, 'Company not found');
    }
}

try {
    $decoded = requireAuth();
    $conn = DB::conn();
    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'GET':
            $company_id = $_GET['company_id'] ?? null;
            if (!$company_id) {
                jsonResponse(400, 'company_id is required');
            }
            getCompanyDetails($conn, $company_id, $token_username);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/account/company.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
