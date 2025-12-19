<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

function checkActiveDriverStocks($conn, $company_id){
    // company_id is optional for public endpoint; if provided, we filter.
    $whereCompany = '';
    if ($company_id !== null && $company_id !== '') {
        $company_id_esc = mysqli_real_escape_string($conn, $company_id);
        $whereCompany = " AND ws.company_id = '$company_id_esc' ";
    }

    $q = "
        SELECT
          ws.session_id,
          MAX(ws.started_at) AS started_at,
          COALESCE(MAX(au.username), MAX(ws.user_id)) AS username,
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
        WHERE ws.status = 'active'
          $whereCompany
        GROUP BY ws.session_id, m.menu_id
        ORDER BY ws.started_at ASC, m.menu_name ASC
    ";

    $r = mysqli_query($conn, $q);
    if (!$r) {
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    // Mask helper for public display (username is phone number)
    $maskPhone = function($username) {
        $u = (string)$username;
        if (strlen($u) <= 6) return $u;
        $start = substr($u, 0, 4);
        $end   = substr($u, -3);
        return $start . '****' . $end;
    };

    $sessions = [];

    while ($row = mysqli_fetch_assoc($r)) {
        $sid = $row['session_id'];

        if (!isset($sessions[$sid])) {
            $sessions[$sid] = [
                'session_id' => $sid,
                'started_at' => $row['started_at'],
                'driver_display' => 'Abang ' . $maskPhone($row['username']),
                'menus' => []
            ];
        }

        $qtyStart = (int)$row['qty_start'];
        $qtySold  = (int)$row['qty_sold'];
        $qtyLeft  = $qtyStart - $qtySold;
        if ($qtyLeft < 0) $qtyLeft = 0;

        $sessions[$sid]['menus'][] = [
            'menu_id' => $row['menu_id'],
            'menu_name' => $row['menu_name'],
            'category_name' => $row['category_name'],
            'thumb_url' => $row['thumb_url'],
            'qty_left' => $qtyLeft,
        ];
    }

    jsonResponse(200, 'Active drivers + stock found', array_values($sessions));
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
    http_response_code(200);
    exit();
}

try {
    $conn = DB::conn();
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'POST':
            break;
        case 'GET':
            $company_id = $_GET['company_id'] ?? null;
            checkActiveDriverStocks($conn, $company_id);
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