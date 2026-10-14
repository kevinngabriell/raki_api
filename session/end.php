<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

function endSession($conn, $schema, $input, $token_username, $decoded) {
    $coreSchema = MOVIRA_CORE_SCHEMA;
    if (!$input || !isset($input['cash_end'])) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/session/end.php',
            'method'        => 'POST',
            'error_message' => 'cash_end is required',
            'user_identifier' => $decoded->username ?? null,
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
            'method'        => 'POST',
            'error_message' => 'cash_end must be >= 0',
            'user_identifier' => $decoded->username ?? null,
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
            'method'        => 'POST',
            'error_message' => 'company_id not found in token',
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'company_id not found in token');
    }

    // Find active session
    $stmtSess = $conn->prepare("SELECT session_id, started_at, cash_start FROM {$schema}.work_session WHERE company_id = ? AND user_id = ? AND status = 'active' ORDER BY started_at DESC LIMIT 1");
    if (!$stmtSess) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/session/end.php',
            'method'        => 'POST',
            'error_message' => 'Database query error',
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(500, 'Database query error');
    }
    $stmtSess->bind_param('ss', $company_id, $token_username);
    $stmtSess->execute();
    $rSess = $stmtSess->get_result();

    if (!$rSess || $rSess->num_rows !== 1) {
        $stmtSess->close();
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

    $session = $rSess->fetch_assoc();
    $stmtSess->close();
    $session_id = $session['session_id'];
    $started_at = $session['started_at'];
    $cash_start = (int)($session['cash_start'] ?? 0);

    // ---- Compute recap ----

    // Total cups brought
    $stmtCupStart = $conn->prepare("SELECT COALESCE(SUM(qty_start),0) AS total_cup_start FROM {$schema}.work_session_stock WHERE session_id = ?");
    $stmtCupStart->bind_param('s', $session_id);
    $stmtCupStart->execute();
    $total_cup_start = (int)($stmtCupStart->get_result()->fetch_assoc()['total_cup_start'] ?? 0);
    $stmtCupStart->close();

    // Total cups sold
    $stmtCupSold = $conn->prepare("SELECT COALESCE(SUM(td.quantity),0) AS total_cup_sold FROM {$schema}.transaction t JOIN {$schema}.transaction_detail td ON td.transaction_id=t.transaction_id WHERE t.session_id = ?");
    $stmtCupSold->bind_param('s', $session_id);
    $stmtCupSold->execute();
    $total_cup_sold = (int)($stmtCupSold->get_result()->fetch_assoc()['total_cup_sold'] ?? 0);
    $stmtCupSold->close();

    // Total transactions
    $stmtTrx = $conn->prepare("SELECT COUNT(*) AS total_trx FROM {$schema}.transaction WHERE session_id = ?");
    $stmtTrx->bind_param('s', $session_id);
    $stmtTrx->execute();
    $total_trx = (int)($stmtTrx->get_result()->fetch_assoc()['total_trx'] ?? 0);
    $stmtTrx->close();

    // Payment breakdown
    $stmtPay = $conn->prepare("SELECT tp.payment_method, COALESCE(SUM(amount),0) AS total FROM {$schema}.transaction_payment tp JOIN {$schema}.transaction t ON t.transaction_id=tp.transaction_id WHERE t.session_id = ? GROUP BY tp.payment_method");
    $stmtPay->bind_param('s', $session_id);
    $stmtPay->execute();
    $rPay = $stmtPay->get_result();
    $stmtPay->close();

    $total_cash = 0;
    $total_qris = 0;

    while ($row = $rPay->fetch_assoc()) {
        if ($row['payment_method'] === 'cash' || $row['payment_method'] === 'edc_flazz') $total_cash += (int)$row['total'];
        if ($row['payment_method'] === 'qris') $total_qris += (int)$row['total'];
    }

    $total_sales = $total_cash + $total_qris;

    // Duration
    $ended_at = date('Y-m-d H:i:s');
    $duration_seconds = strtotime($ended_at) - strtotime($started_at);
    if ($duration_seconds < 0) {
        $duration_seconds = 0;
    }
    $duration_hours = floor($duration_seconds / 3600);
    $duration_minutes = floor(($duration_seconds % 3600) / 60);

    // ---- Close session ----
    $conn->begin_transaction();
    try {
        $stmtEnd = $conn->prepare("UPDATE {$schema}.work_session SET ended_at = ?, cash_end = ?, status = 'closed' WHERE session_id = ?");
        if (!$stmtEnd) {
            throw new Exception('Failed to prepare session update');
        }
        $stmtEnd->bind_param('sis', $ended_at, $cash_end, $session_id);
        if (!$stmtEnd->execute()) {
            $stmtEnd->close();
            throw new Exception('Failed to close session');
        }
        $stmtEnd->close();

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
        jsonResponse(500, 'Failed to close session');
    }

    // ---- Send WhatsApp recap to PIC ----
    require_once '../notification/notification.php';

    $ownerPhone = null;
    $stmtPhone = $conn->prepare("SELECT pic_contact FROM {$coreSchema}.app_company WHERE company_id = ?");
    if ($stmtPhone) {
        $stmtPhone->bind_param('s', $company_id);
        if ($stmtPhone->execute()) {
            $resPhone = $stmtPhone->get_result();
            if ($rowPhone = $resPhone->fetch_assoc()) {
                $ownerPhone = preg_replace('/[^0-9]/', '', $rowPhone['pic_contact']);
            }
        }
        $stmtPhone->close();
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

$decoded = requireAuth();

$conn = DB::conn();
$schema = DB_SCHEMA;
$token_username = $decoded->username;
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        endSession($conn, $schema, $input, $token_username, $decoded);
        break;
    default:
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 405,
            'endpoint'      => '/session/end.php',
            'method'        => 'POST',
            'error_message' => 'Method Not Allowed',
            'user_identifier' => $decoded->username ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(405, 'Method Not Allowed');
        break;
}
