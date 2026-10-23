<?php
// /bonus/payout.php - which weekly abang bonuses were paid out (docs/backend-endpoints-revamp.md §7).
//   GET    ?company_id&start&end                 -> [{ username, week_start, amount, paid_on, paid_by }] for weeks starting in [start, end]
//   POST   { company_id, paid_on, items: [{ username, week_start, amount }] }   paid_by comes from the token
//   DELETE ?company_id&username&week_start       undo a mistake
// Owner (any own outlet) or Mitra (own outlet). week_start is the Monday of the Mon-Sat bonus week.
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

function payoutDate($v, string $field): string {
    $d = DateTime::createFromFormat('Y-m-d', (string)$v);
    if (!$d || $d->format('Y-m-d') !== $v) jsonResponse(400, "$field harus berformat YYYY-MM-DD");
    return $v;
}

function payoutMonday($v, string $field): string {
    $v = payoutDate($v, $field);
    if ((new DateTime($v))->format('N') !== '1') jsonResponse(400, "$field harus hari Senin (awal minggu bonus)");
    return $v;
}

try {
    $decoded = requireAuth();
    $conn = DB::conn();
    $schema = DB_SCHEMA;
    requireManager($decoded);
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            $companyId = requireCompanyAccess($conn, $decoded, $_GET['company_id'] ?? null);
            $start = payoutDate($_GET['start'] ?? date('Y-m-01'), 'start');
            $end = payoutDate($_GET['end'] ?? date('Y-m-d'), 'end');
            $stmt = $conn->prepare("SELECT username, week_start, amount, paid_on, paid_by FROM {$schema}.bonus_payout
                WHERE company_id = ? AND week_start BETWEEN ? AND ? ORDER BY week_start, username");
            $stmt->bind_param('sss', $companyId, $start, $end);
            $stmt->execute();
            $rows = array_map(fn($r) => $r + [], $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
            $stmt->close();
            foreach ($rows as &$r) $r['amount'] = (int)$r['amount'];
            unset($r);
            jsonResponse(200, 'Bonus payouts fetched', $rows);
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) jsonResponse(400, 'Invalid JSON body');
            $companyId = requireCompanyAccess($conn, $decoded, $input['company_id'] ?? null);
            $paidOn = payoutDate($input['paid_on'] ?? date('Y-m-d'), 'paid_on');
            $items = $input['items'] ?? null;
            if (!is_array($items) || !$items) jsonResponse(400, 'items (array) is required');

            // Only abang of this outlet can be paid.
            $core = MOVIRA_CORE_SCHEMA;
            $abangRole = ACCESS_ROLE_ABANG;
            $stmt = $conn->prepare("SELECT username FROM {$core}.app_user WHERE company_id = ? AND app_role_id = ?");
            $stmt->bind_param('ss', $companyId, $abangRole);
            $stmt->execute();
            $abangs = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'username');
            $stmt->close();

            $clean = [];
            foreach ($items as $i => $it) {
                $u = (string)($it['username'] ?? '');
                if (!in_array($u, $abangs, true)) jsonResponse(400, "items[$i]: $u bukan abang outlet ini");
                $amount = (int)($it['amount'] ?? -1);
                if ($amount < 0) jsonResponse(400, "items[$i]: amount harus >= 0");
                $clean[] = [$u, payoutMonday($it['week_start'] ?? null, "items[$i].week_start"), $amount];
            }

            $by = $decoded->username;
            $conn->begin_transaction();
            try {
                // A week already marked paid keeps its first record.
                $stmt = $conn->prepare("INSERT IGNORE INTO {$schema}.bonus_payout (company_id, username, week_start, amount, paid_on, paid_by) VALUES (?, ?, ?, ?, ?, ?)");
                $saved = 0;
                foreach ($clean as [$u, $week, $amount]) {
                    $stmt->bind_param('sssiss', $companyId, $u, $week, $amount, $paidOn, $by);
                    $stmt->execute();
                    $saved += $stmt->affected_rows;
                }
                $stmt->close();
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            jsonResponse(201, 'Bonus ditandai dibayar', ['saved' => $saved, 'already_paid' => count($clean) - $saved]);
            break;

        case 'DELETE':
            $companyId = requireCompanyAccess($conn, $decoded, $_GET['company_id'] ?? null);
            $username = trim((string)($_GET['username'] ?? ''));
            $week = payoutMonday($_GET['week_start'] ?? null, 'week_start');
            if ($username === '') jsonResponse(400, 'username wajib diisi');
            $stmt = $conn->prepare("DELETE FROM {$schema}.bonus_payout WHERE company_id = ? AND username = ? AND week_start = ?");
            $stmt->bind_param('sss', $companyId, $username, $week);
            $stmt->execute();
            $n = $stmt->affected_rows;
            $stmt->close();
            if ($n === 0) jsonResponse(404, 'Pembayaran tidak ditemukan');
            jsonResponse(200, 'Pembayaran dibatalkan');
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Throwable $e) {
    logApiError(DB::conn(), ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/bonus/payout.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
