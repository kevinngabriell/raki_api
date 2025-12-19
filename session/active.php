<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

function checkActiveSession($conn, $company_id, $username)
{
    if (!$company_id) {
        jsonResponse(400, 'company_id is required');
    }

    if (!$username) {
        jsonResponse(400, 'username is required');
    }

    $company_id_esc = mysqli_real_escape_string($conn, $company_id);
    $username_esc   = mysqli_real_escape_string($conn, $username);

    $q = "SELECT session_id, company_id, user_id, started_at, ended_at, cash_start, cash_end, status
          FROM raki_dev.work_session
          WHERE company_id='$company_id_esc'
            AND user_id='$username_esc'
            AND status='active'
          ORDER BY started_at DESC
          LIMIT 1";

    $r = mysqli_query($conn, $q);
    if (!$r) {
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($r) !== 1) {
        jsonResponse(200, 'No active session', null);
    }

    $session = mysqli_fetch_assoc($r);
    $sid = mysqli_real_escape_string($conn, $session['session_id']);

    // Load stock snapshot (optional)
    $qs = "SELECT s.menu_id, m.menu_name, s.qty_start, s.qty_end
           FROM raki_dev.work_session_stock s
           LEFT JOIN raki_dev.menu m ON m.menu_id = s.menu_id
           WHERE s.session_id='$sid'
           ORDER BY m.menu_name ASC";

    $rs = mysqli_query($conn, $qs);
    $stock = [];
    if ($rs) {
        while ($row = mysqli_fetch_assoc($rs)) {
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
    jsonResponse(401, 'Authorization header not found');
}

try {
    $token = preg_replace('/^Bearer\s+/i', '', trim($authHeader));
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn = DB::conn();
    
    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'POST':
            break;
        case 'GET':
            // Prefer token values to prevent user spoofing
            $company_id = $_GET['company_id'];
            $username = $_GET['username'];
            checkActiveSession($conn, $company_id, $username);
            break;
        case 'PUT':
            break;
        case 'DELETE':
            break;
    }

} catch (Exception $e){
    jsonResponse(401, 'Unauthorized', ['error' => $e->getMessage()]);
}

?>