<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../notification/notification.php';
require_once '../log.php';

function createPOSTransacton($conn, $input, $username, $decoded){
    // company_id from token
    $company_id = $decoded->company_id ?? null;
    if (!$company_id) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/pos/transaction.php',
            'method'        => 'POST',
            'error_message' => 'company_id not found in token',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'company_id not found in token');
    }

    // Basic validation
    if (!$input || !isset($input['items']) || !is_array($input['items']) || count($input['items']) === 0) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/pos/transaction.php',
            'method'        => 'POST',
            'error_message' => 'Invalid payload. Require non-empty items array.',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Invalid payload. Require non-empty items array.');
    }

    $items = $input['items'];
    $payments = $input['payments'] ?? null;

    $latitude  = $input['latitude']  ?? null;
    $longitude = $input['longitude'] ?? null;

    if ($latitude !== null && !is_numeric($latitude)) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/pos/transaction.php',
            'method'        => 'POST',
            'error_message' => 'Invalid latitude',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Invalid latitude');
    }
    if ($longitude !== null && !is_numeric($longitude)) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/pos/transaction.php',
            'method'        => 'POST',
            'error_message' => 'Invalid longitude',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Invalid longitude');
    }

    if (!$payments || !is_array($payments) || count($payments) === 0) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/pos/transaction.php',
            'method'        => 'POST',
            'error_message' => 'Invalid payload. Require payments array with at least one item.',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Invalid payload. Require payments array with at least one item.');
    }

    $company_id_esc = mysqli_real_escape_string($conn, $company_id);
    $user_id_esc    = mysqli_real_escape_string($conn, $username);

    // 1) Find active session
    $qSess = "SELECT session_id, started_at, cash_start FROM raki_dev.work_session WHERE company_id='$company_id_esc' AND user_id='$user_id_esc' AND status='active' ORDER BY started_at DESC LIMIT 1";

    $rSess = mysqli_query($conn, $qSess);
    if (!$rSess) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/pos/transaction.php',
            'method'        => 'POST',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($rSess) !== 1) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/pos/transaction.php',
            'method'        => 'POST',
            'error_message' => 'No active session. Please start session first.',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'No active session. Please start session first.');
    }

    $session = mysqli_fetch_assoc($rSess);
    $session_id = $session['session_id'];

    // 2) Prepare items & compute totals
    $total_amount = 0;
    $total_items = 0;
    $prepared_items = [];

    foreach ($items as $idx => $it) {
        if (!isset($it['menu_id']) || !isset($it['quantity']) || !isset($it['unit_price'])) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 400,
                'endpoint'      => '/pos/transaction.php',
                'method'        => 'POST',
                'error_message' => "Invalid item at index $idx. Require menu_id, quantity, unit_price.",
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(400, "Invalid item at index $idx. Require menu_id, quantity, unit_price.");
        }
        $menu_id = (string)$it['menu_id'];
        $quantity = (int)$it['quantity'];
        $unit_price = (int)$it['unit_price'];

        if ($menu_id === '' || $quantity <= 0 || $unit_price < 0) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 400,
                'endpoint'      => '/pos/transaction.php',
                'method'        => 'POST',
                'error_message' => "Invalid item values at index $idx.",
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(400, "Invalid item values at index $idx.");
        }

        $subtotal = $quantity * $unit_price;
        $total_amount += $subtotal;
        $total_items += $quantity;

        $prepared_items[] = [
            'menu_id' => $menu_id,
            'quantity' => $quantity,
            'unit_price' => $unit_price,
            'subtotal' => $subtotal,
        ];
    }

    // 3) Prepare payments & validate sum
    $allowed_methods = ['cash', 'qris'];
    $prepared_payments = [];
    $total_paid = 0;

    foreach ($payments as $idx => $p) {
        if (!isset($p['payment_method']) || !isset($p['amount'])) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 400,
                'endpoint'      => '/pos/transaction.php',
                'method'        => 'POST',
                'error_message' => "Invalid payment at index $idx. Require payment_method and amount.",
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(400, "Invalid payment at index $idx. Require payment_method and amount.");
        }
        $method = (string)$p['payment_method'];
        $amount = (int)$p['amount'];

        if (!in_array($method, $allowed_methods, true)) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 400,
                'endpoint'      => '/pos/transaction.php',
                'method'        => 'POST',
                'error_message' => "Invalid payment_method at index $idx.",
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(400, "Invalid payment_method at index $idx.");
        }
        if ($amount <= 0) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 400,
                'endpoint'      => '/pos/transaction.php',
                'method'        => 'POST',
                'error_message' => "Invalid payment amount at index $idx.",
                'user_identifier' => $username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(400, "Invalid payment amount at index $idx.");
        }

        $total_paid += $amount;
        $prepared_payments[] = ['payment_method' => $method, 'amount' => $amount];
    }

    if ($total_paid !== (int)$total_amount) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/pos/transaction.php',
            'method'        => 'POST',
            'error_message' => "Total payment (cash + qris) must equal total_amount. total_paid=$total_paid, total_amount=$total_amount",
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, "Total payment (cash + qris) must equal total_amount. total_paid=$total_paid, total_amount=$total_amount");
    }

    // 4) Oversell guard: check qty_left for each menu in this session
    $sid_esc = mysqli_real_escape_string($conn, $session_id);

    foreach ($prepared_items as $pi) {
        $menu_id = mysqli_real_escape_string($conn, $pi['menu_id']);
        $qtyReq  = (int)$pi['quantity'];

        $qLeft = "SELECT MAX(wss.qty_start) AS qty_start, COALESCE(SUM(td.quantity), 0) AS qty_sold FROM raki_dev.work_session_stock wss LEFT JOIN raki_dev.`transaction` t ON t.session_id = wss.session_id LEFT JOIN raki_dev.transaction_detail td ON td.transaction_id = t.transaction_id AND td.menu_id = wss.menu_id WHERE wss.session_id = '$sid_esc' AND wss.menu_id = '$menu_id' GROUP BY wss.session_id, wss.menu_id LIMIT 1";

        $rLeft = mysqli_query($conn, $qLeft);
        if (!$rLeft) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/pos/transaction.php',
                'method'        => 'POST',
                'error_message' => mysqli_error($conn),
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
        }

        if (mysqli_num_rows($rLeft) !== 1) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 400,
                'endpoint'      => '/pos/transaction.php',
                'method'        => 'POST',
                'error_message' => "Menu not found in session stock",
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(400, 'Menu not found in session stock', [
                'menu_id' => $pi['menu_id']
            ]);
        }

        $rowLeft = mysqli_fetch_assoc($rLeft);
        $qtyStart = (int)($rowLeft['qty_start'] ?? 0);
        $qtySold  = (int)($rowLeft['qty_sold'] ?? 0);
        $qtyLeft  = $qtyStart - $qtySold;
        if ($qtyLeft < 0) $qtyLeft = 0;

        if ($qtyReq > $qtyLeft) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 400,
                'endpoint'      => '/pos/transaction.php',
                'method'        => 'POST',
                'error_message' => "Insufficient stock for this session",
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(400, 'Insufficient stock for this session', [
                'menu_id' => $pi['menu_id'],
                'qty_left' => $qtyLeft,
                'qty_requested' => $qtyReq
            ]);
        }
    }

    // 5) Insert transaction (header/detail/payment) in one DB transaction
    $conn->begin_transaction();

    try {
        $transaction_id = 'trx' . uniqid();
        $transaction_date = date('Y-m-d H:i:s');

        $sqlHeader = "INSERT INTO raki_dev.transaction (transaction_id, session_id, company_id, transaction_date, total_amount, latitude, longitude, created_at, created_by, updated_at, updated_by, total_item) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, NOW(), ?, ?)";

        $stmtHeader = $conn->prepare($sqlHeader);
        if (!$stmtHeader) {
            throw new Exception('Prepare header failed: ' . $conn->error);
        }

        $stmtHeader->bind_param(
            'ssssiddssi',
            $transaction_id,
            $session_id,
            $company_id,
            $transaction_date,
            $total_amount,   // i
            $latitude,       // d
            $longitude,      // d
            $username,
            $username,
            $total_items     // i
        );

        if (!$stmtHeader->execute()) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/pos/transaction.php',
                'method'        => 'POST',
                'error_message' => 'Execute header failed: ' . $stmtHeader->error,
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);

            throw new Exception('Execute header failed: ' . $stmtHeader->error);
        }

        // Insert details
        $sqlDetail = "INSERT INTO raki_dev.transaction_detail (detail_id, transaction_id, menu_id, quantity, subtotal, created_at) VALUES (?, ?, ?, ?, ?, NOW())";

        $stmtDetail = $conn->prepare($sqlDetail);
        if (!$stmtDetail) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/pos/transaction.php',
                'method'        => 'POST',
                'error_message' => 'Prepare detail failed: ' . $conn->error,
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);

            throw new Exception('Prepare detail failed: ' . $conn->error);
        }

        $response_items = [];
        foreach ($prepared_items as $pi) {
            $detail_id = 'trd' . uniqid();
            $menu_id = $pi['menu_id'];
            $qty = (int)$pi['quantity'];
            $subtotal = (int)$pi['subtotal'];

            $stmtDetail->bind_param('sssii', $detail_id, $transaction_id, $menu_id, $qty, $subtotal);
            if (!$stmtDetail->execute()) {
                logApiError($conn, [
                    'error_level'   => 'error',
                    'http_status'   => 500,
                    'endpoint'      => '/pos/transaction.php',
                    'method'        => 'POST',
                    'error_message' => 'Execute detail failed: ' . $stmtDetail->error,
                    'user_identifier' => $decoded->username ?? null,
                    'company_id'      => $decoded->company_id ?? null,
                ]);

                throw new Exception('Execute detail failed: ' . $stmtDetail->error);
            }

            $response_items[] = [
                'detail_id' => $detail_id,
                'menu_id' => $menu_id,
                'quantity' => $qty,
                'unit_price' => (int)$pi['unit_price'],
                'subtotal' => $subtotal,
            ];
        }

        // Insert payments
        $sqlPayment = "INSERT INTO raki_dev.transaction_payment (payment_id, transaction_id, payment_method, amount, company_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())";

        $stmtPayment = $conn->prepare($sqlPayment);
        if (!$stmtPayment) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/pos/transaction.php',
                'method'        => 'POST',
                'error_message' => 'Prepare payment failed: ' . $conn->error,
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            throw new Exception('Prepare payment failed: ' . $conn->error);
        }

        foreach ($prepared_payments as $pay) {
            $payment_id = 'pay' . uniqid();
            $method = $pay['payment_method'];
            $amount = (int)$pay['amount'];

            $stmtPayment->bind_param('sssii', $payment_id, $transaction_id, $method, $amount, $company_id);
            if (!$stmtPayment->execute()) {
                logApiError($conn, [
                    'error_level'   => 'error',
                    'http_status'   => 500,
                    'endpoint'      => '/pos/transaction.php',
                    'method'        => 'POST',
                    'error_message' => 'Execute payment failed: ' . $stmtPayment->error,
                    'user_identifier' => $decoded->username ?? null,
                    'company_id'      => $decoded->company_id ?? null,
                ]);
                throw new Exception('Execute payment failed: ' . $stmtPayment->error);
            }
        }

        $conn->commit();

        jsonResponse(201, 'POS transaction created', [
            'transaction_id' => $transaction_id,
            'session_id' => $session_id,
            'company_id' => $company_id,
            'transaction_date' => $transaction_date,
            'total_amount' => (int)$total_amount,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'total_item' => (int)$total_items,
            'items' => $response_items,
            'payments' => $prepared_payments,
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/pos/transaction.php',
            'method'        => 'POST',
            'error_message' => $e->getMessage(),
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);

        jsonResponse(500, 'Failed to create POS transaction', ['error' => $e->getMessage()]);
    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

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
        'http_status'   => 401,
        'endpoint'      => '/pos/transaction.php',
        'method'        => 'POST',
        'error_message' => 'Authorization header not found',
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(401, 'Authorization header not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
    http_response_code(200);
    exit();
}

try {
    $token = preg_replace('/^Bearer\s+/i', '', trim($authHeader));
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn = DB::conn();

    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'POST':
            if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
                // Not fatal, but helps clients send correct header
                // continue anyway as we read raw body
            }
            $input = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                jsonResponse(400, 'Invalid JSON body');
            }
            createPOSTransacton($conn, $input, $token_username, $decoded);
            break;
        default:
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 405,
                'endpoint'      => '/pos/transaction.php',
                'method'        => 'POST',
                'error_message' => 'Method Not Allowed',
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
    }

} catch (Exception $e){
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/pos/transaction.php',
        'method'        => 'POST',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}