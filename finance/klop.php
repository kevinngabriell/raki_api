<?php
// /finance/klop.php - one-way RAKI -> Klop link (docs §11.4). Klop itself isn't built yet, so there is no login to
// complete: POST answers 503 until a Klop auth flow exists; DELETE disconnects whatever is stored (data already
// sent stays in Klop). The connection state is read by finance/report.php.
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
    requireOwner($decoded);
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'POST':
            jsonResponse(503, 'Klop belum tersedia. Hubungkan setelah login Klop siap.', ['reason' => 'klop_unavailable']);
            break;
        case 'DELETE':
            $ids = accessCompanyIds($conn, $decoded);
            if ($ids) {
                [$in, $types] = accessInList($ids);
                $stmt = $conn->prepare("DELETE FROM {$schema}.finance_klop WHERE company_id IN ($in)");
                $stmt->bind_param($types, ...$ids);
                $stmt->execute();
                $stmt->close();
            }
            jsonResponse(200, 'Klop diputus');
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Throwable $e) {
    logApiError($conn, ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/finance/klop.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
