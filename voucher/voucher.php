<?php
// /voucher/voucher.php - vouchers for the dashboard (docs/backend-endpoints-revamp.md §9.1).
//
//   GET    list / detail of the vouchers the caller manages (Owner: own outlets, Mitra: own outlet)
//   POST   create (Owner / Mitra). usage_type "one_time" + code_prefix + code_count creates a batch of PREFIX-XXXX codes
//   PUT    partial update, incl. is_active / is_archived
//   DELETE refused (409) once the voucher has been used; archive it instead
//
// Outlets (company_ids), channels, menu scope (category_ids / menu_ids) and usage figures are described in the docs.
// Applying a voucher at the till: voucher/check.php + voucher_code on POST /transaction/index.php (voucher/engine.php).

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once 'engine.php';

const VOUCHER_MAX_BATCH = 2000;

function voucherIsBatch(array $row): bool {
    return (int)($row['code_count'] ?? 0) > 0;
}

/** Rows of a child table for many vouchers: voucher_id => [values]. */
function voucherChildMap(mysqli $conn, string $schema, string $table, string $column, array $ids, ?string $typeColumn = null): array {
    if (!$ids) return [];
    [$in, $types] = accessInList($ids);
    $select = $typeColumn ? "voucher_id, $typeColumn AS t, $column AS v" : "voucher_id, $column AS v";
    $stmt = $conn->prepare("SELECT $select FROM {$schema}.$table WHERE voucher_id IN ($in)");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result() as $r) {
        if ($typeColumn) $out[$r['voucher_id']][$r['t']][] = $r['v'];
        else $out[$r['voucher_id']][] = $r['v'];
    }
    $stmt->close();
    return $out;
}

/** "Owner" or "Mitra · Raki Bintaro" for each creator username. */
function voucherCreatorLabels(mysqli $conn, array $usernames): array {
    $usernames = array_values(array_unique(array_filter($usernames)));
    if (!$usernames) return [];
    $core = MOVIRA_CORE_SCHEMA;
    [$in, $types] = accessInList($usernames);
    $stmt = $conn->prepare("SELECT u.username, u.app_role_id, c.company_name FROM {$core}.app_user u LEFT JOIN {$core}.app_company c ON c.company_id = u.company_id WHERE u.username IN ($in) AND u.app_id = '" . ACCOUNT_RAKI_APP_ID . "'");
    $stmt->bind_param($types, ...$usernames);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result() as $r) {
        $out[$r['username']] = $r['app_role_id'] === ACCESS_ROLE_MITRA ? 'Mitra · ' . ($r['company_name'] ?? '') : ($r['app_role_id'] === ACCESS_ROLE_OWNER ? 'Owner' : $r['username']);
    }
    $stmt->close();
    return $out;
}

function voucherHydrate(mysqli $conn, string $schema, array $rows): array {
    if (!$rows) return [];
    $ids = array_column($rows, 'voucher_id');
    $companies = voucherChildMap($conn, $schema, 'voucher_company', 'company_id', $ids);
    $scopes = voucherChildMap($conn, $schema, 'voucher_scope', 'scope_id', $ids, 'scope_type');
    $labels = voucherCreatorLabels($conn, array_column($rows, 'created_by'));

    [$in, $types] = accessInList($ids);
    $stmt = $conn->prepare("SELECT vu.voucher_id, COUNT(*) AS used, COALESCE(SUM(vu.discount_amount),0) AS disc, COALESCE(SUM(t.total_amount),0) AS omzet
        FROM {$schema}.voucher_usage vu LEFT JOIN {$schema}.transaction t ON t.transaction_id = vu.transaction_id
        WHERE vu.voucher_id IN ($in) GROUP BY vu.voucher_id");
    $stmt->bind_param($types, ...$ids);
    $stmt->execute();
    $usage = [];
    foreach ($stmt->get_result() as $r) $usage[$r['voucher_id']] = $r;
    $stmt->close();

    $out = [];
    foreach ($rows as $r) {
        $id = $r['voucher_id'];
        $r['is_archived'] = (int)$r['is_archived'];
        $r['channels'] = voucherChannels($r['channels'] ?? null) ?: ['outlet', 'abang'];
        $r['company_ids'] = $companies[$id] ?? [];
        $r['category_ids'] = $scopes[$id]['category'] ?? [];
        $r['menu_ids'] = $scopes[$id]['menu'] ?? [];
        $r['used_count'] = (int)($usage[$id]['used'] ?? 0);
        $r['discount_total'] = (int)($usage[$id]['disc'] ?? 0);
        $r['omzet_total'] = (int)($usage[$id]['omzet'] ?? 0);
        $r['created_by_label'] = $labels[$r['created_by']] ?? $r['created_by'];
        $out[] = $r;
    }
    return $out;
}

/** Companies whose vouchers the caller manages. */
function voucherVisibleCompanies(mysqli $conn, object $decoded): array {
    $ids = accessCompanyIds($conn, $decoded);
    return $ids ?: ['-'];
}

function voucherFindManaged(mysqli $conn, string $schema, object $decoded, string $voucherId): ?array {
    $companies = voucherVisibleCompanies($conn, $decoded);
    [$in, $types] = accessInList($companies);
    $stmt = $conn->prepare("SELECT * FROM {$schema}.voucher WHERE voucher_id = ? AND company_id IN ($in)");
    $params = array_merge([$voucherId], $companies);
    $stmt->bind_param('s' . $types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    return $row;
}

function voucherParseCompanyIds(mysqli $conn, object $decoded, $raw): array {
    return accessParseCompanyIds($conn, $decoded, $raw);
}

function voucherParseChannels($raw): ?string {
    return accessParseChannels($raw);
}

function voucherReplaceChildren(mysqli $conn, string $schema, string $voucherId, ?array $companyIds, ?array $categoryIds, ?array $menuIds): void {
    if ($companyIds !== null) {
        $stmt = $conn->prepare("DELETE FROM {$schema}.voucher_company WHERE voucher_id = ?");
        $stmt->bind_param('s', $voucherId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO {$schema}.voucher_company (voucher_id, company_id) VALUES (?, ?)");
        foreach ($companyIds as $c) {
            $stmt->bind_param('ss', $voucherId, $c);
            $stmt->execute();
        }
        $stmt->close();
    }
    if ($categoryIds !== null || $menuIds !== null) {
        $stmt = $conn->prepare("DELETE FROM {$schema}.voucher_scope WHERE voucher_id = ?");
        $stmt->bind_param('s', $voucherId);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("INSERT INTO {$schema}.voucher_scope (voucher_id, scope_type, scope_id) VALUES (?, ?, ?)");
        foreach (['category' => $categoryIds ?? [], 'menu' => $menuIds ?? []] as $type => $list) {
            foreach (array_values(array_unique(array_map('strval', $list))) as $sid) {
                $stmt->bind_param('sss', $voucherId, $type, $sid);
                $stmt->execute();
            }
        }
        $stmt->close();
    }
}

function voucherInsertBatch(mysqli $conn, string $schema, string $voucherId, string $prefix, int $count): void {
    $stmt = $conn->prepare("INSERT IGNORE INTO {$schema}.voucher_code (code, voucher_id) VALUES (?, ?)");
    $made = 0;
    $guard = 0;
    while ($made < $count) {
        if (++$guard > $count * 20) throw new Exception('Could not generate enough unique codes');
        $code = voucherRandomCode($prefix);
        $stmt->bind_param('ss', $code, $voucherId);
        $stmt->execute();
        if ($stmt->affected_rows === 1) $made++;
    }
    $stmt->close();
}

function createVoucher($conn, $schema, $input, object $decoded){
    $username = $decoded->username;
    $company = $decoded->company_id;
    if (!is_array($input)) jsonResponse(400, 'Invalid JSON body');

    $unique = isset($input['code_prefix']) && trim((string)$input['code_prefix']) !== '' && (int)($input['code_count'] ?? 0) > 0;
    $required = ['voucher_name', 'discount_type', 'discount_value', 'usage_type', 'start_date', 'end_date', 'is_active'];
    if (!$unique) $required[] = 'voucher_code';
    foreach ($required as $field) {
        if (!isset($input[$field]) || $input[$field] === '') {
            jsonResponse(400, "$field is required");
        }
    }

    $code_prefix = null;
    $code_count = null;
    if ($unique) {
        $code_prefix = voucherNormalizeCode($input['code_prefix']);
        $code_count = (int)$input['code_count'];
        if (!preg_match('/^[A-Z0-9]{2,20}$/', $code_prefix)) jsonResponse(400, 'code_prefix harus 2-20 huruf/angka');
        if ($code_count < 1 || $code_count > VOUCHER_MAX_BATCH) jsonResponse(400, 'code_count harus 1-' . VOUCHER_MAX_BATCH);
        if ($input['usage_type'] !== 'one_time') jsonResponse(400, 'Kode unik harus usage_type one_time');
        $voucher_code = $code_prefix;
    } else {
        $voucher_code = voucherNormalizeCode($input['voucher_code']);
    }

    $voucher_name = trim($input['voucher_name']);
    $discount_type = $input['discount_type'];
    $discount_value = (int)$input['discount_value'];
    $min_transaction = isset($input['min_transaction']) ? (int)$input['min_transaction'] : 0;
    $max_discount = isset($input['max_discount']) ? (int)$input['max_discount'] : null;
    $usage_type = $input['usage_type'];
    $max_total_usage = isset($input['max_total_usage']) ? (int)$input['max_total_usage'] : null;
    $start_date = $input['start_date'];
    $end_date = $input['end_date'];
    $is_active = (int)$input['is_active'];

    if (!in_array($is_active, [0,1])) jsonResponse(400, 'is_active must be 0 or 1');
    if (!in_array($discount_type, ['nominal','percentage'])) jsonResponse(400, 'Invalid discount_type');
    if (!in_array($usage_type, ['one_time','multi_use'])) jsonResponse(400, 'Invalid usage_type');
    if ($discount_value <= 0) jsonResponse(400, 'discount_value must be greater than 0');
    if ($discount_type === 'percentage' && $discount_value > 100) jsonResponse(400, 'percentage discount cannot be more than 100');
    if (!strtotime($start_date) || !strtotime($end_date)) jsonResponse(400, 'Invalid date format');
    if ($start_date > $end_date) jsonResponse(400, 'start_date cannot be greater than end_date');

    $companyIds = voucherParseCompanyIds($conn, $decoded, $input['company_ids'] ?? null);
    $channels = voucherParseChannels($input['channels'] ?? null);
    $categoryIds = $input['category_ids'] ?? [];
    $menuIds = $input['menu_ids'] ?? [];
    if (!is_array($categoryIds) || !is_array($menuIds)) jsonResponse(400, 'category_ids and menu_ids must be arrays');

    $stmt = $conn->prepare("SELECT 1 FROM {$schema}.voucher WHERE voucher_code = ? LIMIT 1");
    $stmt->bind_param('s', $voucher_code);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        jsonResponse(409, 'Kode voucher sudah dipakai');
    }
    $stmt->close();

    $voucher_id = "voucher" . uniqid();
    $now = getCurrentDateTimeJakarta();

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO {$schema}.voucher (voucher_id, voucher_code, voucher_name, discount_type, discount_value, min_transaction, max_discount, usage_type, max_total_usage, start_date, end_date, is_active, company_id, created_by, created_at, code_prefix, code_count, channels) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssssiiisississssis', $voucher_id, $voucher_code, $voucher_name, $discount_type, $discount_value, $min_transaction, $max_discount, $usage_type, $max_total_usage, $start_date, $end_date, $is_active, $company, $username, $now, $code_prefix, $code_count, $channels);
        $stmt->execute();
        $stmt->close();
        voucherReplaceChildren($conn, $schema, $voucher_id, $companyIds, $categoryIds, $menuIds);
        if ($unique) voucherInsertBatch($conn, $schema, $voucher_id, $code_prefix, $code_count);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    $row = voucherFindManaged($conn, $schema, $decoded, $voucher_id);
    jsonResponse(201, 'Voucher created successfully', voucherHydrate($conn, $schema, [$row])[0] ?? ['voucher_id' => $voucher_id]);
}

function getAllVoucher($conn, $params, $schema, object $decoded, $page = 1, $limit = 10){
    $page = max(1, $page);
    $limit = pageLimit($limit);
    $offset = ($page - 1) * $limit;

    $companies = voucherVisibleCompanies($conn, $decoded);
    [$in, $types] = accessInList($companies);
    $where = "WHERE company_id IN ($in)";
    $bind = $companies;

    if (!empty($params['search'])) {
        $kw = '%' . $params['search'] . '%';
        $where .= " AND (voucher_code LIKE ? OR voucher_name LIKE ?)";
        $types .= 'ss';
        $bind[] = $kw;
        $bind[] = $kw;
    }
    if (isset($params['is_archived']) && $params['is_archived'] !== '') {
        $where .= " AND is_archived = ?";
        $types .= 'i';
        $bind[] = (int)$params['is_archived'];
    }

    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM {$schema}.voucher $where");
    $countStmt->bind_param($types, ...$bind);
    $countStmt->execute();
    $total_data = (int)$countStmt->get_result()->fetch_assoc()['total'];
    $countStmt->close();

    $dataStmt = $conn->prepare("SELECT * FROM {$schema}.voucher $where ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $bind[] = $limit;
    $bind[] = $offset;
    $dataStmt->bind_param($types . 'ii', ...$bind);
    $dataStmt->execute();
    $rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $dataStmt->close();

    jsonResponse(200, 'Voucher list retrieved successfully', [
        'data' => voucherHydrate($conn, $schema, $rows),
        'pagination' => [
            'current_page' => $page,
            'limit' => $limit,
            'total_data' => $total_data,
            'total_page' => (int)ceil($total_data / $limit)
        ]
    ]);
}

function getDetailVoucher($conn, $schema, $voucher_id, object $decoded){
    if (!$voucher_id) jsonResponse(400, 'voucher_id is required');
    $row = voucherFindManaged($conn, $schema, $decoded, $voucher_id);
    if (!$row) jsonResponse(404, 'Voucher not found');
    jsonResponse(200, 'Voucher detail retrieved successfully', voucherHydrate($conn, $schema, [$row])[0]);
}

function updateVoucher($conn, $schema, $input, object $decoded){
    $voucher_id = $input['voucher_id'] ?? null;
    if (!$voucher_id) jsonResponse(400, 'voucher_id is required');

    $existing = voucherFindManaged($conn, $schema, $decoded, $voucher_id);
    if (!$existing) jsonResponse(404, 'Voucher not found');
    if (accessIsMitra($decoded) && $existing['company_id'] !== $decoded->company_id) {
        jsonResponse(403, 'Voucher ini bukan milik outletmu');
    }

    $setParts = [];
    $types    = '';
    $values   = [];
    $add = function (string $col, string $t, $v) use (&$setParts, &$types, &$values) {
        $setParts[] = "$col = ?";
        $types .= $t;
        $values[] = $v;
    };

    $batch = voucherIsBatch($existing);
    // The code (or prefix) and size of a unique batch are fixed once its codes exist.
    if (isset($input['voucher_code']) && !$batch) {
        $newCode = voucherNormalizeCode($input['voucher_code']);
        if ($newCode !== $existing['voucher_code'] && voucherUsedCount($conn, $schema, $voucher_id) > 0) {
            jsonResponse(409, 'Kode voucher yang sudah dipakai tidak bisa diubah');
        }
        $add('voucher_code', 's', $newCode);
    }
    if (isset($input['voucher_name'])) $add('voucher_name', 's', trim($input['voucher_name']));
    if (isset($input['discount_type'])) {
        if (!in_array($input['discount_type'], ['nominal','percentage'])) jsonResponse(400, 'Invalid discount_type');
        $add('discount_type', 's', $input['discount_type']);
    }
    if (isset($input['discount_value'])) {
        $dv = (int)$input['discount_value'];
        if ($dv <= 0) jsonResponse(400, 'discount_value must be greater than 0');
        $add('discount_value', 'i', $dv);
    }
    if (isset($input['min_transaction'])) $add('min_transaction', 'i', (int)$input['min_transaction']);
    if (array_key_exists('max_discount', $input)) $add('max_discount', 'i', $input['max_discount'] !== null ? (int)$input['max_discount'] : null);
    if (isset($input['usage_type']) && !$batch) {
        if (!in_array($input['usage_type'], ['one_time','multi_use'])) jsonResponse(400, 'Invalid usage_type');
        $add('usage_type', 's', $input['usage_type']);
    }
    if (array_key_exists('max_total_usage', $input) && !$batch) $add('max_total_usage', 'i', $input['max_total_usage'] !== null ? (int)$input['max_total_usage'] : null);
    if (isset($input['start_date'])) {
        if (!strtotime($input['start_date'])) jsonResponse(400, 'Invalid start_date');
        $add('start_date', 's', $input['start_date']);
    }
    if (isset($input['end_date'])) {
        if (!strtotime($input['end_date'])) jsonResponse(400, 'Invalid end_date');
        $add('end_date', 's', $input['end_date']);
    }
    if (isset($input['is_active'])) {
        $a = (int)$input['is_active'];
        if (!in_array($a, [0,1])) jsonResponse(400, 'is_active must be 0 or 1');
        $add('is_active', 'i', $a);
    }
    if (isset($input['is_archived'])) {
        $a = (int)$input['is_archived'];
        if (!in_array($a, [0,1])) jsonResponse(400, 'is_archived must be 0 or 1');
        $add('is_archived', 'i', $a);
    }
    if (array_key_exists('channels', $input)) $add('channels', 's', voucherParseChannels($input['channels']));

    $companyIds = array_key_exists('company_ids', $input) ? voucherParseCompanyIds($conn, $decoded, $input['company_ids']) : null;
    $categoryIds = array_key_exists('category_ids', $input) || array_key_exists('menu_ids', $input) ? ($input['category_ids'] ?? []) : null;
    $menuIds = $categoryIds !== null ? ($input['menu_ids'] ?? []) : null;

    if (!$setParts && $companyIds === null && $categoryIds === null) {
        jsonResponse(400, 'No fields provided for update');
    }

    $conn->begin_transaction();
    try {
        if ($setParts) {
            $stmt = $conn->prepare("UPDATE {$schema}.voucher SET " . implode(', ', $setParts) . " WHERE voucher_id = ?");
            $types .= 's';
            $values[] = $voucher_id;
            $stmt->bind_param($types, ...$values);
            $stmt->execute();
            $stmt->close();
        }
        voucherReplaceChildren($conn, $schema, $voucher_id, $companyIds, $categoryIds, $menuIds);
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        if ($e->getCode() === 1062) jsonResponse(409, 'Kode voucher sudah dipakai');
        throw $e;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    jsonResponse(200, 'Voucher updated successfully');
}

function deleteVoucher($conn, $schema, $voucher_id, object $decoded){
    if (!$voucher_id) jsonResponse(400, 'voucher_id is required');
    $existing = voucherFindManaged($conn, $schema, $decoded, $voucher_id);
    if (!$existing) jsonResponse(404, 'Voucher not found');
    if (accessIsMitra($decoded) && $existing['company_id'] !== $decoded->company_id) {
        jsonResponse(403, 'Voucher ini bukan milik outletmu');
    }
    if (voucherUsedCount($conn, $schema, $voucher_id) > 0) {
        jsonResponse(409, 'Voucher sudah pernah dipakai. Arsipkan saja.', ['reason' => 'used']);
    }

    $conn->begin_transaction();
    try {
        foreach (['voucher_company', 'voucher_scope', 'voucher_code', 'voucher'] as $table) {
            $stmt = $conn->prepare("DELETE FROM {$schema}.$table WHERE voucher_id = ?");
            $stmt->bind_param('s', $voucher_id);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    jsonResponse(200, 'Voucher deleted successfully');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$decoded = requireAuth();
$conn    = DB::conn();
$schema  = DB_SCHEMA;
$method  = $_SERVER['REQUEST_METHOD'];

try {
    requireManager($decoded);
    switch ($method) {
        case 'GET':
            $voucher_id = $_GET['voucher_id'] ?? null;
            if ($voucher_id) {
                getDetailVoucher($conn, $schema, $voucher_id, $decoded);
            } else {
                getAllVoucher($conn, $_GET, $schema, $decoded, (int)($_GET['page'] ?? 1), (int)($_GET['limit'] ?? 10));
            }
            break;
        case 'POST':
            createVoucher($conn, $schema, json_decode(file_get_contents('php://input'), true), $decoded);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) jsonResponse(400, 'Invalid JSON body');
            updateVoucher($conn, $schema, $input, $decoded);
            break;
        case 'DELETE':
            deleteVoucher($conn, $schema, $_GET['voucher_id'] ?? null, $decoded);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Throwable $e) {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/voucher/voucher.php',
        'method'          => $method,
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error');
}
