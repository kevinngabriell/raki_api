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

function checkActiveSession($conn, $schema, $company_id, $username) {
    if (!$company_id) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/session/active.php',
            'method'        => '',
            'error_message' => 'company_id is required',
            'user_identifier' => $username ?? null,
            'company_id'      => null,
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
            'company_id'      => null,
        ]);
        jsonResponse(400, 'username is required');
    }

    $stmt = $conn->prepare("SELECT session_id, company_id, user_id, started_at, ended_at, cash_start, cash_end, status FROM {$schema}.work_session WHERE company_id = ? AND user_id = ? AND status = 'active' ORDER BY started_at DESC LIMIT 1");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/session/active.php',
            'method'        => '',
            'error_message' => 'Database query error',
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('ss', $company_id, $username);
    $stmt->execute();
    $r = $stmt->get_result();

    if (!$r || $r->num_rows !== 1) {
        $stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 200,
            'endpoint'      => '/session/active.php',
            'method'        => '',
            'error_message' => 'No active session',
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(200, 'No active session', null);
    }

    $session = $r->fetch_assoc();
    $sid = $session['session_id'];
    $stmt->close();

    // --- Payment summary for this session ---
    $stmtPay = $conn->prepare("SELECT SUM(CASE WHEN tp.payment_method IN ('cash', 'edc_flazz') THEN t.total_amount ELSE 0 END) AS cash_amount, SUM(CASE WHEN tp.payment_method = 'qris' THEN t.total_amount ELSE 0 END) AS qris_amount, SUM(CASE WHEN tp.payment_method = 'transfer' THEN t.total_amount ELSE 0 END) AS transfer_amount, SUM(CASE WHEN tp.payment_method = 'qris_midtrans' THEN t.total_amount ELSE 0 END) AS qris_midtrans_amount, COUNT(t.transaction_id) AS total_transactions, SUM(t.total_amount) AS grand_total_amount FROM {$schema}.transaction_payment tp LEFT JOIN {$schema}.`transaction` t ON tp.transaction_id = t.transaction_id WHERE t.session_id = ?");
    if ($stmtPay) {
        $stmtPay->bind_param('s', $sid);
        $stmtPay->execute();
        $rp = $stmtPay->get_result();
        $stmtPay->close();
    }

    $paymentSummary = [
        'cash_amount'          => 0,
        'qris_amount'          => 0,
        'transfer_amount'      => 0,
        'qris_midtrans_amount' => 0,
        'total_transactions'   => 0,
        'grand_total_amount'   => 0,
    ];

    if (isset($rp) && $rp && $rp->num_rows === 1) {
        $paymentSummary = $rp->fetch_assoc();
        foreach ($paymentSummary as $k => $v) {
            $paymentSummary[$k] = (int)($v ?? 0);
        }
    }

    $session['payment_summary'] = $paymentSummary;

    // Load stock snapshot (optional)
    $stmtStock = $conn->prepare("SELECT s.menu_id, m.menu_name, m.image_url, m.price, s.qty_start, s.qty_end, COALESCE(SUM(td.quantity), 0) AS qty_sold FROM {$schema}.work_session_stock s LEFT JOIN {$schema}.menu m ON m.menu_id = s.menu_id LEFT JOIN {$schema}.`transaction` t ON t.session_id = s.session_id LEFT JOIN {$schema}.transaction_detail td ON td.transaction_id = t.transaction_id AND td.menu_id = s.menu_id WHERE s.session_id = ? GROUP BY s.menu_id, m.menu_name, m.image_url, m.price, s.qty_start, s.qty_end ORDER BY m.menu_name ASC");
    if ($stmtStock) {
        $stmtStock->bind_param('s', $sid);
        $stmtStock->execute();
        $rs = $stmtStock->get_result();
        $stmtStock->close();
    }

    $stock = [];
    if (isset($rs) && $rs) {
        while ($row = $rs->fetch_assoc()) {
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

$decoded = requireAuth();

$conn = DB::conn();
$schema = DB_SCHEMA;
$token_username = $decoded->username;
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $company_id = $_GET['company_id'] ?? null;
        $username = $_GET['username'] ?? null;
        checkActiveSession($conn, $schema, $company_id, $username);
        break;
    default:
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 405,
            'endpoint'      => '/session/active.php',
            'method'        => $method,
            'error_message' => 'Method Not Allowed',
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(405, 'Method Not Allowed');
        break;
}
