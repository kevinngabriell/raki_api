<?php
// GET /session/history.php?username&start_date&end_date
// -> [{ started_at, ended_at, cash_end, menus: [{ menu_name, thumb_url, qty_start, qty_sold, qty_left }] }]
// Past work sessions of one abang: "Disetor / Selisih" per day and "Stok bawaan" for past days. Owner / Mitra only.
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
    $core = MOVIRA_CORE_SCHEMA;
    requireManager($decoded);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(405, 'Method Not Allowed');

    $username = trim((string)($_GET['username'] ?? ''));
    if ($username === '') jsonResponse(400, 'username wajib diisi');
    $start = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
    $end = $_GET['end_date'] ?? date('Y-m-d');
    foreach ([['start_date', $start], ['end_date', $end]] as [$f, $v]) {
        $d = DateTime::createFromFormat('Y-m-d', (string)$v);
        if (!$d || $d->format('Y-m-d') !== $v) jsonResponse(400, "$f harus berformat YYYY-MM-DD");
    }

    // The abang must belong to an outlet the caller may see.
    $app = ACCOUNT_RAKI_APP_ID;
    $stmt = $conn->prepare("SELECT user_id, username, phone_number, company_id FROM {$core}.app_user WHERE username = ? AND app_id = ? LIMIT 1");
    $stmt->bind_param('ss', $username, $app);
    $stmt->execute();
    $abang = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$abang) jsonResponse(404, 'Abang tidak ditemukan');
    requireCompanyAccess($conn, $decoded, $abang['company_id']);

    $ids = array_values(array_filter([$abang['username'], $abang['user_id'], $abang['phone_number']]));
    [$in, $types] = accessInList($ids);
    $stmt = $conn->prepare("SELECT session_id, started_at, ended_at, cash_start, cash_end FROM {$schema}.work_session
        WHERE company_id = ? AND user_id IN ($in) AND DATE(started_at) BETWEEN ? AND ? ORDER BY started_at DESC");
    $params = array_merge([$abang['company_id']], $ids, [$start, $end]);
    $stmt->bind_param('s' . $types . 'ss', ...$params);
    $stmt->execute();
    $sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = [];
    if ($sessions) {
        $sids = array_column($sessions, 'session_id');
        [$sin, $stypes] = accessInList($sids);
        $stmt = $conn->prepare("SELECT s.session_id, m.menu_name, m.thumb_url, s.qty_start, s.qty_end FROM {$schema}.work_session_stock s
            JOIN {$schema}.menu m ON m.menu_id = s.menu_id WHERE s.session_id IN ($sin) ORDER BY m.menu_name");
        $stmt->bind_param($stypes, ...$sids);
        $stmt->execute();
        $menus = [];
        foreach ($stmt->get_result() as $r) {
            $left = $r['qty_end'] === null ? null : (int)$r['qty_end'];
            $menus[$r['session_id']][] = ['menu_name' => $r['menu_name'], 'thumb_url' => $r['thumb_url'], 'qty_start' => (int)$r['qty_start'],
                'qty_sold' => $left === null ? null : max(0, (int)$r['qty_start'] - $left), 'qty_left' => $left];
        }
        $stmt->close();
        foreach ($sessions as $s) {
            $out[] = ['started_at' => $s['started_at'], 'ended_at' => $s['ended_at'], 'cash_start' => (int)$s['cash_start'],
                      'cash_end' => $s['cash_end'] === null ? null : (int)$s['cash_end'], 'menus' => $menus[$s['session_id']] ?? []];
        }
    }
    jsonResponse(200, 'Session history fetched', $out);
} catch (Throwable $e) {
    logApiError(DB::conn(), ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/session/history.php', 'method' => 'GET',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
