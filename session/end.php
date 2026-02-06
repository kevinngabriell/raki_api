<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

// Ensure consistent timezone (WIB)
date_default_timezone_set('Asia/Jakarta');

function formatIndoDateTime($datetimeStr) {
    if (!$datetimeStr) return $datetimeStr;

    $months = [ 1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];

    try {
        $dt = new DateTime($datetimeStr, new DateTimeZone('Asia/Jakarta'));
        $day = (int)$dt->format('j');
        $monthNum = (int)$dt->format('n');
        $year = $dt->format('Y');
        $time = $dt->format('H:i');
        $monthName = $months[$monthNum] ?? $dt->format('m');
        return $day . ' ' . $monthName . ' ' . $year . ' ' . $time;
    } catch (Exception $e) {
        return $datetimeStr;
    }
}

function endSession($conn, $input, $token_username, $decoded){
    // Validate input
    if (!$input || !isset($input['cash_end'])) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/session/end.php',
            'method'        => '',
            'error_message' =>'cash_end is required',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'cash_end is required');
    }

    $cash_end = (int)$input['cash_end'];

    if ($cash_end < 0) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/session/end.php',
            'method'        => '',
            'error_message' =>'cash_end must be >= 0',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'cash_end must be >= 0');
    }

    $company_id = $decoded->company_id ?? null;
    if (!$company_id) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/session/end.php',
            'method'        => '',
            'error_message' =>'company_id not found in token',
            'user_identifier' => $username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'company_id not found in token');
    }

    $company_id_esc = mysqli_real_escape_string($conn, $company_id);
    $user_esc = mysqli_real_escape_string($conn, $token_username);

    // Find active session
    $qSess = "SELECT session_id, started_at, cash_start FROM raki_dev.work_session WHERE company_id='$company_id_esc' AND user_id='$user_esc' AND status='active' ORDER BY started_at DESC LIMIT 1";

    $rSess = mysqli_query($conn, $qSess);
    if (!$rSess) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/session/end.php',
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
            'endpoint'      => '/session/end.php',
            'method'        => 'POST',
            'error_message' => 'No active session found',
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);

        jsonResponse(400, 'No active session found');
    }

    $session = mysqli_fetch_assoc($rSess);
    $session_id = $session['session_id'];
    $started_at = $session['started_at'];
    $cash_start = (int)($session['cash_start'] ?? 0);

    // ---- Compute recap ----

    // Total cups brought
    $qCupStart = "SELECT COALESCE(SUM(qty_start),0) AS total_cup_start FROM raki_dev.work_session_stock WHERE session_id='$session_id'";
    $rCupStart = mysqli_query($conn, $qCupStart);
    $total_cup_start = (int)(mysqli_fetch_assoc($rCupStart)['total_cup_start'] ?? 0);

    // Total cups sold
    $qCupSold = "SELECT COALESCE(SUM(td.quantity),0) AS total_cup_sold FROM raki_dev.transaction t JOIN raki_dev.transaction_detail td ON td.transaction_id=t.transaction_id WHERE t.session_id='$session_id'";
    $rCupSold = mysqli_query($conn, $qCupSold);
    $total_cup_sold = (int)(mysqli_fetch_assoc($rCupSold)['total_cup_sold'] ?? 0);

    // Total transactions
    $qTrx = "SELECT COUNT(*) AS total_trx FROM raki_dev.transaction WHERE session_id='$session_id'";
    $rTrx = mysqli_query($conn, $qTrx);
    $total_trx = (int)(mysqli_fetch_assoc($rTrx)['total_trx'] ?? 0);

    // Payment breakdown
    $qPay = "SELECT tp.payment_method, COALESCE(SUM(amount),0) AS total FROM raki_dev.transaction_payment tp JOIN raki_dev.transaction t ON t.transaction_id=tp.transaction_id WHERE t.session_id='$session_id' GROUP BY tp.payment_method";
    $rPay = mysqli_query($conn, $qPay);

    $total_cash = 0;
    $total_qris = 0;

    while ($row = mysqli_fetch_assoc($rPay)) {
        if ($row['payment_method'] === 'cash') $total_cash = (int)$row['total'];
        if ($row['payment_method'] === 'qris') $total_qris = (int)$row['total'];
    }

    $total_sales = $total_cash + $total_qris;

    // Duration (use WIB timezone and guard against negative values)
    $ended_at = date('Y-m-d H:i:s');
    $duration_seconds = strtotime($ended_at) - strtotime($started_at);
    if ($duration_seconds < 0) {
        // Fallback: treat duration as 0 if server timezone mismatch still occurs
        $duration_seconds = 0;
    }
    $duration_hours = floor($duration_seconds / 3600);
    $duration_minutes = floor(($duration_seconds % 3600) / 60);

    // ---- Close session ----
    $conn->begin_transaction();
    try {
        $qEnd = "UPDATE raki_dev.work_session SET ended_at='$ended_at', cash_end=$cash_end, status='closed' WHERE session_id='$session_id'";

        if (!mysqli_query($conn, $qEnd)) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/session/end.php',
                'method'        => 'POST',
                'error_message' => mysqli_error($conn),
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            throw new Exception(mysqli_error($conn));
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/session/end.php',
            'method'        => 'POST',
            'error_message' => $e->getMessage(),
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to close session', ['error' => $e->getMessage()]);
    }

    // ---- Send WhatsApp recap to PIC ----
    require_once '../notification/notification.php';

    $ownerPhone = null;
    $stmtPhone = $conn->prepare("SELECT pic_contact FROM movira_core_dev.app_company WHERE company_id = ?");
    if ($stmtPhone) {
        $stmtPhone->bind_param('s', $company_id);
        if ($stmtPhone->execute()) {
            $resPhone = $stmtPhone->get_result();
            if ($rowPhone = $resPhone->fetch_assoc()) {
                $ownerPhone = preg_replace('/[^0-9]/', '', $rowPhone['pic_contact']);
            }
        }
    }

    if (!empty($ownerPhone)) {
        $chatId = $ownerPhone . '@c.us';

        $started_fmt = formatIndoDateTime($started_at);
        $ended_fmt = formatIndoDateTime($ended_at);

        $text = "REKAP SESI RAKI\n\n"
            . "Driver : $token_username\n"
            . "Mulai : $started_fmt\n"
            . "Selesai : $ended_fmt ($duration_hours jam $duration_minutes menit)\n\n"
            . "Cup dibawa: $total_cup_start\n"
            . "Cup terjual: $total_cup_sold\n"
            . "Total transaksi: $total_trx\n\n"
            . "Cash: Rp " . number_format($total_cash, 0, ',', '.') . "\n"
            . "QRIS: Rp " . number_format($total_qris, 0, ',', '.') . "\n"
            . "Total: Rp " . number_format($total_sales, 0, ',', '.') . "\n\n"
            . "Cash awal: Rp " . number_format($cash_start, 0, ',', '.') . "\n"
            . "Cash akhir: Rp " . number_format($cash_end, 0, ',', '.') . "\n"
            . "Selisih: Rp " . number_format(($cash_end - $cash_start), 0, ',', '.');

        sendWhatsAppText($chatId, $text);
    }

    jsonResponse(200, 'Session closed successfully', [
        'session_id' => $session_id,
        'started_at' => $started_at,
        'ended_at' => $ended_at,
        'duration_minutes' => ($duration_hours * 60) + $duration_minutes,
        'total_cup_start' => $total_cup_start,
        'total_cup_sold' => $total_cup_sold,
        'total_transaction' => $total_trx,
        'total_cash' => $total_cash,
        'total_qris' => $total_qris,
        'total_sales' => $total_sales,
        'cash_start' => $cash_start,
        'cash_end' => $cash_end,
    ]);
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$rawHeaders = getallheaders();
// normalisasi semua key ke lowercase
$headers = array_change_key_case($rawHeaders, CASE_LOWER);

if (!isset($headers['authorization'])) {
    // untuk debug sementara, bisa log semua header:
    // error_log('HEADERS: ' . print_r($rawHeaders, true));
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 401,
        'endpoint'      => '/session/end.php',
        'method'        => 'POST',
        'error_message' => 'Authorization header not found',
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
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
            endSession($conn, $input, $token_username, $decoded);
            break;
        default:
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 401,
                'endpoint'      => '/session/end.php',
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
        'http_status'   => 500,
        'endpoint'      => '/session/end.php',
        'method'        => 'POST',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);    
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}