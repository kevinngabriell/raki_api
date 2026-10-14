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

function startSession($conn, $schema, $input, $token_username, $decoded) {
    $company_id = $input['company_id'] ?? ($decoded->company_id ?? null);
    $cash_start = $input['cash_start'] ?? null;
    $stock      = $input['stock'] ?? null;

    if (!$company_id) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/session/start.php',
            'method'        => 'POST',
            'error_message' => 'company_id is required',
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'company_id is required');
    }

    if ($cash_start === null || $cash_start === '') {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/session/start.php',
            'method'        => 'POST',
            'error_message' => 'cash_start is required',
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'cash_start is required');
    }

    if (!is_array($stock) || count($stock) === 0) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/session/start.php',
            'method'        => 'POST',
            'error_message' => 'stock is required (array)',
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'stock is required (array)');
    }

    $cash_start_int = (int)$cash_start;

    // 1) check active session first
    $stmtCheck = $conn->prepare("SELECT session_id FROM {$schema}.work_session WHERE company_id = ? AND user_id = ? AND status = 'active' ORDER BY started_at DESC LIMIT 1");
    if (!$stmtCheck) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/session/start.php',
            'method'        => 'POST',
            'error_message' => 'Database query error',
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(500, 'Database query error');
    }
    $stmtCheck->bind_param('ss', $company_id, $token_username);
    $stmtCheck->execute();
    $rc = $stmtCheck->get_result();

    if ($rc && $rc->num_rows === 1) {
        $row = $rc->fetch_assoc();
        $stmtCheck->close();

        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 409,
            'endpoint'      => '/session/start.php',
            'method'        => 'POST',
            'error_message' => 'You already have an active session',
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);

        jsonResponse(409, 'You already have an active session', ['session_id' => $row['session_id']]);
    }
    $stmtCheck->close();

    // 2) begin transaction
    mysqli_begin_transaction($conn);

    try {
        $session_id = 'ses_' . bin2hex(random_bytes(10));

        $stmtIns = $conn->prepare("INSERT INTO {$schema}.work_session (session_id, company_id, user_id, started_at, cash_start, status, created_at) VALUES (?, ?, ?, NOW(), ?, 'active', NOW())");
        if (!$stmtIns) {
            throw new Exception('Failed to prepare work_session insert');
        }
        $stmtIns->bind_param('sssi', $session_id, $company_id, $token_username, $cash_start_int);
        if (!$stmtIns->execute()) {
            $stmtIns->close();
            throw new Exception('Failed to insert work_session');
        }
        $stmtIns->close();

        // Insert stock snapshot
        $stmtStock = $conn->prepare("INSERT INTO {$schema}.work_session_stock (session_stock_id, session_id, menu_id, qty_start, created_at) VALUES (?, ?, ?, ?, NOW())");
        if (!$stmtStock) {
            throw new Exception('Failed to prepare stock insert');
        }

        foreach ($stock as $it) {
            if (!is_array($it)) continue;

            $menu_id = $it['menu_id'] ?? null;
            $qty     = $it['qty_start'] ?? null;

            if (!$menu_id || $qty === null || $qty === '') {
                continue;
            }

            $qty_int = (int)$qty;
            $ssid = 'wss_' . bin2hex(random_bytes(10));

            $stmtStock->bind_param('sssi', $ssid, $session_id, $menu_id, $qty_int);
            if (!$stmtStock->execute()) {
                $stmtStock->close();
                throw new Exception('Failed to insert stock');
            }
        }
        $stmtStock->close();

        mysqli_commit($conn);

        // Load back session + stock
        $stmtSess = $conn->prepare("SELECT session_id, company_id, user_id, started_at, ended_at, cash_start, cash_end, status FROM {$schema}.work_session WHERE session_id = ? LIMIT 1");
        $stmtSess->bind_param('s', $session_id);
        $stmtSess->execute();
        $rsess = $stmtSess->get_result();
        $session = $rsess ? $rsess->fetch_assoc() : null;
        $stmtSess->close();

        $stmtStockList = $conn->prepare("SELECT s.menu_id, m.menu_name, s.qty_start, s.qty_end FROM {$schema}.work_session_stock s LEFT JOIN {$schema}.menu m ON m.menu_id = s.menu_id WHERE s.session_id = ? ORDER BY m.menu_name ASC");
        $stmtStockList->bind_param('s', $session_id);
        $stmtStockList->execute();
        $rstock = $stmtStockList->get_result();
        $stockList = [];
        if ($rstock) {
            while ($row = $rstock->fetch_assoc()) {
                $stockList[] = $row;
            }
        }
        $stmtStockList->close();

        if (is_array($session)) {
            $session['stock'] = $stockList;
        }

        jsonResponse(200, 'Session started', $session);

    } catch (Exception $e) {
        mysqli_rollback($conn);

        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/session/start.php',
            'method'        => 'POST',
            'error_message' => $e->getMessage(),
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);

        jsonResponse(500, 'Failed to start session');
    }
}

$decoded = requireAuth();

$conn = DB::conn();
$schema = DB_SCHEMA;
$token_username = $decoded->username;
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        startSession($conn, $schema, $input, $token_username, $decoded);
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
