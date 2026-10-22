<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function checkActiveDriverSpesific($conn, $schema, $company_id, $username) {
    $coreSchema = MOVIRA_CORE_SCHEMA;
    if (!$company_id) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/session/active-drivers.php',
            'method'        => '',
            'error_message' => 'company_id not found in token',
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'company_id not found in token');
    }

    // Payment totals per session
    $stmtPay = $conn->prepare("SELECT t.session_id, tp.payment_method, SUM(tp.amount) AS total_amount FROM {$schema}.`transaction` t JOIN {$schema}.transaction_payment tp ON tp.transaction_id = t.transaction_id WHERE t.company_id = ? GROUP BY t.session_id, tp.payment_method");
    if (!$stmtPay) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/session/active-drivers.php',
            'method'        => '',
            'error_message' => 'Database query error',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Database query error');
    }
    $stmtPay->bind_param('s', $company_id);
    $stmtPay->execute();
    $rPay = $stmtPay->get_result();
    $stmtPay->close();

    $paymentsBySession = [];
    while ($row = $rPay->fetch_assoc()) {
        $sid = $row['session_id'];
        if (!isset($paymentsBySession[$sid])) {
            $paymentsBySession[$sid] = [];
        }
        $paymentsBySession[$sid][] = [
            'payment_method' => $row['payment_method'],
            'total_amount'   => (int)$row['total_amount'],
        ];
    }

    // Active sessions with stock details
    $stmt = $conn->prepare("SELECT ws.session_id, MAX(ws.company_id) AS company_id, MAX(ws.user_id) AS user_id, MAX(ws.started_at) AS started_at, MAX(ws.ended_at) AS ended_at, MAX(ws.cash_start) AS cash_start, MAX(ws.cash_end) AS cash_end, MAX(ws.status) AS status, COALESCE(MAX(au.username), MAX(ws.user_id)) AS username, MAX(au.phone_number) AS phone_number, m.menu_id, MAX(m.menu_name) AS menu_name, MAX(cm.category_name) AS category_name, MAX(m.image_url) AS image_url, MAX(wss.qty_start) AS qty_start, COALESCE(SUM(td.quantity), 0) AS qty_sold, m.price FROM {$schema}.work_session ws LEFT JOIN {$coreSchema}.app_user au ON au.username = ws.user_id OR au.phone_number = ws.user_id OR au.user_id = ws.user_id JOIN {$schema}.work_session_stock wss ON wss.session_id = ws.session_id JOIN {$schema}.menu m ON m.menu_id = wss.menu_id LEFT JOIN {$schema}.category_menu cm ON cm.category_id = m.category_id LEFT JOIN {$schema}.`transaction` t ON t.session_id = ws.session_id LEFT JOIN {$schema}.transaction_detail td ON td.transaction_id = t.transaction_id AND td.menu_id = m.menu_id WHERE ws.company_id = ? AND ws.status = 'active' GROUP BY ws.session_id, m.menu_id ORDER BY started_at ASC, menu_name ASC");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/session/active-drivers.php',
            'method'        => '',
            'error_message' => 'Database query error',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $company_id);
    $stmt->execute();
    $r = $stmt->get_result();
    $stmt->close();

    $sessions = [];
    while ($row = $r->fetch_assoc()) {
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
                'payments' => $paymentsBySession[$sid] ?? [],
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
            'price' => (int)($row['price'] ?? 0),
        ];
    }

    jsonResponse(200, 'Active drivers found', array_values($sessions));
}

$decoded = requireAuth();

$conn = DB::conn();
$schema = DB_SCHEMA;
$token_username = $decoded->username;
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // An Owner may look at any of their outlets; everyone else gets the company in the token.
        $company_id = $_GET['company_id'] ?? ($decoded->company_id ?? null);
        if ($company_id !== ($decoded->company_id ?? null)) {
            requireCompanyAccess($conn, $decoded, $company_id);
        }
        checkActiveDriverSpesific($conn, $schema, $company_id, $token_username);
        break;
    default:
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 405,
            'endpoint'      => '/session/active-drivers.php',
            'method'        => $method,
            'error_message' => 'Method Not Allowed',
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(405, 'Method Not Allowed');
        break;
}
