<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function checkActiveDriverSpesific($conn, $schema, $company_id, $username){
    if (!$company_id) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/session/active-drivers.php',
            'method'        => '',
            'error_message' => 'company_id not found in token',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'company_id not found in token');
    }

    $company_id_esc = mysqli_real_escape_string($conn, $company_id);

    $qPay = "SELECT t.session_id, tp.payment_method, SUM(tp.amount) AS total_amount FROM {$schema}.`transaction` t JOIN {$schema}.transaction_payment tp ON tp.transaction_id = t.transaction_id WHERE t.company_id = '$company_id_esc'  GROUP BY t.session_id, tp.payment_method";

    $rPay = mysqli_query($conn, $qPay);
    if (!$rPay) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/session/active-drivers.php',
            'method'        => '',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(500, 'DB error payment', ['error' => mysqli_error($conn)]);
    }

    $paymentsBySession = [];

    while ($row = mysqli_fetch_assoc($rPay)) {
        $sid = $row['session_id'];

        if (!isset($paymentsBySession[$sid])) {
            $paymentsBySession[$sid] = [];
        }

        $paymentsBySession[$sid][] = [
            'payment_method' => $row['payment_method'],
            'total_amount'   => (int)$row['total_amount'],
        ];
    }

    // Aggregate per (session_id, menu_id) to be compatible with ONLY_FULL_GROUP_BY
    $q = "SELECT ws.session_id, MAX(ws.company_id) AS company_id, MAX(ws.user_id) AS user_id, MAX(ws.started_at) AS started_at, MAX(ws.ended_at) AS ended_at, MAX(ws.cash_start) AS cash_start, MAX(ws.cash_end) AS cash_end, MAX(ws.status) AS status, COALESCE(MAX(au.username), MAX(ws.user_id)) AS username, MAX(au.phone_number) AS phone_number, m.menu_id, MAX(m.menu_name) AS menu_name, MAX(cm.category_name) AS category_name, MAX(m.image_url) AS image_url, MAX(wss.qty_start) AS qty_start, COALESCE(SUM(td.quantity), 0) AS qty_sold, m.price FROM {$schema}.work_session ws LEFT JOIN movira_core_dev.app_user au ON au.username = ws.user_id OR au.phone_number = ws.user_id OR au.user_id = ws.user_id JOIN {$schema}.work_session_stock wss ON wss.session_id = ws.session_id JOIN {$schema}.menu m ON m.menu_id = wss.menu_id LEFT JOIN {$schema}.category_menu cm ON cm.category_id = m.category_id LEFT JOIN {$schema}.`transaction` t ON t.session_id = ws.session_id LEFT JOIN {$schema}.transaction_detail td ON td.transaction_id = t.transaction_id AND td.menu_id = m.menu_id WHERE ws.company_id = '$company_id_esc' AND ws.status = 'active' GROUP BY ws.session_id, m.menu_id ORDER BY started_at ASC, menu_name ASC";

    $r = mysqli_query($conn, $q);
    if (!$r) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/session/active-drivers.php',
            'method'        => '',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    $sessions = [];

    while ($row = mysqli_fetch_assoc($r)) {
        $sid = $row['session_id'];

        if (!isset($sessions[$sid])) {
            $sessions[$sid] = [
                'session_id' => $sid,
                'company_id' => $row['company_id'],
                'user_id' => $row['user_id'],
                'username' => $row['username'],
                'phone_number' => $row['phone_number'] ?? null,
                'started_at' => $row['started_at'],
                'ended_at' => $row['ended_at'],
                'cash_start' => (int)($row['cash_start'] ?? 0),
                'cash_end' => $row['cash_end'] !== null ? (int)$row['cash_end'] : null,
                'status' => $row['status'],
                'menus' => [],
                'payments' => []
            ];
        }

        $qtyStart = (int)($row['qty_start'] ?? 0);
        $qtySold  = (int)($row['qty_sold'] ?? 0);
        $qtyLeft  = $qtyStart - $qtySold;
        if ($qtyLeft < 0) $qtyLeft = 0;

        $sessions[$sid]['menus'][] = [
            'menu_id' => $row['menu_id'],
            'menu_name' => $row['menu_name'],
            'category_name' => $row['category_name'],
            'image_url' => $row['image_url'],
            'qty_start' => $qtyStart,
            'qty_sold' => $qtySold,
            'qty_left' => $qtyLeft,
            'price' => (int)($row['price'] ?? 0)
        ];
    }

    jsonResponse(200, 'Active drivers found', array_values($sessions));
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

$headers = function_exists('getallheaders') ? getallheaders() : [];

// Case-insensitive Authorization lookup (some servers return 'authorization')
$authHeader = null;
foreach ($headers as $k => $v) {
    if (strtolower($k) === 'authorization') {
        $authHeader = $v;
        break;
    }
}

// Fallbacks for different SAPIs / proxies
if (!$authHeader) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
}
if (!$authHeader) {
    $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
}

if (!$authHeader) {
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/session/active-drivers.php',
        'method'        => '',
        'error_message' => 'Authorization header not found',
        'user_identifier' => $username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(401, 'Authorization header not found');
}

try {
    $token = preg_replace('/^Bearer\s+/i', '', trim($authHeader));
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn = DB::conn();
    $schema = DB_SCHEMA;

    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'GET':
            $company_id = $decoded->company_id ?? null;
            checkActiveDriverSpesific($conn, $schema, $company_id, $token_username);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }

} catch (Exception $e){
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 401,
        'endpoint'      => '/session/active-drivers.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(401, 'Unauthorized', ['error' => $e->getMessage()]);
}