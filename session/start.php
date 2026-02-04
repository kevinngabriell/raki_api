<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function startSession($conn, $input, $token_username, $decoded){

    // Use company_id from payload if provided, otherwise fallback to token
    $company_id = $input['company_id'] ?? ($decoded->company_id ?? null);
    $cash_start = $input['cash_start'] ?? null;
    $stock      = $input['stock'] ?? null; // array of {menu_id, qty_start}

    if (!$company_id) {
        jsonResponse(400, 'company_id is required');
    }

    if ($cash_start === null || $cash_start === '') {
        jsonResponse(400, 'cash_start is required');
    }

    if (!is_array($stock) || count($stock) === 0) {
        jsonResponse(400, 'stock is required (array)');
    }

    $company_id_esc = mysqli_real_escape_string($conn, $company_id);
    $user_id_esc    = mysqli_real_escape_string($conn, $token_username);
    $cash_start_int = (int)$cash_start;

    // 1) check active session first
    $check = "SELECT session_id FROM raki_dev.work_session WHERE company_id='$company_id_esc' AND user_id='$user_id_esc' AND status='active' ORDER BY started_at DESC LIMIT 1";
    $rc = mysqli_query($conn, $check);

    if (!$rc) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/session/start.php',
            'method'        => 'POST',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);


        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($rc) === 1) {
        $row = mysqli_fetch_assoc($rc);

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

    // 2) begin transaction
    mysqli_begin_transaction($conn);

    try {
        $session_id = 'ses_' . bin2hex(random_bytes(10));

        $ins = "INSERT INTO raki_dev.work_session
                  (session_id, company_id, user_id, started_at, cash_start, status, created_at)
                VALUES
                  ('$session_id', '$company_id_esc', '$user_id_esc', NOW(), $cash_start_int, 'active', NOW())";

        if (!mysqli_query($conn, $ins)) {
            
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 400,
                'endpoint'      => '/session/start.php',
                'method'        => 'POST',
                'error_message' => mysqli_error($conn),
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);

            throw new Exception('Failed to insert work_session: ' . mysqli_error($conn));
        }

        // Insert stock snapshot
        foreach ($stock as $it) {
            if (!is_array($it)) continue;

            $menu_id = $it['menu_id'] ?? null;
            $qty     = $it['qty_start'] ?? null;

            if (!$menu_id || $qty === null || $qty === '') {
                continue;
            }

            $menu_id_esc = mysqli_real_escape_string($conn, $menu_id);
            $qty_int     = (int)$qty;

            $ssid = 'wss_' . bin2hex(random_bytes(10));

            $insS = "INSERT INTO raki_dev.work_session_stock (session_stock_id, session_id, menu_id, qty_start, created_at) VALUES ('$ssid', '$session_id', '$menu_id_esc', $qty_int, NOW())";

            if (!mysqli_query($conn, $insS)) {
                
                logApiError($conn, [
                    'error_level'   => 'error',
                    'http_status'   => 400,
                    'endpoint'      => '/session/start.php',
                    'method'        => 'POST',
                    'error_message' => mysqli_error($conn),
                    'user_identifier' => $decoded->username ?? null,
                    'company_id'      => $decoded->company_id ?? null,
                ]);

                throw new Exception('Failed to insert stock: ' . mysqli_error($conn));
            }
        }

        mysqli_commit($conn);

        // Load back session + stock
        $qsess = "SELECT session_id, company_id, user_id, started_at, ended_at, cash_start, cash_end, status
                  FROM raki_dev.work_session
                  WHERE session_id='$session_id'
                  LIMIT 1";
        $rsess = mysqli_query($conn, $qsess);
        $session = $rsess ? mysqli_fetch_assoc($rsess) : null;

        $qstock = "SELECT s.menu_id, m.menu_name, s.qty_start, s.qty_end
                   FROM raki_dev.work_session_stock s
                   LEFT JOIN raki_dev.menu m ON m.menu_id = s.menu_id
                   WHERE s.session_id='$session_id'
                   ORDER BY m.menu_name ASC";
        $rstock = mysqli_query($conn, $qstock);
        $stockList = [];
        if ($rstock) {
            while ($row = mysqli_fetch_assoc($rstock)) {
                $stockList[] = $row;
            }
        }

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

        jsonResponse(500, 'Failed to start session', ['error' => $e->getMessage()]);

    }
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$rawHeaders = getallheaders();
// normalisasi semua key ke lowercase
$headers = array_change_key_case($rawHeaders, CASE_LOWER);

if (!isset($headers['authorization'])) {
    // untuk debug sementara, bisa log semua header:
    // error_log('HEADERS: ' . print_r($rawHeaders, true));
    jsonResponse(401, 'Authorization header not found');
}

$authHeader = $headers['authorization'];
$token = str_replace('Bearer ', '', $authHeader);


if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
    http_response_code(200);
    exit();
}

try {
    $authHeader = $headers['authorization'];
    $token = str_replace('Bearer ', '', $authHeader);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn = DB::conn();
    
    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            startSession($conn, $input, $token_username, $decoded);
            break;
        case 'GET':
            break;
        case 'PUT':
            break;
        case 'DELETE':
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }

} catch (Exception $e){
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>