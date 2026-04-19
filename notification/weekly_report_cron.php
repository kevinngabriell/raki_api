<?php

require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../log.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/../dashboard/weekly_report_data.php';
require_once __DIR__ . '/weekly_report_email.php';

// ── Context detection ─────────────────────────────────────────────────────────

$isCli  = php_sapi_name() === 'cli';
$isHttp = !$isCli
    && isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__);

if (!$isCli && !$isHttp) {
    return;
}

// ── HTTP guard: require cron secret to avoid public access ────────────────────

if ($isHttp) {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status_code' => 405, 'status_message' => 'Method not allowed.']);
        exit;
    }

    $cronSecret = $_ENV['CRON_SECRET'] ?? '';
    $provided   = $_GET['secret'] ?? '';

    if (empty($cronSecret) || $provided !== $cronSecret) {
        http_response_code(403);
        echo json_encode(['status_code' => 403, 'status_message' => 'Forbidden.']);
        exit;
    }
}

// ── Setup ─────────────────────────────────────────────────────────────────────

date_default_timezone_set('Asia/Jakarta');

$now    = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$conn   = DB::conn();
$schema = DB_SCHEMA;
$appId  = '06660e87-37e7-491b-92c3-c772130eb57c';

if ($isCli) {
    echo '[' . $now->format('Y-m-d H:i:s') . '] Weekly report cron started' . PHP_EOL;
}

// ── Resolve date range: previous Mon–Sun ──────────────────────────────────────

$dayOfWeek  = (int)$now->format('N');
$lastSunday = clone $now;
$lastSunday->modify("-{$dayOfWeek} days");
$lastMonday = clone $lastSunday;
$lastMonday->modify('-6 days');

$startDate = $lastMonday->format('Y-m-d');
$endDate   = $lastSunday->format('Y-m-d');

if ($isCli) {
    echo '[' . $now->format('Y-m-d H:i:s') . '] Period: ' . $startDate . ' → ' . $endDate . PHP_EOL;
}

// ── Fetch business owner users with email ─────────────────────────────────────

$sql = "SELECT
            au.user_id,
            au.username,
            au.first_name,
            au.email,
            au.company_id
        FROM movira_core_dev.app_user au
        JOIN movira_core_dev.app_role ar ON ar.app_role_id = au.app_role_id
        WHERE au.app_id          = ?
          AND au.company_id      IS NOT NULL
          AND au.email           IS NOT NULL
          AND au.email           != ''
          AND LOWER(ar.role_name) LIKE '%owner%'
        ORDER BY au.company_id";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $msg = 'Failed to prepare owner query: ' . $conn->error;
    if ($isCli) {
        echo '[' . $now->format('Y-m-d H:i:s') . '] ERROR: ' . $msg . PHP_EOL;
    } else {
        http_response_code(500);
        echo json_encode(['status_code' => 500, 'status_message' => $msg]);
    }
    exit;
}

$stmt->bind_param('s', $appId);
$stmt->execute();
$result  = $stmt->get_result();
$targets = [];

while ($row = $result->fetch_assoc()) {
    $targets[] = $row;
}
$stmt->close();

if (empty($targets)) {
    $msg = 'No verified business owner users with email found.';
    if ($isCli) {
        echo '[' . $now->format('Y-m-d H:i:s') . '] WARNING: ' . $msg . PHP_EOL;
    } else {
        http_response_code(404);
        echo json_encode(['status_code' => 404, 'status_message' => $msg]);
    }
    exit;
}

if ($isCli) {
    echo '[' . $now->format('Y-m-d H:i:s') . '] Found ' . count($targets) . ' recipient(s)' . PHP_EOL;
}

// ── Send report to each owner ─────────────────────────────────────────────────

$results    = [];
$anySuccess = false;

foreach ($targets as $user) {
    $recipientName = !empty($user['first_name']) ? $user['first_name'] : $user['username'];
    $email         = $user['email'];
    $companyId     = $user['company_id'];

    if ($isCli) {
        echo '[' . $now->format('Y-m-d H:i:s') . '] Sending to ' . $email . ' (company: ' . $companyId . ')' . PHP_EOL;
    }

    $result = sendWeeklyReportEmail($conn, $schema, $email, $recipientName, $companyId, $startDate, $endDate);

    if ($result['success']) {
        $anySuccess = true;
    }

    if ($isCli) {
        $label = $result['success'] ? 'OK' : 'FAILED — ' . ($result['error'] ?? 'unknown');
        echo '[' . $now->format('Y-m-d H:i:s') . '] ' . $email . ': ' . $label . PHP_EOL;
    }

    $results[] = [
        'email'      => $email,
        'company_id' => $companyId,
        'success'    => $result['success'],
        'error'      => $result['error'] ?? null,
    ];
}

// ── Output ────────────────────────────────────────────────────────────────────

if ($isCli) {
    echo '[' . $now->format('Y-m-d H:i:s') . '] Done. Overall success: ' . ($anySuccess ? 'YES' : 'NO') . PHP_EOL;
} else {
    $statusCode = $anySuccess ? 200 : 500;
    http_response_code($statusCode);
    echo json_encode([
        'status_code'    => $statusCode,
        'status_message' => $anySuccess ? 'Weekly reports sent.' : 'All sends failed.',
        'period'         => ['start' => $startDate, 'end' => $endDate],
        'results'        => $results,
    ], JSON_PRETTY_PRINT);
}

exit;
?>
