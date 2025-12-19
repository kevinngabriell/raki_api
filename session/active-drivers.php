<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

function checkActiveDriverSpesific($conn, $company_id){
    if (!$company_id) {
        jsonResponse(400, 'company_id not found in token');
    }

    $company_id_esc = mysqli_real_escape_string($conn, $company_id);

    // Aggregate per (session_id, menu_id) to be compatible with ONLY_FULL_GROUP_BY
    $q = "
        SELECT
          ws.session_id,
          MAX(ws.company_id) AS company_id,
          MAX(ws.user_id) AS user_id,
          MAX(ws.started_at) AS started_at,
          MAX(ws.ended_at) AS ended_at,
          MAX(ws.cash_start) AS cash_start,
          MAX(ws.cash_end) AS cash_end,
          MAX(ws.status) AS status,

          COALESCE(MAX(au.username), MAX(ws.user_id)) AS username,
          MAX(au.phone_number) AS phone_number,

          m.menu_id,
          MAX(m.menu_name) AS menu_name,
          MAX(cm.category_name) AS category_name,
          MAX(m.thumb_url) AS thumb_url,
          MAX(wss.qty_start) AS qty_start,
          COALESCE(SUM(td.quantity), 0) AS qty_sold
        FROM raki_dev.work_session ws
        LEFT JOIN movira_core_dev.app_user au
          ON au.username = ws.user_id
          OR au.phone_number = ws.user_id
          OR au.user_id = ws.user_id
        JOIN raki_dev.work_session_stock wss ON wss.session_id = ws.session_id
        JOIN raki_dev.menu m ON m.menu_id = wss.menu_id
        LEFT JOIN raki_dev.category_menu cm ON cm.category_id = m.category_id
        LEFT JOIN raki_dev.`transaction` t ON t.session_id = ws.session_id
        LEFT JOIN raki_dev.transaction_detail td
          ON td.transaction_id = t.transaction_id
         AND td.menu_id = m.menu_id
        WHERE ws.company_id = '$company_id_esc'
          AND ws.status = 'active'
        GROUP BY ws.session_id, m.menu_id
        ORDER BY started_at ASC, menu_name ASC
    ";

    $r = mysqli_query($conn, $q);
    if (!$r) {
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
                'menus' => []
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
            'thumb_url' => $row['thumb_url'],
            'qty_start' => $qtyStart,
            'qty_sold' => $qtySold,
            'qty_left' => $qtyLeft,
        ];
    }

    jsonResponse(200, 'Active drivers found', array_values($sessions));
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
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
    $token = str_replace('Bearer ', '', $headers['Authorization']);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn = DB::conn();
    
    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'POST':
            break;
        case 'GET':
            $company_id = $decoded->company_id ?? null;
            checkActiveDriverSpesific($conn, $company_id);
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