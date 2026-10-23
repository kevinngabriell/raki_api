<?php
// /setoran/day.php - the day's setoran for one outlet (docs/backend-endpoints-revamp.md §4).
//   GET   ?company_id&date=YYYY-MM-DD  -> { done, cash[], qris{}, edc{} }
//   PATCH { company_id, date, action: "finish" | "reopen" }
// Owner / Mitra only. Money the system expects comes from the day's sales; what was deposited comes from
// work_session.cash_end (abang), settlement files (QRIS / EDC) and the reasons typed in the dashboard.

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once 'lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function setoranDateParam($raw): string {
    $d = DateTime::createFromFormat('Y-m-d', (string)$raw);
    if (!$d || $d->format('Y-m-d') !== $raw) {
        jsonResponse(400, 'date harus berformat YYYY-MM-DD');
    }
    return $raw;
}

function setoranDayPayload(mysqli $conn, string $schema, string $companyId, string $date): array {
    $core = MOVIRA_CORE_SCHEMA;

    // Every payment of the day with who sold it.
    $stmt = $conn->prepare("SELECT t.transaction_id, COALESCE(t.created_for, t.created_by) AS cashier, t.source, t.created_by, t.session_id,
            TIME_FORMAT(IF(t.sell_end IS NOT NULL, CONCAT(t.sell_end, ':00'), TIME(t.created_at)), '%H:%i') AS at,
            tp.payment_method, tp.amount
        FROM {$schema}.transaction t JOIN {$schema}.transaction_payment tp ON tp.transaction_id = t.transaction_id
        WHERE t.company_id = ? AND t.transaction_date = ?");
    $stmt->bind_param('ss', $companyId, $date);
    $stmt->execute();
    $pays = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Who is an abang, and display names.
    $names = [];
    $abang = [];
    $alias = []; // work_session.user_id may hold a username, a phone number or a user_id
    $stmt = $conn->prepare("SELECT user_id, username, first_name, phone_number, app_role_id FROM {$core}.app_user WHERE company_id = ? AND app_id = ?");
    $app = ACCOUNT_RAKI_APP_ID;
    $stmt->bind_param('ss', $companyId, $app);
    $stmt->execute();
    foreach ($stmt->get_result() as $u) {
        $names[$u['username']] = $u['first_name'] ?: $u['username'];
        foreach ([$u['username'], $u['user_id'], $u['phone_number']] as $a) {
            if ($a !== null && $a !== '') $alias[$a] = $u['username'];
        }
        if ($u['app_role_id'] === ACCESS_ROLE_ABANG) $abang[$u['username']] = true;
    }
    $stmt->close();

    // Older rows have no source: an abang's sale without a work session is their day recap (bundle).
    foreach ($pays as &$p) {
        $p['source'] ??= (isset($abang[$p['created_by']]) && $p['session_id'] === null) ? 'bundle' : 'outlet';
    }
    unset($p);

    // Cash points: one per abang, one for the outlet cashiers.
    $cash = [];
    $point = function (string $id, string $name, string $kind) use (&$cash) {
        $cash[$id] ??= ['id' => $id, 'name' => $name, 'kind' => $kind, 'closedBy' => null, 'closedAt' => null, 'system' => 0, 'deposited' => null, 'reason' => null];
    };
    foreach ($pays as $p) {
        if ($p['payment_method'] !== 'cash') continue;
        $isAbang = isset($abang[$p['cashier']]);
        $id = $isAbang ? $p['cashier'] : 'outlet';
        $point($id, $isAbang ? ($names[$p['cashier']] ?? $p['cashier']) : 'Kasir outlet', $isAbang ? 'abang' : 'outlet');
        $cash[$id]['system'] += (int)$p['amount'];
    }

    // Closed sessions of the day: what the abang handed in.
    $stmt = $conn->prepare("SELECT user_id, cash_start, cash_end, TIME_FORMAT(ended_at, '%H:%i') AS closed_at FROM {$schema}.work_session
        WHERE company_id = ? AND DATE(started_at) = ? AND status = 'closed' ORDER BY ended_at");
    $stmt->bind_param('ss', $companyId, $date);
    $stmt->execute();
    foreach ($stmt->get_result() as $ws) {
        $ws['user_id'] = $alias[$ws['user_id']] ?? $ws['user_id'];
        $point($ws['user_id'], $names[$ws['user_id']] ?? $ws['user_id'], 'abang');
        if ($ws['cash_end'] !== null) {
            $cash[$ws['user_id']]['deposited'] = ($cash[$ws['user_id']]['deposited'] ?? 0) + max(0, (int)$ws['cash_end'] - (int)$ws['cash_start']);
        }
        $cash[$ws['user_id']]['closedAt'] = $ws['closed_at'];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT point_id, reason FROM {$schema}.setoran_cash WHERE company_id = ? AND setoran_date = ?");
    $stmt->bind_param('ss', $companyId, $date);
    $stmt->execute();
    foreach ($stmt->get_result() as $r) {
        if (isset($cash[$r['point_id']])) $cash[$r['point_id']]['reason'] = $r['reason'];
    }
    $stmt->close();

    // Day header + settlement files.
    $stmt = $conn->prepare("SELECT done, qris_typed_total FROM {$schema}.setoran_day WHERE company_id = ? AND setoran_date = ?");
    $stmt->bind_param('ss', $companyId, $date);
    $stmt->execute();
    $day = $stmt->get_result()->fetch_assoc() ?: ['done' => 0, 'qris_typed_total' => null];
    $stmt->close();

    $files = [];
    $rowsByKind = ['qris' => [], 'edc' => []];
    $stmt = $conn->prepare("SELECT s.kind, s.settlement_id, s.file_name, s.row_count, TIME_FORMAT(s.uploaded_at, '%H:%i') AS at, r.amount, r.paid_time
        FROM {$schema}.setoran_settlement s LEFT JOIN {$schema}.setoran_settlement_row r ON r.settlement_id = s.settlement_id
        WHERE s.company_id = ? AND s.setoran_date = ? ORDER BY s.uploaded_at, r.row_no");
    $stmt->bind_param('ss', $companyId, $date);
    $stmt->execute();
    $latest = [];
    foreach ($stmt->get_result() as $r) {
        $latest[$r['kind']] = $r['settlement_id']; // the last uploaded file of a kind wins
        $files[$r['settlement_id']] = ['kind' => $r['kind'], 'name' => $r['file_name'], 'rows' => (int)$r['row_count'], 'uploadedAt' => $r['at']];
        if ($r['amount'] !== null) $rowsByKind[$r['kind']][$r['settlement_id']][] = ['amount' => (int)$r['amount'], 'time' => $r['paid_time']];
    }
    $stmt->close();
    $fileOf = fn(string $kind) => isset($latest[$kind]) ? ['name' => $files[$latest[$kind]]['name'], 'rows' => $files[$latest[$kind]]['rows'], 'uploadedAt' => $files[$latest[$kind]]['uploadedAt']] : null;
    $rowsOf = fn(string $kind) => isset($latest[$kind]) ? ($rowsByKind[$kind][$latest[$kind]] ?? []) : [];

    $flags = [];
    $stmt = $conn->prepare("SELECT transaction_id, status FROM {$schema}.setoran_qris_flag WHERE company_id = ?");
    $stmt->bind_param('s', $companyId);
    $stmt->execute();
    foreach ($stmt->get_result() as $r) $flags[$r['transaction_id']] = $r['status'];
    $stmt->close();

    $byMethod = fn(array $methods) => array_values(array_filter($pays, fn($p) => in_array($p['payment_method'], $methods, true)));
    $qrisPays = $byMethod(['qris', 'qris_midtrans']);
    $edcPays = $byMethod(['edc_flazz']);

    // Abang record their day as one bundle, so how its QRIS is matched is still open (question C4): bundles
    // count in the totals but are not matched one by one.
    $matchable = fn(array $list) => array_values(array_filter($list, fn($p) => $p['source'] !== 'bundle'));
    $toMatch = fn(array $list) => array_map(fn($p) => ['id' => $p['transaction_id'] . '|' . $p['payment_method'], 'amount' => (int)$p['amount'], 'time' => $p['at']], $list);

    $qrisMatch = setoranMatch($toMatch($matchable($qrisPays)), $rowsOf('qris'));
    $edcMatch = setoranMatch($toMatch($matchable($edcPays)), $rowsOf('edc'));

    // Unmatched QRIS sales with what the cashier needs to find them.
    $unmatched = [];
    $detail = $conn->prepare("SELECT COALESCE(m.menu_name, pk.package_name) AS name, td.quantity FROM {$schema}.transaction_detail td
        LEFT JOIN {$schema}.menu m ON m.menu_id = td.menu_id LEFT JOIN {$schema}.package pk ON pk.package_id = td.package_id
        WHERE td.transaction_id = ? ORDER BY td.line_no, td.detail_id LIMIT 1");
    foreach ($matchable($qrisPays) as $p) {
        if (!in_array($p['transaction_id'] . '|' . $p['payment_method'], $qrisMatch['unmatched'], true)) continue;
        $detail->bind_param('s', $p['transaction_id']);
        $detail->execute();
        $first = $detail->get_result()->fetch_assoc();
        $unmatched[] = [
            'transactionId' => $p['transaction_id'], 'amount' => (int)$p['amount'], 'seller' => $names[$p['cashier']] ?? $p['cashier'], 'time' => $p['at'],
            'items' => $first ? $first['name'] . ' ×' . $first['quantity'] : '', 'status' => $flags[$p['transaction_id']] ?? 'open',
        ];
    }
    $detail->close();

    $sum = fn(array $list) => array_sum(array_map(fn($p) => (int)$p['amount'], $list));
    $count = fn(array $list) => count(array_unique(array_column($list, 'transaction_id')));

    return [
        'done' => (bool)$day['done'],
        'cash' => array_values($cash),
        'qris' => [
            'system' => $sum($qrisPays), 'transactions' => $count($qrisPays),
            'typedTotal' => $day['qris_typed_total'] === null ? null : (int)$day['qris_typed_total'],
            'file' => $fileOf('qris'), 'matched' => count($qrisMatch['matched']), 'unmatched' => $unmatched,
        ],
        'edc' => [
            'system' => $sum($edcPays), 'transactions' => $count($edcPays),
            'settled' => array_sum(array_column($rowsOf('edc'), 'amount')),
            'file' => $fileOf('edc'), 'matched' => count($edcMatch['matched']), 'unmatched' => count($edcMatch['unmatched']),
        ],
    ];
}

try {
    $decoded = requireAuth();
    $conn = DB::conn();
    $schema = DB_SCHEMA;
    requireManager($decoded);

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            $companyId = requireCompanyAccess($conn, $decoded, $_GET['company_id'] ?? null);
            $date = setoranDateParam($_GET['date'] ?? date('Y-m-d'));
            jsonResponse(200, 'Setoran fetched', setoranDayPayload($conn, $schema, $companyId, $date));
            break;

        case 'PATCH':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) jsonResponse(400, 'Invalid JSON body');
            $companyId = requireCompanyAccess($conn, $decoded, $input['company_id'] ?? null);
            $date = setoranDateParam($input['date'] ?? null);
            $action = $input['action'] ?? '';
            if (!in_array($action, ['finish', 'reopen'], true)) jsonResponse(400, 'action must be finish or reopen');
            $done = $action === 'finish' ? 1 : 0;
            $by = $done ? $decoded->username : null;
            $stmt = $conn->prepare("INSERT INTO {$schema}.setoran_day (company_id, setoran_date, done, finished_by, finished_at) VALUES (?, ?, ?, ?, IF(?=1, NOW(), NULL))
                ON DUPLICATE KEY UPDATE done = VALUES(done), finished_by = VALUES(finished_by), finished_at = VALUES(finished_at)");
            $stmt->bind_param('ssisi', $companyId, $date, $done, $by, $done);
            $stmt->execute();
            $stmt->close();
            jsonResponse(200, $done ? 'Setoran selesai' : 'Setoran dibuka lagi', ['done' => (bool)$done]);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Throwable $e) {
    logApiError(DB::conn(), ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/setoran/day.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
