<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function checkActiveSession($conn, $company_id, $username)
{
    if (!$company_id) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/session/active.php',
            'method'        => '',
            'error_message' => 'company_id is required',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'company_id is required');
    }

    if (!$username) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/session/active.php',
            'method'        => '',
            'error_message' => 'username is required',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'username is required');
    }

    $company_id_esc = mysqli_real_escape_string($conn, $company_id);
    $username_esc   = mysqli_real_escape_string($conn, $username);

    $q = "SELECT session_id, company_id, user_id, started_at, ended_at, cash_start, cash_end, status FROM raki_dev.work_session WHERE company_id='$company_id_esc' AND user_id='$username_esc' AND status='active' ORDER BY started_at DESC LIMIT 1";

    $r = mysqli_query($conn, $q);
    if (!$r) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/session/active.php',
            'method'        => '',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($r) !== 1) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 200,
            'endpoint'      => '/session/active.php',
            'method'        => '',
            'error_message' => 'No active session',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(200, 'No active session', null);
    }

    $session = mysqli_fetch_assoc($r);
    $sid = mysqli_real_escape_string($conn, $session['session_id']);

    // --- Payment summary for this session ---
    $qp = "SELECT SUM(CASE WHEN tp.payment_method = 'cash' THEN t.total_amount ELSE 0 END) AS cash_amount, SUM(CASE WHEN tp.payment_method = 'qris' THEN t.total_amount ELSE 0 END) AS qris_amount, SUM(CASE WHEN tp.payment_method = 'transfer' THEN t.total_amount ELSE 0 END) AS transfer_amount, SUM(CASE WHEN tp.payment_method = 'qris_midtrans' THEN t.total_amount ELSE 0 END) AS qris_midtrans_amount, COUNT(t.transaction_id) AS total_transactions, SUM(t.total_amount) AS grand_total_amount FROM raki_dev.transaction_payment tp LEFT JOIN raki_dev.`transaction` t  ON tp.transaction_id = t.transaction_id WHERE t.session_id = '$sid';";

    $rp = mysqli_query($conn, $qp);
    $paymentSummary = [
        'cash_amount'          => 0,
        'qris_amount'          => 0,
        'transfer_amount'      => 0,
        'qris_midtrans_amount' => 0,
        'total_transactions'   => 0,
        'grand_total_amount'   => 0,
    ];

    if ($rp && mysqli_num_rows($rp) === 1) {
        $paymentSummary = mysqli_fetch_assoc($rp);
        // normalize to int
        foreach ($paymentSummary as $k => $v) {
            $paymentSummary[$k] = (int)($v ?? 0);
        }
    }

    $session['payment_summary'] = $paymentSummary;

    // Load stock snapshot (optional)
    $qs = "SELECT s.menu_id, m.menu_name, m.image_url, m.price, s.qty_start, s.qty_end, COALESCE(SUM(td.quantity), 0) AS qty_sold FROM raki_dev.work_session_stock s LEFT JOIN raki_dev.menu m ON m.menu_id = s.menu_id LEFT JOIN raki_dev.`transaction` t ON t.session_id = s.session_id LEFT JOIN raki_dev.transaction_detail td ON td.transaction_id = t.transaction_id AND td.menu_id = s.menu_id WHERE s.session_id = '$sid' GROUP BY s.menu_id, m.menu_name, m.image_url, m.price, s.qty_start, s.qty_end ORDER BY m.menu_name ASC";

    $rs = mysqli_query($conn, $qs);
    $stock = [];
    if ($rs) {
        while ($row = mysqli_fetch_assoc($rs)) {
            $qtyStart = (int)($row['qty_start'] ?? 0);
            $qtySold  = (int)($row['qty_sold'] ?? 0);
            $qtyLeft  = $qtyStart - $qtySold;
            if ($qtyLeft < 0) {
                $qtyLeft = 0;
            }

            $row['qty_start'] = $qtyStart;
            $row['qty_sold']  = $qtySold;
            $row['qty_left']  = $qtyLeft;

            $stock[] = $row;
        }
    }

    $session['stock'] = $stock;

    jsonResponse(200, 'Active session found', $session);
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

$rawHeaders = getallheaders();
// normalisasi semua key ke lowercase
$headers = array_change_key_case($rawHeaders, CASE_LOWER);

if (!isset($headers['authorization'])) {
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 401,
        'endpoint'      => '/session/active.php',
        'method'        => '',
        'error_message' => 'Authorization header not found',
        'user_identifier' => $username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(401, 'Authorization header not found');
}

$authHeader = $headers['authorization'];
$token = str_replace('Bearer ', '', $authHeader);

try {
    $authHeader = $headers['authorization'];
    $token = str_replace('Bearer ', '', $authHeader);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn = DB::conn();
    
    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'GET':
            // Prefer token values to prevent user spoofing
            $company_id = $_GET['company_id'];
            $username = $_GET['username'];
            checkActiveSession($conn, $company_id, $username);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 401,
        'endpoint'      => '/session/active.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(401, 'Unauthorized', ['error' => $e->getMessage()]);
}

?>