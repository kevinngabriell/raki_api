<?php
// Sends the Rekap Harian WhatsApp of every outlet whose setting is on and whose send_time has passed today
// (docs/backend-endpoints-revamp.md §3). Call it every ~5 minutes from the scheduler:
//   GET /notification/wa_recap_cron.php?secret=$CRON_SECRET     (same guard as the other cron endpoints)
// last_sent_date makes it send once per day per outlet.
require_once __DIR__ . '/wa_recap_lib.php';

if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    $secret = $_ENV['CRON_SECRET'] ?? '';
    if ($secret === '' || !hash_equals($secret, (string)($_GET['secret'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['status_code' => 403, 'status_message' => 'Forbidden.']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['status_code' => 405, 'status_message' => 'Method not allowed. Use GET.']);
        exit;
    }
}

$conn = DB::conn();
$schema = DB_SCHEMA;
$today = date('Y-m-d');
$nowTime = date('H:i');

$stmt = $conn->prepare("SELECT company_id, destination FROM {$schema}.wa_recap_setting WHERE enabled = 1 AND send_time <= ? AND (last_sent_date IS NULL OR last_sent_date < ?)");
$stmt->bind_param('ss', $nowTime, $today);
$stmt->execute();
$due = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$sent = [];
$failed = [];
foreach ($due as $d) {
    // Claim the day first so an overlapping cron run cannot send it twice.
    $claim = $conn->prepare("UPDATE {$schema}.wa_recap_setting SET last_sent_date = ? WHERE company_id = ? AND (last_sent_date IS NULL OR last_sent_date < ?)");
    $claim->bind_param('sss', $today, $d['company_id'], $today);
    $claim->execute();
    $won = $claim->affected_rows === 1;
    $claim->close();
    if (!$won) continue;

    $res = waRecapSend($conn, $schema, $d['company_id'], $d['destination'], $today);
    if ($res['ok']) {
        $sent[] = $d['company_id'];
    } else {
        $failed[] = $d['company_id'];
        $undo = $conn->prepare("UPDATE {$schema}.wa_recap_setting SET last_sent_date = NULL WHERE company_id = ? AND last_sent_date = ?");
        $undo->bind_param('ss', $d['company_id'], $today);
        $undo->execute();
        $undo->close();
    }
}

$out = ['status_code' => 200, 'status_message' => 'WA recap cron done', 'data' => ['sent' => $sent, 'failed' => $failed]];
echo php_sapi_name() === 'cli' ? json_encode($out) . "\n" : json_encode($out);
