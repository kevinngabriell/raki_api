<?php
// /settings/target.php - sales targets of an outlet (docs/backend-endpoints-revamp.md §12.1).
//   GET ?company_id -> { daily_target, monthly_target } (null = not set). The Owner (own outlets) and a Mitra (own outlet) may read.
//   PUT { company_id, daily_target, monthly_target } - Owner only, any own outlet; 0 or null clears a target.
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$decoded = requireAuth();
$conn = DB::conn();
$schema = DB_SCHEMA;

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            $companyId = requireCompanyAccess($conn, $decoded, $_GET['company_id'] ?? null);
            $stmt = $conn->prepare("SELECT daily_target, monthly_target FROM {$schema}.sales_target WHERE company_id = ?");
            $stmt->bind_param('s', $companyId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc() ?: [];
            $stmt->close();
            $daily = (int)($row['daily_target'] ?? 0);
            $monthly = (int)($row['monthly_target'] ?? 0);
            jsonResponse(200, 'Target fetched', ['daily_target' => $daily ?: null, 'monthly_target' => $monthly ?: null]);
            break;

        case 'PUT':
            requireOwner($decoded);
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) jsonResponse(400, 'Invalid JSON body');
            $companyId = requireCompanyAccess($conn, $decoded, $input['company_id'] ?? null);
            $vals = [];
            foreach (['daily_target', 'monthly_target'] as $f) {
                $v = $input[$f] ?? null;
                if ($v !== null && $v !== '' && (!is_numeric($v) || $v < 0)) jsonResponse(400, "$f harus angka >= 0");
                $vals[$f] = ($v === null || $v === '' || (int)$v === 0) ? null : (int)$v;
            }
            $by = $decoded->username;
            $stmt = $conn->prepare("INSERT INTO {$schema}.sales_target (company_id, daily_target, monthly_target, updated_by, updated_at) VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE daily_target = VALUES(daily_target), monthly_target = VALUES(monthly_target), updated_by = VALUES(updated_by), updated_at = NOW()");
            $stmt->bind_param('siis', $companyId, $vals['daily_target'], $vals['monthly_target'], $by);
            $stmt->execute();
            $stmt->close();
            jsonResponse(200, 'Target disimpan', $vals);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Throwable $e) {
    logApiError($conn, ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/settings/target.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
