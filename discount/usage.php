<?php
// GET /discount/usage.php?kind=voucher|promo&id
// -> { per_day: [{ date, count }], per_outlet: [{ company_name, count }], per_channel: { outlet, abang },
//      recent: [{ username, outlet_name, transaction_date, code, total_amount, discount_amount }] }
// The channel is the role of the cashier who made the sale (Abang => abang, anyone else => outlet).

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, 'Method Not Allowed');
}

$decoded = requireAuth();
$conn = DB::conn();

try {
    requireManager($decoded);
    $schema = DB_SCHEMA;
    $core = MOVIRA_CORE_SCHEMA;
    $kind = $_GET['kind'] ?? '';
    $id = trim((string)($_GET['id'] ?? ''));
    if (!in_array($kind, ['voucher', 'promo'], true) || $id === '') {
        jsonResponse(400, 'kind (voucher|promo) and id are required');
    }

    $companies = accessCompanyIds($conn, $decoded) ?: ['-'];
    [$in, $types] = accessInList($companies);
    $table = $kind === 'voucher' ? 'voucher' : 'promo';
    $stmt = $conn->prepare("SELECT 1 FROM {$schema}.$table WHERE {$kind}_id = ? AND company_id IN ($in)");
    $params = array_merge([$id], $companies);
    $stmt->bind_param('s' . $types, ...$params);
    $stmt->execute();
    if (!$stmt->get_result()->fetch_row()) {
        jsonResponse(404, ucfirst($kind) . ' not found');
    }
    $stmt->close();

    // One row per use: when, outlet, who, code, sale total, discount.
    if ($kind === 'voucher') {
        $sql = "SELECT DATE(vu.used_at) AS used_date, t.company_id, t.created_by AS username, vu.code, t.total_amount, vu.discount_amount, t.transaction_date, vu.used_at AS at
                FROM {$schema}.voucher_usage vu JOIN {$schema}.transaction t ON t.transaction_id = vu.transaction_id
                WHERE vu.voucher_id = ?";
    } else {
        $sql = "SELECT t.transaction_date AS used_date, t.company_id, t.created_by AS username, NULL AS code, t.total_amount, tp.discount_amount, t.transaction_date, tp.created_at AS at
                FROM {$schema}.transaction_promo tp JOIN {$schema}.transaction t ON t.transaction_id = tp.transaction_id
                WHERE tp.promo_id = ?";
    }
    $stmt = $conn->prepare($sql . " ORDER BY at DESC");
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $uses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $names = [];
    $abang = [];
    if ($uses) {
        $cids = array_values(array_unique(array_filter(array_column($uses, 'company_id'))));
        if ($cids) {
            [$cin, $ctypes] = accessInList($cids);
            $stmt = $conn->prepare("SELECT company_id, company_name FROM {$core}.app_company WHERE company_id IN ($cin)");
            $stmt->bind_param($ctypes, ...$cids);
            $stmt->execute();
            foreach ($stmt->get_result() as $r) $names[$r['company_id']] = $r['company_name'];
            $stmt->close();
        }
        $users = array_values(array_unique(array_filter(array_column($uses, 'username'))));
        if ($users) {
            [$uin, $utypes] = accessInList($users);
            $stmt = $conn->prepare("SELECT username FROM {$core}.app_user WHERE username IN ($uin) AND app_role_id = ?");
            $role = ACCESS_ROLE_ABANG;
            $params = array_merge($users, [$role]);
            $stmt->bind_param($utypes . 's', ...$params);
            $stmt->execute();
            foreach ($stmt->get_result() as $r) $abang[$r['username']] = true;
            $stmt->close();
        }
    }

    $perDay = [];
    $perOutlet = [];
    $perChannel = ['outlet' => 0, 'abang' => 0];
    $recent = [];
    foreach ($uses as $u) {
        $perDay[$u['used_date']] = ($perDay[$u['used_date']] ?? 0) + 1;
        $outlet = $names[$u['company_id']] ?? (string)$u['company_id'];
        $perOutlet[$outlet] = ($perOutlet[$outlet] ?? 0) + 1;
        $perChannel[isset($abang[$u['username']]) ? 'abang' : 'outlet']++;
        if (count($recent) < 20) {
            $recent[] = ['username' => $u['username'], 'outlet_name' => $outlet, 'transaction_date' => $u['transaction_date'], 'code' => $u['code'],
                         'total_amount' => (int)$u['total_amount'], 'discount_amount' => (int)$u['discount_amount']];
        }
    }
    ksort($perDay);
    arsort($perOutlet);

    jsonResponse(200, 'Usage fetched', [
        'per_day' => array_map(fn($d, $c) => ['date' => $d, 'count' => $c], array_keys($perDay), $perDay),
        'per_outlet' => array_map(fn($n, $c) => ['company_name' => $n, 'count' => $c], array_keys($perOutlet), $perOutlet),
        'per_channel' => $perChannel,
        'recent' => $recent,
    ]);
} catch (Throwable $e) {
    logApiError($conn, ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/discount/usage.php', 'method' => 'GET',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
