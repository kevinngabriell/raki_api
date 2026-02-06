<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function checkTransactionHistory($conn, $company_id, $username){
    $company_id_esc = mysqli_real_escape_string($conn, $company_id);
    $username_esc   = mysqli_real_escape_string($conn, $username);

    $qHeader = "SELECT t.transaction_id, t.created_at, t.total_amount, t.total_item, t.payment_method, t.source_type, COALESCE(SUM(td.quantity), 0) AS total_cup FROM raki_dev.`transaction` t LEFT JOIN raki_dev.transaction_detail td ON td.transaction_id = t.transaction_id WHERE t.company_id = '$company_id_esc' AND t.created_by = '$username_esc' GROUP BY t.transaction_id, t.created_at, t.total_amount, t.total_item, t.payment_method, t.source_type ORDER BY t.created_at DESC, t.created_at DESC";

    $rHeader = mysqli_query($conn, $qHeader);
    if (!$rHeader) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/session/transaction-history.php',
            'method'        => 'POST',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(500, 'DB error (transaction header)', ['error' => mysqli_error($conn)]);
    }

    $transactions = [];
    while ($row = mysqli_fetch_assoc($rHeader)) {
        $tid = $row['transaction_id'];

        $transactions[$tid] = [
            'transaction_id'   => $tid,
            'transaction_date' => $row['created_at'],
            'total_amount'     => (int)$row['total_amount'],
            'total_item'       => isset($row['total_item']) ? (int)$row['total_item'] : null,
            'total_cup'        => (int)$row['total_cup'], // jumlah cup = SUM qty detail
            'payment_method'   => $row['payment_method'], // default payment_method di header
            'source_type'      => $row['source_type'],
            'payments'         => [], // akan diisi dari tabel transaction_payment
        ];
    }

    if (empty($transactions)) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 200,
            'endpoint'      => '/session/transaction-history.php',
            'method'        => 'POST',
            'error_message' => 'No transactions found',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(200, 'No transactions found', []);
    }

    // 2) Ambil detail pembayaran per transaksi (bisa multi payment_method per transaksi)
    $transaction_ids = array_keys($transactions);
    $inList = "'" . implode("','", array_map('mysqli_real_escape_string', array_fill(0, count($transaction_ids), $conn), $transaction_ids)) . "'";

    $qPay = "SELECT tp.transaction_id, tp.payment_method, SUM(tp.amount) AS amount FROM raki_dev.transaction_payment tp WHERE tp.transaction_id IN ($inList) GROUP BY  tp.transaction_id, tp.payment_method";

    $rPay = mysqli_query($conn, $qPay);
    if ($rPay) {
        while ($row = mysqli_fetch_assoc($rPay)) {
            $tid = $row['transaction_id'];
            if (!isset($transactions[$tid])) {
                continue;
            }
            $transactions[$tid]['payments'][] = [
                'payment_method' => $row['payment_method'],
                'amount'         => (int)$row['amount'],
            ];
        }
    }

    // Return sebagai array terurut (values saja)
    jsonResponse(200, 'Transaction history found', array_values($transactions));
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
        'http_status'   => 401,
        'endpoint'      => '/session/transaction-history.php',
        'method'        => 'POST',
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
    
    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'GET':
            // Prefer token values to prevent user spoofing, but currently using params
            $company_id = $_GET['company_id'] ?? null;
            $username   = $_GET['username'] ?? null;

            if (!$company_id || !$username) {
                jsonResponse(400, 'company_id and username are required');
            }

            checkTransactionHistory($conn, $company_id, $username);
        break;
        default:
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 405,
                'endpoint'      => '/session/start.php',
                'method'        => 'POST',
                'error_message' => 'Method Not Allowed',
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 401,
        'endpoint'      => '/session/transaction-history.php',
        'method'        => 'POST',
        'error_message' => $e->getMessage(),
        'user_identifier' => $username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(401, 'Unauthorized', ['error' => $e->getMessage()]);
}

?>
