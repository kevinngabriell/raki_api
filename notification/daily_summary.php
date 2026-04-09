<?php

require_once(__DIR__ . '/../connection/db.php');
require_once(__DIR__ . '/../vendor/autoload.php');
require_once(__DIR__ . '/../general.php');
require_once(__DIR__ . '/../config.php');
require_once(__DIR__ . '/../log.php');
require_once(__DIR__ . '/notification.php');

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

    if (!$isDebug && $hour !== 23) {
        http_response_code(403);
        echo json_encode([
            'status_code'    => 403,
            'status_message' => 'Daily summary can only be sent at 23:00 WIB.',
        ]);
        exit;
    }
}

// --- CLI: log start ---
if ($isCli) {
    echo "[" . $now->format('Y-m-d H:i:s') . "] Daily summary cron started\n";
}

// --- Main logic ---
$conn   = DB::conn();
$appId  = '06660e87-37e7-491b-92c3-c772130eb57c';
$schema = DB_SCHEMA;
$today  = $now->format('Y-m-d');

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

    // Daily summary: per-driver stats for today
    $sqlDaily = "
        SELECT
            t.created_by,
            SUM(DISTINCT t.total_amount)      AS total_amount
        FROM {$schema}.transaction t
        JOIN {$schema}.transaction_detail td ON td.transaction_id = t.transaction_id
        WHERE t.company_id = ?
          AND td.menu_id <> 'menu69112f46968b3'
          AND DATE(t.transaction_date) = ?
        GROUP BY t.created_by
        ORDER BY t.created_by
    ";

    $stmtDaily = $conn->prepare($sqlDaily);
    if (!$stmtDaily) {
        $errMsg = 'Database error: unable to prepare daily summary query.';
        if ($isCli) {
            echo "[" . $now->format('Y-m-d H:i:s') . "] ERROR [{$companyId}]: {$errMsg}\n";
        }
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/notification/daily_summary.php',
            'method'          => '',
            'error_message'   => $errMsg,
            'user_identifier' => null,
            'company_id'      => $companyId,
        ]);
        $results[] = ['company_id' => $companyId, 'success' => false, 'error' => $errMsg];
        continue;
    }

    $stmtDaily->bind_param('ss', $companyId, $today);
    $stmtDaily->execute();
    $resultDaily = $stmtDaily->get_result();

    $rows           = [];
    $totalAmountAll = 0;

    while ($row = $resultDaily->fetch_assoc()) {
        $rows[]          = $row;
        $totalAmountAll += (int) $row['total_amount'];
    }
    $stmtDaily->close();

    // Skip sending if no transactions today
    if (empty($rows)) {
        if ($isCli) {
            echo "[" . $now->format('Y-m-d H:i:s') . "] SKIP [{$companyId}]: No transactions today\n";
        }
        $results[] = ['company_id' => $companyId, 'skipped' => true, 'reason' => 'No transactions today'];
        continue;
    }

    $driverCount = count($rows);
    $avgAmount   = $driverCount > 0 ? (int) round($totalAmountAll / $driverCount) : 0;

    $lines   = [];
    $lines[] = "Halo, tim *Raki* 👋";
    $lines[] = "";

    if (!empty($companyName)) {
        $lines[] = "Berikut rekap transaksi harian untuk outlet *{$companyName}*.";
        $lines[] = "";
    }

    $lines[] = "Tanggal: *" . $now->format('d M Y') . "*";
    $lines[] = "";
    $lines[] = "Driver aktif hari ini: *{$driverCount} driver*";
    $lines[] = "Rata-rata per driver: *" . formatRupiah($avgAmount) . "*";
    $lines[] = "";
    $lines[] = "Silakan dicek melalui *Dashboard Raki* pada menu *Transaksi*.";
    $lines[] = "";
    $lines[] = "Terima kasih dan semangat selalu! ☕😊";

    $text     = implode("\n", $lines);
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
        'drivers'      => $driverCount,
        'avg_amount'   => $avgAmount,
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
        ? 'Daily summary processed. Check per-company results.'
        : 'Failed to send daily summary for all companies.';

    http_response_code($statusCode);
    echo json_encode([
        'status_code'    => $statusCode,
        'status_message' => $statusMessage,
        'date'           => $today,
        'results'        => $results,
    ]);
}

exit;
