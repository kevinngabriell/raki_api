<?php
// PATCH /setoran/cash.php { company_id, date, point_id, reason } - why a cash difference happened.
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

try {
    $decoded = requireAuth();
    $conn = DB::conn();
    $schema = DB_SCHEMA;
    requireManager($decoded);
    if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') jsonResponse(405, 'Method Not Allowed');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) jsonResponse(400, 'Invalid JSON body');
    $companyId = requireCompanyAccess($conn, $decoded, $input['company_id'] ?? null);
    $date = (string)($input['date'] ?? '');
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) jsonResponse(400, 'date harus berformat YYYY-MM-DD');
    $pointId = trim((string)($input['point_id'] ?? ''));
    if ($pointId === '') jsonResponse(400, 'point_id wajib diisi');
    $reason = trim((string)($input['reason'] ?? ''));
    if (mb_strlen($reason) > 500) jsonResponse(400, 'Alasan maksimal 500 karakter');
    $reason = $reason === '' ? null : $reason;
    $by = $decoded->username;

    $stmt = $conn->prepare("INSERT INTO {$schema}.setoran_cash (company_id, setoran_date, point_id, reason, updated_by) VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE reason = VALUES(reason), updated_by = VALUES(updated_by)");
    $stmt->bind_param('sssss', $companyId, $date, $pointId, $reason, $by);
    $stmt->execute();
    $stmt->close();
    jsonResponse(200, 'Alasan disimpan');
} catch (Throwable $e) {
    logApiError(DB::conn(), ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/setoran/cash.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
