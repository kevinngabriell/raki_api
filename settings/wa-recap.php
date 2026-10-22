<?php
// /settings/wa-recap.php - Rekap Harian WhatsApp (docs/backend-endpoints-revamp.md §3). Owner (own outlets) / Mitra (own outlet).
//   GET  ?company_id                      -> { enabled, send_time: "HH:MM", destination, last_sent_date }
//   PUT  { company_id, enabled, send_time, destination? }    destination null / "" = the outlet's PIC contact
//   POST ?action=send  { company_id, date? }                 "kirim sekarang": sends the recap of the day (default today)
// The scheduled send is notification/wa_recap_cron.php.
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once '../notification/wa_recap_lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$decoded = requireAuth();
$conn = DB::conn();
$schema = DB_SCHEMA;

try {
    requireManager($decoded);
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            $companyId = requireCompanyAccess($conn, $decoded, $_GET['company_id'] ?? null);
            $stmt = $conn->prepare("SELECT enabled, send_time, destination, last_sent_date FROM {$schema}.wa_recap_setting WHERE company_id = ?");
            $stmt->bind_param('s', $companyId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            jsonResponse(200, 'WA recap setting fetched', [
                'enabled' => (bool)($row['enabled'] ?? false), 'send_time' => $row['send_time'] ?? '21:00',
                'destination' => $row['destination'] ?? null, 'last_sent_date' => $row['last_sent_date'] ?? null,
            ]);
            break;

        case 'PUT':
            $in = json_decode(file_get_contents('php://input'), true);
            if (!is_array($in)) jsonResponse(400, 'Invalid JSON body');
            $companyId = requireCompanyAccess($conn, $decoded, $in['company_id'] ?? null);
            $enabled = filter_var($in['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            $time = (string)($in['send_time'] ?? '21:00');
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) jsonResponse(400, 'send_time harus berformat HH:MM');
            $dest = null;
            if (isset($in['destination']) && trim((string)$in['destination']) !== '') {
                $dest = waRecapDigits($in['destination']);
                if ($dest === null) jsonResponse(400, 'Nomor WhatsApp tidak valid. Gunakan nomor Indonesia (62…).');
            }
            $by = $decoded->username;
            $stmt = $conn->prepare("INSERT INTO {$schema}.wa_recap_setting (company_id, enabled, send_time, destination, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), send_time = VALUES(send_time), destination = VALUES(destination), updated_by = VALUES(updated_by), updated_at = NOW()");
            $stmt->bind_param('sisss', $companyId, $enabled, $time, $dest, $by);
            $stmt->execute();
            $stmt->close();
            jsonResponse(200, 'Pengaturan rekap disimpan', ['enabled' => (bool)$enabled, 'send_time' => $time, 'destination' => $dest]);
            break;

        case 'POST':
            if (($_GET['action'] ?? '') !== 'send') jsonResponse(400, 'action must be send');
            $in = json_decode(file_get_contents('php://input'), true) ?: [];
            $companyId = requireCompanyAccess($conn, $decoded, $in['company_id'] ?? ($_GET['company_id'] ?? null));
            $date = $in['date'] ?? date('Y-m-d');
            $d = DateTime::createFromFormat('Y-m-d', (string)$date);
            if (!$d || $d->format('Y-m-d') !== $date) jsonResponse(400, 'date harus berformat YYYY-MM-DD');

            $stmt = $conn->prepare("SELECT destination FROM {$schema}.wa_recap_setting WHERE company_id = ?");
            $stmt->bind_param('s', $companyId);
            $stmt->execute();
            $configured = $stmt->get_result()->fetch_row()[0] ?? null;
            $stmt->close();

            $res = waRecapSend($conn, $schema, $companyId, $configured, $date);
            if ($res['error'] === 'no_destination') jsonResponse(422, 'Belum ada nomor WhatsApp tujuan. Isi nomor di pengaturan rekap.', ['reason' => 'no_destination']);
            if (!$res['ok']) jsonResponse(502, 'Rekap gagal dikirim ke WhatsApp. Coba lagi sebentar.', ['reason' => 'send_failed']);
            jsonResponse(200, 'Rekap terkirim', ['date' => $date]);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Throwable $e) {
    logApiError($conn, ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/settings/wa-recap.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
