<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function getPaymentMethodSummary($conn, $schema, $company_id, $start_date, $end_date, $username){
    if (!$company_id || !$start_date || !$end_date) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/dashboard/index.php',
            'method'        => 'GET',
            'error_message' => 'company_id, start_date, and end_date parameters are required',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(400, 'company_id, start_date, and end_date parameters are required');
    }

    $stmt = $conn->prepare("CALL {$schema}.GetPaymentMethodSummary(?, ?, ?)");

    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/index.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for GetPaymentMethodSummary: ' . $conn->error,
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to prepare statement', ['error' => $conn->error]);
    }

    $stmt->bind_param('sss', $start_date, $end_date, $company_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'payment_method' => $row['payment_method'],
            'total_amount'   => (float)$row['total_amount'],
        ];
    }
    $stmt->close();

    jsonResponse(200, 'Payment method summary fetched', [
        'period' => ['start_date' => $start_date, 'end_date' => $end_date],
        'summary' => $data,
    ]);
}

function getCompanyOutletSnapshot($conn, $schema, $company_id){
    $start_month = date('Y-m-01');
    $next_month  = date('Y-m-01', strtotime('+1 month', strtotime($start_month)));

    $stmtName = $conn->prepare("SELECT company_name FROM movira_core_dev.app_company WHERE company_id = ? LIMIT 1");
    $stmtName->bind_param('s', $company_id);
    $stmtName->execute();
    $company_name = $stmtName->get_result()->fetch_assoc()['company_name'] ?? null;
    $stmtName->close();

    $stmtRev = $conn->prepare("SELECT COALESCE(SUM(total_amount),0) AS revenue FROM {$schema}.transaction WHERE company_id = ? AND transaction_date >= ? AND transaction_date < ?");
    $stmtRev->bind_param('sss', $company_id, $start_month, $next_month);
    $stmtRev->execute();
    $revenue = (float)($stmtRev->get_result()->fetch_assoc()['revenue'] ?? 0);
    $stmtRev->close();

    $stmtCups = $conn->prepare("SELECT COALESCE(SUM(td.quantity),0) AS cups FROM {$schema}.transaction t JOIN {$schema}.transaction_detail td ON td.transaction_id = t.transaction_id WHERE t.company_id = ? AND t.transaction_date >= ? AND t.transaction_date < ?");
    $stmtCups->bind_param('sss', $company_id, $start_month, $next_month);
    $stmtCups->execute();
    $cups = (int)($stmtCups->get_result()->fetch_assoc()['cups'] ?? 0);
    $stmtCups->close();

    $stmtDrivers = $conn->prepare("SELECT COUNT(*) AS c FROM movira_core_dev.app_user WHERE company_id = ? AND app_role_id = 'app_role6902bc0cbb991'");
    $stmtDrivers->bind_param('s', $company_id);
    $stmtDrivers->execute();
    $driver_count = (int)($stmtDrivers->get_result()->fetch_assoc()['c'] ?? 0);
    $stmtDrivers->close();

    $stmtActive = $conn->prepare("SELECT COUNT(DISTINCT user_id) AS c FROM {$schema}.work_session WHERE company_id = ? AND status = 'active'");
    $stmtActive->bind_param('s', $company_id);
    $stmtActive->execute();
    $active_drivers_today = (int)($stmtActive->get_result()->fetch_assoc()['c'] ?? 0);
    $stmtActive->close();

    return [
        'company_id' => $company_id,
        'company_name' => $company_name,
        'revenue_this_month' => $revenue,
        'cups_this_month' => $cups,
        'driver_count' => $driver_count,
        'active_drivers_today' => $active_drivers_today,
    ];
}

function getAllOutletsSummary($conn, $schema, $token_username, $token_role, $token_company_id, $username){
    if (!$token_company_id) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/dashboard/index.php',
            'method'        => 'GET',
            'error_message' => 'company_id not found in token',
            'user_identifier' => $username ?? null,
            'company_id'      => $token_company_id ?? null,
        ]);
        jsonResponse(400, 'company_id not found in token');
    }

    // Multi-outlet owners are separate app_user rows (one per outlet) that share
    // the same email address. Resolve every company_id tied to this owner's email
    // so the dashboard can fetch all outlets in a single call instead of N+1.
    $stmtSelf = $conn->prepare("SELECT email FROM movira_core_dev.app_user WHERE username = ? LIMIT 1");
    $stmtSelf->bind_param('s', $token_username);
    $stmtSelf->execute();
    $email = trim($stmtSelf->get_result()->fetch_assoc()['email'] ?? '');
    $stmtSelf->close();

    $company_ids = [];
    if ($email !== '') {
        $stmtCompanies = $conn->prepare("SELECT DISTINCT company_id FROM movira_core_dev.app_user WHERE email = ? AND app_role_id = ? AND company_id IS NOT NULL AND company_id <> ''");
        $stmtCompanies->bind_param('ss', $email, $token_role);
        $stmtCompanies->execute();
        $res = $stmtCompanies->get_result();
        while ($row = $res->fetch_assoc()) {
            $company_ids[] = $row['company_id'];
        }
        $stmtCompanies->close();
    }

    if (empty($company_ids)) {
        $company_ids = [$token_company_id];
    }

    $summaries = [];
    foreach ($company_ids as $company_id) {
        $summaries[] = getCompanyOutletSnapshot($conn, $schema, $company_id);
    }

    jsonResponse(200, 'All outlets summary fetched', $summaries);
}

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

    // 3b. Today-scoped figures (same-day momentum, separate from the monthly rollup)
    $today = date('Y-m-d');
    $tomorrow = date('Y-m-d', strtotime('+1 day', strtotime($today)));

    $sqlToday = "SELECT COALESCE(SUM(t.total_amount),0) AS revenue_today, COUNT(*) AS transactions_today FROM {$schema}.transaction t WHERE t.company_id = ? AND t.transaction_date >= ? AND t.transaction_date < ?";
    $stmtToday = $conn->prepare($sqlToday);

    if(!$stmtToday){
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/index.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for today summary : ' . $conn->error,
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to prepare statement', ['error'=>$conn->error]);
    }

    $stmtToday->bind_param('sss', $company_id, $today, $tomorrow);
    $stmtToday->execute();
    $todayRow = $stmtToday->get_result()->fetch_assoc();
    $revenue_today = (float)($todayRow['revenue_today'] ?? 0);
    $transactions_today = (int)($todayRow['transactions_today'] ?? 0);
    $stmtToday->close();

    $sqlCupsToday = "SELECT COALESCE(SUM(td.quantity),0) AS cups_today FROM {$schema}.transaction t JOIN {$schema}.transaction_detail td ON td.transaction_id = t.transaction_id WHERE t.company_id = ? AND t.transaction_date >= ? AND t.transaction_date < ?";
    $stmtCupsToday = $conn->prepare($sqlCupsToday);
    $stmtCupsToday->bind_param('sss', $company_id, $today, $tomorrow);
    $stmtCupsToday->execute();
    $cups_today = (int)($stmtCupsToday->get_result()->fetch_assoc()['cups_today'] ?? 0);
    $stmtCupsToday->close();

    $stmtActiveDrivers = $conn->prepare("SELECT COUNT(DISTINCT user_id) AS c FROM {$schema}.work_session WHERE company_id = ? AND status = 'active'");
    $stmtActiveDrivers->bind_param('s', $company_id);
    $stmtActiveDrivers->execute();
    $active_drivers_today = (int)($stmtActiveDrivers->get_result()->fetch_assoc()['c'] ?? 0);
    $stmtActiveDrivers->close();

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
        'revenue_today' => $revenue_today,
        'cups_today' => $cups_today,
        'transactions_today' => $transactions_today,
        'active_drivers_today' => $active_drivers_today,
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
            $action = $_GET['action'] ?? null;
            if ($action === 'payment_method_summary') {
                $start_date = $_GET['start_date'] ?? null;
                $end_date   = $_GET['end_date'] ?? null;
                getPaymentMethodSummary($conn, $schema, $company_id, $start_date, $end_date, $token_username);
            } else if ($action === 'all_outlets_summary') {
                getAllOutletsSummary($conn, $schema, $token_username, $decoded->role, $decoded->company_id ?? null, $token_username);
            } else {
                getDashboard($conn, $schema, $company_id, $token_username);
            }
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