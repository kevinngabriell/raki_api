<?php
// POST /setoran/settlement.php (multipart) { company_id, date, kind: "qris"|"edc", file }
// Stores the bank / EDC settlement file of the day; day.php matches it to the sales by amount + time.
// CSV (comma or semicolon) or .xlsx: a header row with a nominal/amount column and optionally a time column.
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once 'lib.php';

const SETORAN_MAX_FILE_BYTES = 2 * 1024 * 1024;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

try {
    $decoded = requireAuth();
    $conn = DB::conn();
    $schema = DB_SCHEMA;
    requireManager($decoded);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(405, 'Method Not Allowed');
    rejectOversizedBody();

    $companyId = requireCompanyAccess($conn, $decoded, $_POST['company_id'] ?? null);
    $date = (string)($_POST['date'] ?? '');
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) jsonResponse(400, 'date harus berformat YYYY-MM-DD');
    $kind = (string)($_POST['kind'] ?? '');
    if (!in_array($kind, ['qris', 'edc'], true)) jsonResponse(400, 'kind must be qris or edc');

    $file = $_FILES['file'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) jsonResponse(400, 'File settlement wajib diunggah');
    if ($file['size'] > SETORAN_MAX_FILE_BYTES) jsonResponse(400, 'File maksimal 2 MB');
    $name = basename((string)$file['name']);
    if (preg_match('/\.xlsx$/i', $name)) {
        $parsed = setoranParseXlsx($file['tmp_name']);
    } elseif (preg_match('/\.(csv|txt)$/i', $name)) {
        $parsed = setoranParseCsv($file['tmp_name']);
    } else {
        jsonResponse(400, 'Format file tidak didukung. Unggah CSV atau Excel (.xlsx). File .xls lama: simpan ulang sebagai .xlsx atau CSV.');
    }
    if (isset($parsed['error'])) jsonResponse(400, $parsed['error']);
    $rows = $parsed['rows'];

    $id = 'set' . uniqid();
    $by = $decoded->username;
    $count = count($rows);
    $conn->begin_transaction();
    try {
        // A new upload replaces the earlier file of the same kind and day.
        foreach (['setoran_settlement_row' => "settlement_id IN (SELECT settlement_id FROM {$schema}.setoran_settlement WHERE company_id = ? AND setoran_date = ? AND kind = ?)",
                  'setoran_settlement' => 'company_id = ? AND setoran_date = ? AND kind = ?'] as $table => $where) {
            $stmt = $conn->prepare("DELETE FROM {$schema}.$table WHERE $where");
            $stmt->bind_param('sss', $companyId, $date, $kind);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $conn->prepare("INSERT INTO {$schema}.setoran_settlement (settlement_id, company_id, setoran_date, kind, file_name, row_count, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param('sssssis', $id, $companyId, $date, $kind, $name, $count, $by);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO {$schema}.setoran_settlement_row (settlement_id, row_no, amount, paid_time) VALUES (?, ?, ?, ?)");
        foreach ($rows as $i => $r) {
            $no = $i + 1;
            $stmt->bind_param('siis', $id, $no, $r['amount'], $r['time']);
            $stmt->execute();
        }
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    jsonResponse(201, 'Settlement diunggah', ['settlement_id' => $id, 'file' => $name, 'rows' => $count]);
} catch (Throwable $e) {
    logApiError(DB::conn(), ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/setoran/settlement.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
