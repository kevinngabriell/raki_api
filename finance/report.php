<?php
// GET /finance/report.php?start_date&end_date (docs/backend-endpoints-revamp.md §11.1)
// Owner: own outlets + a pusat column (own costs, royalti earned from Mitra). Mitra: their own outlet, with royalti
// owed to pusat as a cost. A Mitra outlet's omzet is not part of the Owner's laba (I1).
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once 'lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

/** SELECT ... GROUP BY company: company_id => value (helper for the sums below). */
function reportSums(mysqli $conn, string $sql, array $companies, array $extra): array {
    [$in, $types] = accessInList($companies);
    $stmt = $conn->prepare(str_replace('{IN}', $in, $sql));
    $params = array_merge($companies, $extra);
    $stmt->bind_param($types . str_repeat('s', count($extra)), ...$params);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result() as $r) $out[$r['c']] = $r;
    $stmt->close();
    return $out;
}

try {
    $decoded = requireAuth();
    $conn = DB::conn();
    $schema = DB_SCHEMA;
    $core = MOVIRA_CORE_SCHEMA;
    requireManager($decoded);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') jsonResponse(405, 'Method Not Allowed');

    $start = $_GET['start_date'] ?? date('Y-m-01');
    $end = $_GET['end_date'] ?? date('Y-m-d');
    foreach ([['start_date', $start], ['end_date', $end]] as [$f, $v]) {
        $d = DateTime::createFromFormat('Y-m-d', (string)$v);
        if (!$d || $d->format('Y-m-d') !== $v) jsonResponse(400, "$f harus berformat YYYY-MM-DD");
    }
    if ($start > $end) jsonResponse(400, 'start_date tidak boleh setelah end_date');

    $isOwner = accessIsOwner($decoded);
    $outlets = accessCompanyIds($conn, $decoded);
    if (!$outlets) jsonResponse(200, 'Report fetched', ['columns' => [], 'kas' => [], 'royalties' => [], 'klop' => ['connected' => false, 'last_sync_at' => null, 'account' => null, 'auth_url' => null, 'open_url' => null]]);

    $names = [];
    [$in, $types] = accessInList($outlets);
    $stmt = $conn->prepare("SELECT company_id, company_name FROM {$core}.app_company WHERE company_id IN ($in)");
    $stmt->bind_param($types, ...$outlets);
    $stmt->execute();
    foreach ($stmt->get_result() as $r) $names[$r['company_id']] = $r['company_name'];
    $stmt->close();

    $scopes = financeScopes($conn, $decoded);
    financeMaterialize($conn, $schema, $scopes, financePeriod($start), financePeriod($end));

    $sales = reportSums($conn, "SELECT company_id AS c, COALESCE(SUM(total_amount), 0) AS omzet, COALESCE(SUM(discount_amount), 0) AS discount FROM {$schema}.transaction WHERE company_id IN ({IN}) AND transaction_date BETWEEN ? AND ? GROUP BY company_id", $outlets, [$start, $end]);
    $supply = reportSums($conn, "SELECT from_company_id AS c, COALESCE(SUM(total_amount + COALESCE(shipping_cost, 0)), 0) AS v FROM {$schema}.supply_order WHERE from_company_id IN ({IN}) AND status = 'completed' AND DATE(completed_at) BETWEEN ? AND ? GROUP BY from_company_id", $outlets, [$start, $end]);
    $bonus = reportSums($conn, "SELECT company_id AS c, COALESCE(SUM(amount), 0) AS v FROM {$schema}.bonus_payout WHERE company_id IN ({IN}) AND paid_on BETWEEN ? AND ? GROUP BY company_id", $outlets, [$start, $end]);
    $expense = reportSums($conn, "SELECT company_id AS c, COALESCE(SUM(amount), 0) AS v FROM {$schema}.finance_expense WHERE company_id IN ({IN}) AND expense_date BETWEEN ? AND ? GROUP BY company_id", $scopes, [$start, $end]);

    // Royalti per Mitra outlet: the Owner sees every Mitra, a Mitra only themselves.
    $mitras = [];
    if ($isOwner) {
        $role = ACCESS_ROLE_MITRA;
        $app = ACCOUNT_RAKI_APP_ID;
        $res = $conn->prepare("SELECT DISTINCT C.company_id, C.company_name FROM {$core}.app_company C JOIN {$core}.app_user U ON U.company_id = C.company_id AND U.app_role_id = ? WHERE C.app_id = ? AND C.status = 'active' ORDER BY C.company_name");
        $res->bind_param('ss', $role, $app);
        $res->execute();
        foreach ($res->get_result() as $r) $mitras[$r['company_id']] = $r['company_name'];
        $res->close();
    } elseif (accessIsMitra($decoded)) {
        $mitras[$decoded->company_id] = $names[$decoded->company_id] ?? '';
    }
    $mitraSales = $mitras ? reportSums($conn, "SELECT company_id AS c, COALESCE(SUM(total_amount), 0) AS omzet FROM {$schema}.transaction WHERE company_id IN ({IN}) AND transaction_date BETWEEN ? AND ? GROUP BY company_id", array_keys($mitras), [$start, $end]) : [];
    $rules = $mitras ? reportSums($conn, "SELECT company_id AS c, type, percent, fixed FROM {$schema}.finance_royalty_rule WHERE company_id IN ({IN})", array_keys($mitras), []) : [];
    $months = financeMonthsBetween($start, $end);
    $payments = [];
    if ($mitras && $months) {
        $ids = array_keys($mitras);
        [$pin, $ptypes] = accessInList($ids);
        [$min, $mtypes] = accessInList($months);
        $stmt = $conn->prepare("SELECT payment_id, company_id, amount, paid_on, method, recorded_by FROM {$schema}.finance_royalty_payment WHERE company_id IN ($pin) AND period IN ($min) ORDER BY paid_on");
        $params = array_merge($ids, $months);
        $stmt->bind_param($ptypes . $mtypes, ...$params);
        $stmt->execute();
        foreach ($stmt->get_result() as $r) $payments[$r['company_id']][] = ['payment_id' => $r['payment_id'], 'amount' => (int)$r['amount'], 'paid_on' => $r['paid_on'], 'method' => $r['method'], 'recorded_by' => $r['recorded_by']];
        $stmt->close();
    }
    $royalties = [];
    $royaltyTotal = 0;
    foreach ($mitras as $cid => $cname) {
        $omzet = (int)($mitraSales[$cid]['omzet'] ?? 0);
        $rule = $rules[$cid] ?? null;
        $amount = $rule ? financeRoyalty($rule, $omzet, $start, $end) : 0;
        $royaltyTotal += $amount;
        $royalties[] = [
            'company_id' => $cid, 'company_name' => $cname, 'type' => $rule['type'] ?? 'pct', 'percent' => (float)($rule['percent'] ?? 0), 'fixed' => (int)($rule['fixed'] ?? 0),
            'omzet' => $omzet, 'amount' => $amount, 'paid' => array_sum(array_column($payments[$cid] ?? [], 'amount')), 'payments' => $payments[$cid] ?? [],
        ];
    }
    $royaltyByCompany = array_column($royalties, 'amount', 'company_id');

    $columns = [];
    foreach ($outlets as $cid) {
        $columns[] = [
            'company_id' => $cid, 'company_name' => $names[$cid] ?? $cid, 'kind' => 'outlet',
            'omzet' => (int)($sales[$cid]['omzet'] ?? 0), 'discount' => (int)($sales[$cid]['discount'] ?? 0),
            'supply' => (int)($supply[$cid]['v'] ?? 0), 'bonus' => (int)($bonus[$cid]['v'] ?? 0), 'expense' => (int)($expense[$cid]['v'] ?? 0),
            'royalty_in' => 0, 'royalty_out' => accessIsMitra($decoded) ? (int)($royaltyByCompany[$cid] ?? 0) : 0,
        ];
    }
    if ($isOwner) {
        $columns[] = ['company_id' => FINANCE_PUSAT, 'company_name' => 'Pusat', 'kind' => 'pusat', 'omzet' => 0, 'discount' => 0, 'supply' => 0, 'bonus' => 0,
                      'expense' => (int)($expense[FINANCE_PUSAT]['v'] ?? 0), 'royalty_in' => $royaltyTotal, 'royalty_out' => 0];
    }

    // Kas: closed abang sessions whose handed-in cash differs from the cash sales of that day.
    $kas = [];
    $alias = [];
    $abangRole = ACCESS_ROLE_ABANG;
    $stmt = $conn->prepare("SELECT user_id, username, first_name, phone_number, company_id FROM {$core}.app_user WHERE company_id IN ($in) AND app_role_id = ?");
    $params = array_merge($outlets, [$abangRole]);
    $stmt->bind_param($types . 's', ...$params);
    $stmt->execute();
    $displayName = [];
    foreach ($stmt->get_result() as $u) {
        $displayName[$u['username']] = $u['first_name'] ?: $u['username'];
        foreach ([$u['username'], $u['user_id'], $u['phone_number']] as $a) if ($a) $alias[$u['company_id'] . '|' . $a] = $u['username'];
    }
    $stmt->close();
    $deposited = [];
    $stmt = $conn->prepare("SELECT company_id, user_id, DATE(started_at) AS d, SUM(cash_end - cash_start) AS dep FROM {$schema}.work_session
        WHERE company_id IN ($in) AND status = 'closed' AND cash_end IS NOT NULL AND DATE(started_at) BETWEEN ? AND ? GROUP BY company_id, user_id, DATE(started_at)");
    $params = array_merge($outlets, [$start, $end]);
    $stmt->bind_param($types . 'ss', ...$params);
    $stmt->execute();
    foreach ($stmt->get_result() as $r) {
        $u = $alias[$r['company_id'] . '|' . $r['user_id']] ?? $r['user_id'];
        $deposited[$r['company_id'] . '|' . $u . '|' . $r['d']] = (int)$r['dep'];
    }
    $stmt->close();
    $cashSales = [];
    $stmt = $conn->prepare("SELECT T.company_id, COALESCE(T.created_for, T.created_by) AS u, T.transaction_date AS d, SUM(P.amount) AS v FROM {$schema}.transaction T JOIN {$schema}.transaction_payment P ON P.transaction_id = T.transaction_id
        WHERE T.company_id IN ($in) AND P.payment_method = 'cash' AND T.transaction_date BETWEEN ? AND ? GROUP BY T.company_id, COALESCE(T.created_for, T.created_by), T.transaction_date");
    $stmt->bind_param($types . 'ss', ...$params);
    $stmt->execute();
    foreach ($stmt->get_result() as $r) $cashSales[$r['company_id'] . '|' . $r['u'] . '|' . $r['d']] = (int)$r['v'];
    $stmt->close();
    $reasons = [];
    $stmt = $conn->prepare("SELECT company_id, point_id, setoran_date, reason FROM {$schema}.setoran_cash WHERE company_id IN ($in) AND setoran_date BETWEEN ? AND ? AND reason IS NOT NULL AND reason <> ''");
    $stmt->bind_param($types . 'ss', ...$params);
    $stmt->execute();
    foreach ($stmt->get_result() as $r) $reasons[$r['company_id'] . '|' . $r['point_id'] . '|' . $r['setoran_date']] = true;
    $stmt->close();
    $dayNames = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    $monthNames = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    foreach ($deposited as $key => $dep) {
        [$cid, $u, $d] = explode('|', $key);
        $diff = $dep - ($cashSales[$key] ?? 0);
        if ($diff === 0) continue;
        $dt = new DateTime($d);
        $checked = isset($reasons[$key]);
        $kas[] = ['name' => ($displayName[$u] ?? $u) . ' · Abang ' . ($names[$cid] ?? ''), 'note' => 'Tunai · ' . $dayNames[(int)$dt->format('w')] . ' ' . $dt->format('j') . ' ' . $monthNames[(int)$dt->format('n')] . ' · ' . ($checked ? 'sudah dicek' : 'belum dicek'),
                  'amount' => $diff, 'checked' => $checked, 'date' => $d];
    }
    usort($kas, fn($a, $b) => strcmp($b['date'], $a['date']));

    $klop = ['connected' => false, 'last_sync_at' => null, 'account' => null, 'auth_url' => null, 'open_url' => null];
    $stmt = $conn->prepare("SELECT account, last_sync_at FROM {$schema}.finance_klop WHERE company_id IN ($in) LIMIT 1");
    $stmt->bind_param($types, ...$outlets);
    $stmt->execute();
    if ($k = $stmt->get_result()->fetch_assoc()) {
        $klop = ['connected' => true, 'last_sync_at' => $k['last_sync_at'], 'account' => $k['account'], 'auth_url' => null, 'open_url' => null];
    }
    $stmt->close();

    jsonResponse(200, 'Report fetched', ['columns' => $columns, 'kas' => $kas, 'royalties' => $royalties, 'klop' => $klop]);
} catch (Throwable $e) {
    logApiError(DB::conn(), ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/finance/report.php', 'method' => 'GET',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
