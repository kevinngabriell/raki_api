<?php

require_once(__DIR__ . '/../connection/db.php');
require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/../general.php');
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../log.php');

function sendWhatsAppText($chatId, $text, $session = WAHA_SESSION) {
    $conn = DB::conn();

    if (!function_exists('curl_init')) {
        return [
            'error'   => true,
            'message' => 'curl_init MISSING',
            'php'     => PHP_VERSION,
            'sapi'    => php_sapi_name(),
        ];
    }

    $url = rtrim(WAHA_BASE_URL, '/') . '/api/sendText';

    $payload = [
        'chatId'  => $chatId,
        'text'    => $text,
        'session' => $session,
    ];

    $ch = curl_init($url);

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
        error_log('WAHA CURL error: ' . $error);
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/notification/notification.php',
            'method'          => '',
            'error_message'   => $error,
            'user_identifier' => null,
            'company_id'      => null,
        ]);
        return [
            'success'  => false,
            'httpCode' => $httpCode,
            'error'    => $error,
            'raw'      => $responseBody,
        ];
    }

    $json = json_decode($responseBody, true);

    return [
        'success'  => $httpCode >= 200 && $httpCode < 300,
        'httpCode' => $httpCode,
        'data'     => $json,
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
    // Being included by another file, do nothing
    return;
}

date_default_timezone_set('Asia/Jakarta');
$now       = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$dayOfWeek = (int) $now->format('N'); // 1=Mon ... 6=Sat ... 7=Sun
$hour      = (int) $now->format('G'); // 0-23

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

    if (!$isDebug && !($dayOfWeek === 6 && $hour === 21)) {
        http_response_code(403);
        echo json_encode([
            'status_code'    => 403,
            'status_message' => 'WhatsApp recap can only be sent on Saturday at 21:00 WIB.',
        ]);
        exit;
    }
}

// --- CLI: log start ---
if ($isCli) {
    echo "[" . $now->format('Y-m-d H:i:s') . "] Cron started\n";
}

// --- Main logic ---
$conn  = DB::conn();
$appId = '06660e87-37e7-491b-92c3-c772130eb57c';
$targets = [];

if ($isHttp && isset($_GET['company_id']) && trim($_GET['company_id']) !== '') {
    // HTTP single-company mode
    $companyId = trim($_GET['company_id']);

    $sqlPhone = "SELECT company_id, company_name, pic_contact FROM movira_core_dev.app_company WHERE company_id = ?";
    $stmtPhone = $conn->prepare($sqlPhone);
    if (!$stmtPhone) {
        http_response_code(500);
        echo json_encode(['status_code' => 500, 'status_message' => 'Database error: unable to prepare phone query.']);
        exit;
    }
    $stmtPhone->bind_param('s', $companyId);
    $stmtPhone->execute();
    $resultPhone = $stmtPhone->get_result();
    $rowPhone    = $resultPhone->fetch_assoc();
    $stmtPhone->close();

    if (!$rowPhone || empty($rowPhone['pic_contact'])) {
        http_response_code(404);
        echo json_encode(['status_code' => 404, 'status_message' => 'PIC WhatsApp number not found for this company_id.']);
        exit;
    }

    $targets[] = [
        'company_id'   => $rowPhone['company_id'],
        'company_name' => $rowPhone['company_name'] ?? '',
        'pic_contact'  => $rowPhone['pic_contact'],
    ];

} else {
    // Cron / HTTP all-companies mode
    $sqlCompanies = "SELECT company_id, company_name, pic_contact FROM movira_core_dev.app_company WHERE app_id = ?";
    $stmtCompanies = $conn->prepare($sqlCompanies);
    if (!$stmtCompanies) {
        $msg = 'Database error: unable to prepare company list query.';
        if ($isCli) {
            echo "[" . $now->format('Y-m-d H:i:s') . "] ERROR: {$msg}\n";
        } else {
            http_response_code(500);
            echo json_encode(['status_code' => 500, 'status_message' => $msg]);
        }
        exit;
    }
    $stmtCompanies->bind_param('s', $appId);
    $stmtCompanies->execute();
    $resultCompanies = $stmtCompanies->get_result();

    while ($row = $resultCompanies->fetch_assoc()) {
        if (!empty($row['pic_contact'])) {
            $targets[] = [
                'company_id'   => $row['company_id'],
                'company_name' => $row['company_name'] ?? '',
                'pic_contact'  => $row['pic_contact'],
            ];
        }
    }
    $stmtCompanies->close();

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
$schema     = DB_SCHEMA;

foreach ($targets as $target) {
    $companyId   = $target['company_id'];
    $companyName = $target['company_name'];
    $picContact  = $target['pic_contact'];

    if ($isCli) {
        echo "[" . $now->format('Y-m-d H:i:s') . "] Processing company: {$companyName} ({$companyId})\n";
    }

    $sqlRecap = "
        SELECT
            t.created_by,
            SUM(td.quantity)               AS total_cup,
            SUM(DISTINCT t.total_amount)   AS total_amount
        FROM {$schema}.transaction t
        JOIN {$schema}.transaction_detail td ON td.transaction_id = t.transaction_id
        WHERE t.company_id = ?
          AND td.menu_id <> 'menu69112f46968b3'
          AND YEARWEEK(t.transaction_date, 1) = YEARWEEK(CURDATE(), 1)
        GROUP BY t.created_by
        ORDER BY t.created_by
    ";

    $stmtRecap = $conn->prepare($sqlRecap);
    if (!$stmtRecap) {
        $errMsg = 'Database error: unable to prepare recap query.';
        if ($isCli) {
            echo "[" . $now->format('Y-m-d H:i:s') . "] ERROR [{$companyId}]: {$errMsg}\n";
        }
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/notification/notification.php',
            'method'          => '',
            'error_message'   => $errMsg,
            'user_identifier' => null,
            'company_id'      => $companyId,
        ]);
        $results[] = ['company_id' => $companyId, 'success' => false, 'error' => $errMsg];
        continue;
    }

    $stmtRecap->bind_param('s', $companyId);
    $stmtRecap->execute();
    $resultRecap = $stmtRecap->get_result();

    $rows           = [];
    $totalCupAll    = 0;
    $totalAmountAll = 0;

    while ($row = $resultRecap->fetch_assoc()) {
        $rows[]          = $row;
        $totalCupAll    += (int) $row['total_cup'];
        $totalAmountAll += (int) $row['total_amount'];
    }
    $stmtRecap->close();

    if (empty($rows)) {
        $text  = "Halo, tim *Raki* 👋\n\n";
        if (!empty($companyName)) {
            $text .= "Berikut rekap transaksi mingguan untuk outlet *{$companyName}*.\n\n";
        }
        $text .= "Periode minggu ini: *" . $now->format('d M Y') . "*\n\n";
        $text .= "Belum ada transaksi yang tercatat minggu ini.\n\n";
        $text .= "Silakan dipantau kembali melalui *Dashboard Raki* ya.\n\n";
        $text .= "Terima kasih dan semangat selalu! ☕😊";
    } else {
        $textLines   = [];
        $textLines[] = "Halo, tim *Raki* 👋";
        $textLines[] = "";

        if (!empty($companyName)) {
            $textLines[] = "Berikut rekap transaksi mingguan untuk outlet *{$companyName}*.";
            $textLines[] = "";
        }

        $textLines[] = "Periode minggu ini: *" . $now->format('d M Y') . "*";
        $textLines[] = "";

        foreach ($rows as $r) {
            $driver      = $r['created_by'] ?: '-';
            $cups        = (int) $r['total_cup'];
            $amount      = formatRupiah((int) $r['total_amount']);
            $textLines[] = "*{$driver}* — {$cups} cup (Total: {$amount})";
        }

        $textLines[] = "";
        $textLines[] = "*TOTAL:* {$totalCupAll} cup — " . formatRupiah($totalAmountAll);
        $textLines[] = "";
        $textLines[] = "Silakan dicek melalui *Dashboard Raki* pada menu *Transaksi*.";
        $textLines[] = "";
        $textLines[] = "Terima kasih dan semangat selalu! ☕😊";

        $text = implode("\n", $textLines);
    }

    $waResult = sendWhatsAppText($picContact, $text);
    $success  = $waResult['success'] ?? false;

    if ($success) {
        $anySuccess = true;
    }

    if ($isCli) {
        $statusLabel = $success ? 'OK' : 'FAILED';
        echo "[" . $now->format('Y-m-d H:i:s') . "] WA send [{$companyId}]: {$statusLabel}\n";
    }

    $results[] = [
        'company_id'   => $companyId,
        'company_name' => $companyName,
        'pic_contact'  => $picContact,
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
        ? 'WhatsApp recap processed. Check per-company results.'
        : 'Failed to send WhatsApp recap for all companies.';

    http_response_code($statusCode);
    echo json_encode([
        'status_code'    => $statusCode,
        'status_message' => $statusMessage,
        'results'        => $results,
    ]);
}

exit;