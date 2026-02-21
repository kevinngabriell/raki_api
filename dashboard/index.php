<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function getDashboard($conn, $schema, $company_id, $username){
    //Check is company id parameter exists or not
    if (!$company_id) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/dashboard/index.php',
            'method'        => 'GET',
            'error_message' => 'Company ID parameters is required',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(400, 'Company ID parameters is required');
    }

    // Period: current month [start_month, next_month)
    $start_month = date('Y-m-01');
    $next_month  = date('Y-m-01', strtotime('+1 month', strtotime($start_month)));

    // 1. Revenue this month
    $sql1 = "SELECT COALESCE(SUM(t.total_amount),0) AS revenue_this_month FROM {$schema}.transaction t WHERE t.company_id = ? AND t.transaction_date >= ? AND t.transaction_date < ?";
    $stmt1 = $conn->prepare($sql1);

    if(!$stmt1){ 
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/index.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for revenue this month : ' + $conn-> error,
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to prepare statement', ['error'=>$conn->error]); 
    }

    $stmt1->bind_param('sss', $company_id, $start_month, $next_month);
    $stmt1->execute();
    $rev = ($stmt1->get_result()->fetch_assoc()['revenue_this_month'] ?? 0) * 1;

    // 2. Total cups this month
    $sql2 = "SELECT COALESCE(SUM(td.quantity),0) AS cups_this_month FROM {$schema}.transaction t JOIN {$schema}.transaction_detail td ON td.transaction_id = t.transaction_id WHERE t.company_id = ? AND t.transaction_date >= ? AND t.transaction_date < ?";
    $stmt2 = $conn->prepare($sql2);

    if(!$stmt2){ 
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/index.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for total cups this month : ' + $conn-> error,
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to prepare statement', ['error'=>$conn->error]); 
    }
    $stmt2->bind_param('sss', $company_id, $start_month, $next_month);
    $stmt2->execute();
    $cups = ($stmt2->get_result()->fetch_assoc()['cups_this_month'] ?? 0) * 1;

    // 3. Average daily revenue (calendar days in the month)
    $days_in_month = (int)date('t', strtotime($start_month));
    $avg_calendar = $days_in_month > 0 ? ($rev / $days_in_month) : 0;

    // 4. Top 3 menus by cups this month
    $sql4 = "SELECT m.menu_id, m.menu_name, COALESCE(SUM(td.quantity),0) AS total_cups, COALESCE(SUM(td.subtotal),0) AS total_revenue FROM {$schema}.transaction t JOIN {$schema}.transaction_detail td ON td.transaction_id = t.transaction_id LEFT JOIN {$schema}.menu m ON m.menu_id = td.menu_id WHERE t.company_id = ? AND t.transaction_date >= ? AND t.transaction_date < ? GROUP BY m.menu_id, m.menu_name ORDER BY total_cups DESC, total_revenue DESC LIMIT 3";
    $stmt4 = $conn->prepare($sql4);

    if(!$stmt4){ 
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/index.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for top 3 menus : ' + $conn-> error,
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to prepare statement', ['error'=>$conn->error]); 
    }

    $stmt4->bind_param('sss', $company_id, $start_month, $next_month);
    $stmt4->execute();

    $top = [];
    $r4 = $stmt4->get_result();

    while($row = $r4->fetch_assoc()){
        $top[] = [
            'menu_id' => $row['menu_id'],
            'menu_name' => $row['menu_name'],
            'total_cups' => (int)$row['total_cups'],
            'total_revenue' => (float)$row['total_revenue'],
        ];
    }

    // 5) Performance per menu (full list)
    $sql5 = "SELECT m.menu_id, m.menu_name, COALESCE(SUM(td.quantity),0) AS total_cups, COALESCE(SUM(td.subtotal),0) AS total_revenue FROM {$schema}.transaction t JOIN {$schema}.transaction_detail td ON td.transaction_id = t.transaction_id LEFT JOIN {$schema}.menu m ON m.menu_id = td.menu_id WHERE t.company_id = ? AND t.transaction_date >= ? AND t.transaction_date < ? GROUP BY m.menu_id, m.menu_name ORDER BY total_revenue DESC";
    $stmt5 = $conn->prepare($sql5);

    if(!$stmt5){ 
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/index.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for performance per menus : ' + $conn-> error,
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to prepare statement', ['error'=>$conn->error]); 
    }

    $stmt5->bind_param('sss', $company_id, $start_month, $next_month);
    $stmt5->execute();
    $per_menu = [];
    $r5 = $stmt5->get_result();
    while($row = $r5->fetch_assoc()){
        $per_menu[] = [
            'menu_id' => $row['menu_id'],
            'menu_name' => $row['menu_name'],
            'total_cups' => (int)$row['total_cups'],
            'total_revenue' => (float)$row['total_revenue'],
        ];
    }

    jsonResponse(200, 'Dashboard fetched', [
        'period' => [ 'start' => $start_month, 'end_exclusive' => $next_month ],
        'revenue_this_month' => (float)$rev,
        'cups_this_month' => (int)$cups,
        'avg_daily_revenue' => (float)$avg_calendar,
        'top_menus' => $top,
        'menu_performance' => $per_menu,
    ]);
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 401,
        'endpoint'      => '/dashboard/index.php',
        'method'        => 'GET',
        'error_message' => 'Authorization header not found',
        'user_identifier' => $decoded->username ?? null,
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
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'GET':
            $company_id = $_GET['company_id'] ?? null;
            getDashboard($conn, $schema, $company_id, $token_username);
            break;
        default:
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 405,
                'endpoint'      => '/dashboard/index.php',
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
        'endpoint'      => '/dashboard/index.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>