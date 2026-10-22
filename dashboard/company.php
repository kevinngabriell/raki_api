<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function getAllCompany($conn){
    $stmt = $conn->prepare("SELECT company_id, company_name, EXISTS (SELECT 1 FROM app_user u WHERE u.company_id = app_company.company_id AND u.app_role_id = '" . ACCESS_ROLE_MITRA . "') AS is_mitra FROM app_company WHERE app_id = '06660e87-37e7-491b-92c3-c772130eb57c' AND company_id != 'company691b31b41ea7b' AND status = 'active'");

    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/company.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement',
            'user_identifier' => null,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Failed to prepare statement');
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $data = array_map(fn($r) => ['is_mitra' => (bool)$r['is_mitra']] + $r, $result->fetch_all(MYSQLI_ASSOC));
        $stmt->close();
        jsonResponse(200, 'Company found', ['data' => $data]);
    } else {
        $stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/dashboard/company.php',
            'method'        => 'GET',
            'error_message' => 'Company not found',
            'user_identifier' => null,
            'company_id'      => null,
        ]);
        jsonResponse(404, 'Company not found');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $decoded = requireAuth();

    $conn = DB::conn();

    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'GET':
            getAllCompany($conn);
            break;
        default:
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 405,
                'endpoint'      => '/dashboard/company.php',
                'method'        => $method,
                'error_message' => 'Method Not Allowed',
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/dashboard/company.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => null,
        'company_id'      => null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>