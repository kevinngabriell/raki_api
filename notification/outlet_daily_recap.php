<?php

require_once(__DIR__ . '/../connection/db.php');
require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/../general.php');
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../log.php');
require_once(__DIR__ . '/email.php');

// Recaps sales created by users with the "Outlet" role, once a day at 18:00 WIB.
// Outlet-created transactions no longer trigger a notification per-transaction
// (see transaction/index.php) — this cron sends the daily total instead.

function sendWhatsAppText($chatId, $text, $session = WAHA_SESSION) {
    $conn = DB::conn();

    $url     = rtrim(WAHA_BASE_URL, '/') . '/api/sendText';
    $payload = ['chatId' => $chatId, 'text' => $text, 'session' => $session];

    $ch      = curl_init($url);
    $headers = ['Content-Type: application/json'];
    if (!empty(WAHA_API_KEY)) {
        $headers[] = 'X-Api-Key: ' . WAHA_API_KEY;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 10,
    ]);

    $responseBody = curl_exec($ch);
    $errno        = curl_errno($ch);
    $error        = curl_error($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        error_log("WAHA CURL error: $error");
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/notification/outlet_daily_recap.php',
            'method'          => '',
            'error_message'   => $error,
            'user_identifier' => null,
            'company_id'      => null,
        ]);
        return ['success' => false, 'httpCode' => $httpCode, 'error' => $error, 'raw' => $responseBody];
    }

    return [
        'success'  => $httpCode >= 200 && $httpCode < 300,
        'httpCode' => $httpCode,
        'data'     => json_decode($responseBody, true),
        'raw'      => $responseBody,
    ];
}

function formatRupiah($angka) {
    return 'Rp ' . number_format((int)$angka, 0, ',', '.');
}

// --- Determine execution context ---
$isCli  = php_sapi_name() === 'cli';
$isHttp = !$isCli
    && isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__);

if (!$isCli && !$isHttp) {
    return;
}

date_default_timezone_set('Asia/Jakarta');
$now  = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$hour = (int) $now->format('G'); // 0-23

// --- HTTP-only guards ---
if ($isHttp) {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'status_code'    => 405,
            'status_message' => 'Method not allowed. Use GET.',
        ]);
        exit;
    }

    $isDebug = isset($_GET['debug']) && $_GET['debug'] == '1';

    if (!$isDebug && $hour !== 18) {
        http_response_code(403);
        echo json_encode([
            'status_code'    => 403,
            'status_message' => 'Outlet daily recap can only be sent at 18:00 WIB.',
        ]);
        exit;
    }
}

// --- CLI: log start ---
if ($isCli) {
    echo "[" . $now->format('Y-m-d H:i:s') . "] Outlet daily recap cron started\n";
}

// --- Main logic ---
$conn   = DB::conn();
$appId  = '06660e87-37e7-491b-92c3-c772130eb57c';
$schema = DB_SCHEMA;
$today  = $now->format('Y-m-d');
// Same non-cup menu IDs excluded from cup counts as transaction/index.php.
$excludedMenuIds = ['menu696797b22ff84', 'menu696797bda43f6'];

$targets = [];

if ($isHttp && isset($_GET['company_id']) && trim($_GET['company_id']) !== '') {
    $companyId = trim($_GET['company_id']);

    $stmt = $conn->prepare("SELECT company_id, company_name, pic_contact FROM movira_core_dev.app_company WHERE company_id = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['status_code' => 500, 'status_message' => 'Database error: unable to prepare company query.']);
        exit;
    }
    $stmt->bind_param('s', $companyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['pic_contact'])) {
        http_response_code(404);
        echo json_encode(['status_code' => 404, 'status_message' => 'PIC WhatsApp number not found for this company_id.']);
        exit;
    }

    $targets[] = [
        'company_id'   => $row['company_id'],
        'company_name' => $row['company_name'] ?? '',
        'pic_contact'  => $row['pic_contact'],
    ];

} else {
    $stmt = $conn->prepare("SELECT company_id, company_name, pic_contact FROM movira_core_dev.app_company WHERE app_id = ?");
    if (!$stmt) {
        $msg = 'Database error: unable to prepare company list query.';
        if ($isCli) {
            echo "[" . $now->format('Y-m-d H:i:s') . "] ERROR: {$msg}\n";
        } else {
            http_response_code(500);
            echo json_encode(['status_code' => 500, 'status_message' => $msg]);
        }
        exit;
    }
    $stmt->bind_param('s', $appId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if (!empty($row['pic_contact'])) {
            $targets[] = [
                'company_id'   => $row['company_id'],
                'company_name' => $row['company_name'] ?? '',
                'pic_contact'  => $row['pic_contact'],
            ];
        }
    }
    $stmt->close();

    if (empty($targets)) {
        $msg = 'No companies with PIC contact found for this app_id.';
        if ($isCli) {
            echo "[" . $now->format('Y-m-d H:i:s') . "] WARNING: {$msg}\n";
        } else {
            http_response_code(404);
            echo json_encode(['status_code' => 404, 'status_message' => $msg]);
        }
        exit;
    }
}

if ($isCli) {
    echo "[" . $now->format('Y-m-d H:i:s') . "] Found " . count($targets) . " target(s)\n";
}

$results    = [];
$anySuccess = false;

foreach ($targets as $target) {
    $companyId   = $target['company_id'];
    $companyName = $target['company_name'];
    $picContact  = $target['pic_contact'];

    if ($isCli) {
        echo "[" . $now->format('Y-m-d H:i:s') . "] Processing company: {$companyName} ({$companyId})\n";
    }

    // Totals for today, restricted to transactions created by Outlet-role users.
    $sqlTotals = "
        SELECT COUNT(*) AS trx_count, SUM(t.total_amount) AS total_amount
        FROM {$schema}.transaction t
        JOIN movira_core_dev.app_user u ON u.username = t.created_by
        JOIN movira_core_dev.app_role r ON r.app_role_id = u.app_role_id
        WHERE t.company_id = ?
          AND r.role_name = 'Outlet'
          AND DATE(t.transaction_date) = ?
    ";

    $stmtTotals = $conn->prepare($sqlTotals);
    if (!$stmtTotals) {
        $errMsg = 'Database error: unable to prepare outlet totals query.';
        if ($isCli) {
            echo "[" . $now->format('Y-m-d H:i:s') . "] ERROR [{$companyId}]: {$errMsg}\n";
        }
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/notification/outlet_daily_recap.php',
            'method'          => '',
            'error_message'   => $errMsg,
            'user_identifier' => null,
            'company_id'      => $companyId,
        ]);
        $results[] = ['company_id' => $companyId, 'success' => false, 'error' => $errMsg];
        continue;
    }

    $stmtTotals->bind_param('ss', $companyId, $today);
    $stmtTotals->execute();
    $totalsRow  = $stmtTotals->get_result()->fetch_assoc();
    $stmtTotals->close();

    $trxCount    = (int) ($totalsRow['trx_count'] ?? 0);
    $totalAmount = (int) ($totalsRow['total_amount'] ?? 0);

    // Skip sending if Outlet had no transactions today
    if ($trxCount === 0) {
        if ($isCli) {
            echo "[" . $now->format('Y-m-d H:i:s') . "] SKIP [{$companyId}]: No Outlet transactions today\n";
        }
        $results[] = ['company_id' => $companyId, 'skipped' => true, 'reason' => 'No Outlet transactions today'];
        continue;
    }

    // Cup count, excluding non-cup menu items.
    $excludedPlaceholders = implode(',', array_fill(0, count($excludedMenuIds), '?'));
    $sqlCups = "
        SELECT SUM(td.quantity) AS total_cups
        FROM {$schema}.transaction_detail td
        JOIN {$schema}.transaction t ON t.transaction_id = td.transaction_id
        JOIN movira_core_dev.app_user u ON u.username = t.created_by
        JOIN movira_core_dev.app_role r ON r.app_role_id = u.app_role_id
        WHERE t.company_id = ?
          AND r.role_name = 'Outlet'
          AND DATE(t.transaction_date) = ?
          AND td.menu_id NOT IN ({$excludedPlaceholders})
    ";

    $stmtCups = $conn->prepare($sqlCups);
    $totalCups = 0;
    if ($stmtCups) {
        $cupTypes = 'ss' . str_repeat('s', count($excludedMenuIds));
        $cupParams = array_merge([$companyId, $today], $excludedMenuIds);
        $stmtCups->bind_param($cupTypes, ...$cupParams);
        $stmtCups->execute();
        $cupsRow = $stmtCups->get_result()->fetch_assoc();
        $stmtCups->close();
        $totalCups = (int) ($cupsRow['total_cups'] ?? 0);
    }

    $lines   = [];
    $lines[] = "Halo! 👋";
    $lines[] = "";
    $lines[] = "Berikut adalah rekap penjualan *Raki Coffee* hari ini oleh *Outlet*"
        . (!empty($companyName) ? " *{$companyName}*" : "") . ":";
    $lines[] = "";
    $lines[] = "*Total Transaksi:* {$trxCount} transaksi";
    $lines[] = "*Total Penjualan:* " . formatRupiah($totalAmount);
    $lines[] = "*Jumlah Cup:* {$totalCups} cup";
    $lines[] = "";
    $lines[] = "Detail lengkap dapat dilihat melalui *Dashboard Raki*.";
    $lines[] = "Terima kasih dan semangat selalu! ☕😊";

    $text     = implode("\n", $lines);
    $waResult = sendWhatsAppText($picContact, $text);
    $success  = $waResult['success'] ?? false;

    if (!$success) {
        error_log('Gagal kirim WhatsApp rekap outlet: ' . ($waResult['raw'] ?? ''));

        // Fallback: send email to business owner
        $sqlOwnerEmail = "SELECT u.email FROM movira_core_dev.app_user u JOIN movira_core_dev.app_role r ON r.app_role_id = u.app_role_id WHERE u.company_id = ? AND r.role_name = 'Owner' AND u.email IS NOT NULL AND u.email != '' LIMIT 1";
        $stmtOwnerEmail = $conn->prepare($sqlOwnerEmail);
        if ($stmtOwnerEmail) {
            $stmtOwnerEmail->bind_param('s', $companyId);
            if ($stmtOwnerEmail->execute()) {
                $ownerEmailRow = $stmtOwnerEmail->get_result()->fetch_assoc();
                if ($ownerEmailRow) {
                    $emailSubject = 'Rekap Penjualan Outlet Raki Coffee - ' . $now->format('d M Y');
                    $emailBody    = "<p>Halo!</p>"
                        . "<p>Berikut adalah rekap penjualan <strong>Raki Coffee</strong> hari ini oleh <strong>Outlet</strong>"
                        . (!empty($companyName) ? " <strong>{$companyName}</strong>" : "") . ":</p>"
                        . "<p><strong>Total Transaksi:</strong> {$trxCount} transaksi</p>"
                        . "<p><strong>Total Penjualan:</strong> " . formatRupiah($totalAmount) . "</p>"
                        . "<p><strong>Jumlah Cup:</strong> {$totalCups} cup</p>"
                        . "<p>Detail lengkap dapat dilihat melalui <strong>Dashboard Raki</strong>.</p>"
                        . "<p>Terima kasih dan semangat selalu!</p>";
                    $emailFallback = sendEmail($ownerEmailRow['email'], $emailSubject, $emailBody);
                    if ($emailFallback['success']) {
                        $success = true;
                    } else {
                        error_log('Gagal kirim email fallback rekap outlet: ' . ($emailFallback['error'] ?? ''));
                    }
                }
            }
            $stmtOwnerEmail->close();
        }
    }

    if ($success) {
        $anySuccess = true;
    }

    if ($isCli) {
        $statusLabel = $success ? 'OK' : 'FAILED';
        echo "[" . $now->format('Y-m-d H:i:s') . "] Recap send [{$companyId}]: {$statusLabel}\n";
    }

    $results[] = [
        'company_id'   => $companyId,
        'company_name' => $companyName,
        'pic_contact'  => $picContact,
        'trx_count'    => $trxCount,
        'total_amount' => $totalAmount,
        'total_cups'   => $totalCups,
        'success'      => $success,
        'whatsapp'     => $waResult,
    ];
}

// --- Final output ---
if ($isCli) {
    echo "[" . $now->format('Y-m-d H:i:s') . "] Done. Overall success: " . ($anySuccess ? 'YES' : 'NO') . "\n";
} else {
    $statusCode    = $anySuccess ? 200 : 500;
    $statusMessage = $anySuccess
        ? 'Outlet daily recap processed. Check per-company results.'
        : 'Failed to send outlet daily recap for all companies.';

    http_response_code($statusCode);
    echo json_encode([
        'status_code'    => $statusCode,
        'status_message' => $statusMessage,
        'date'           => $today,
        'results'        => $results,
    ]);
}

exit;
