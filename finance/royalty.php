<?php
// PUT /finance/royalty.php { company_id, type: "pct"|"fix"|"both", percent, fixed } - the royalti rule of a Mitra outlet (Owner only).
// Royalti = omzet x percent (+ fixed per month, pro-rata for a partial month); see financeRoyalty().
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once 'lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$decoded = requireAuth();
$conn = DB::conn();
$schema = DB_SCHEMA;

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') jsonResponse(405, 'Method Not Allowed');
    requireOwner($decoded);
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) jsonResponse(400, 'Invalid JSON body');

    $companyId = trim((string)($in['company_id'] ?? ''));
    if ($companyId === '' || !accessCompanyIsMitra($conn, $companyId)) jsonResponse(400, 'company_id harus outlet Mitra');
    $type = (string)($in['type'] ?? '');
    if (!in_array($type, ['pct', 'fix', 'both'], true)) jsonResponse(400, 'type must be pct, fix or both');
    $percent = (float)($in['percent'] ?? 0);
    $fixed = (int)($in['fixed'] ?? 0);
    if ($percent < 0 || $percent > 100) jsonResponse(400, 'percent harus 0-100');
    if ($fixed < 0) jsonResponse(400, 'fixed harus >= 0');
    if (in_array($type, ['pct', 'both'], true) && $percent <= 0) jsonResponse(400, 'percent harus lebih dari 0');
    if (in_array($type, ['fix', 'both'], true) && $fixed <= 0) jsonResponse(400, 'fixed harus lebih dari 0');
    if ($type === 'pct') $fixed = 0;
    if ($type === 'fix') $percent = 0;

    $by = $decoded->username;
    $stmt = $conn->prepare("INSERT INTO {$schema}.finance_royalty_rule (company_id, type, percent, fixed, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE type = VALUES(type), percent = VALUES(percent), fixed = VALUES(fixed), updated_by = VALUES(updated_by), updated_at = NOW()");
    $stmt->bind_param('ssdis', $companyId, $type, $percent, $fixed, $by);
    $stmt->execute();
    $stmt->close();
    jsonResponse(200, 'Aturan royalti disimpan', ['company_id' => $companyId, 'type' => $type, 'percent' => $percent, 'fixed' => $fixed]);
} catch (Throwable $e) {
    logApiError($conn, ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/finance/royalty.php', 'method' => 'PUT',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
