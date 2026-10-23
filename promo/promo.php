<?php
// /promo/promo.php - configure promos from the dashboard.
//
//   GET    (any logged-in user)  list / detail of the caller's company promos
//   POST   (Owner)               create
//   PUT    (Owner)               partial update
//   DELETE (Owner)               remove
//
// A promo is owned by the company that created it. Owner: any outlets (company_ids, empty = all); Mitra:
// forced to their own outlet. GET lists what the caller manages. Who may write is PROMO_MANAGER_ROLES in engine.php. The rules themselves are evaluated
// by the same engine that /promo/check.php and /transaction/index.php use.

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once 'engine.php';

header('Content-Type: application/json');

// API field => promo_condition.condition_type
const PROMO_CONDITION_FIELDS = [
    'days_of_week'     => 'day_of_week',
    'category_ids'     => 'category',
    'menu_ids'         => 'menu',
    'exclude_menu_ids' => 'exclude_menu',
    'outlet_types'     => 'outlet_type',
];

// column => [minimum, nullable]
const PROMO_INT_FIELDS = [
    'buy_quantity'          => [1, false],
    'discount_amount'       => [1, false],
    'max_applications'      => [1, true],
    'min_item_price'        => [0, true],
    'max_item_price'        => [0, true],
    'min_eligible_subtotal' => [0, true],
];

function promoToInt($value): ?int {
    if (is_int($value)) {
        return $value;
    }
    if (is_float($value) && floor($value) === $value) {
        return (int)$value;
    }
    if (is_string($value) && preg_match('/^-?\d+$/', trim($value))) {
        return (int)trim($value);
    }
    return null;
}

// Validates the scalar fields. On create buy_quantity and discount_amount are
// required; on update only the keys present are touched (null clears a nullable one).
function promoParseFields(array $input, bool $isCreate): array {
    $fields = [];

    if ($isCreate || array_key_exists('promo_name', $input)) {
        $name = isset($input['promo_name']) && is_string($input['promo_name']) ? trim($input['promo_name']) : '';
        if ($name === '' || mb_strlen($name) > 255) {
            jsonResponse(400, 'promo_name is required (max 255 characters)');
        }
        $fields['promo_name'] = $name;
    }

    if (array_key_exists('promo_type', $input)) {
        if (!in_array($input['promo_type'], PROMO_TYPES, true)) {
            jsonResponse(400, 'promo_type must be one of ' . implode(', ', PROMO_TYPES));
        }
        $fields['promo_type'] = $input['promo_type'];
    } elseif ($isCreate) {
        $fields['promo_type'] = PROMO_TYPE_BUY_N_NOMINAL_OFF;
    }

    foreach (['start_date', 'end_date'] as $dateField) {
        if (!array_key_exists($dateField, $input)) continue;
        if ($input[$dateField] === null || $input[$dateField] === '') {
            $fields[$dateField] = null;
            continue;
        }
        $d = DateTime::createFromFormat('Y-m-d', (string)$input[$dateField]);
        if (!$d || $d->format('Y-m-d') !== $input[$dateField]) {
            jsonResponse(400, "$dateField must be YYYY-MM-DD");
        }
        $fields[$dateField] = $input[$dateField];
    }
    if (!empty($fields['start_date']) && !empty($fields['end_date']) && $fields['start_date'] > $fields['end_date']) {
        jsonResponse(400, 'start_date cannot be greater than end_date');
    }

    if (array_key_exists('is_archived', $input)) {
        $arch = filter_var($input['is_archived'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($arch === null) {
            jsonResponse(400, 'is_archived must be 0 or 1');
        }
        $fields['is_archived'] = $arch ? 1 : 0;
    }
    if (array_key_exists('channels', $input)) {
        $fields['channels'] = accessParseChannels($input['channels']);
    }

    foreach (PROMO_INT_FIELDS as $field => [$min, $nullable]) {
        if (!array_key_exists($field, $input)) {
            if ($isCreate && !$nullable) {
                if ($field === 'buy_quantity' && ($input['promo_type'] ?? PROMO_TYPE_BUY_N_NOMINAL_OFF) !== PROMO_TYPE_BUY_N_NOMINAL_OFF) {
                    $fields['buy_quantity'] = 1;
                    continue;
                }
                jsonResponse(400, "$field is required");
            }
            continue;
        }
        if ($input[$field] === null) {
            if (!$nullable) {
                jsonResponse(400, "$field cannot be null");
            }
            $fields[$field] = null;
            continue;
        }
        $value = promoToInt($input[$field]);
        if ($value === null || $value < $min) {
            jsonResponse(400, "$field must be an integer >= $min");
        }
        $fields[$field] = $value;
    }

    if (($fields['promo_type'] ?? $input['promo_type'] ?? null) === PROMO_TYPE_PERCENT_OFF && ($fields['discount_amount'] ?? 0) > 100) {
        jsonResponse(400, 'discount_amount cannot be more than 100 for percent_off');
    }

    if (array_key_exists('is_active', $input)) {
        $active = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($active === null) {
            jsonResponse(400, 'is_active must be 0 or 1');
        }
        $fields['is_active'] = $active ? 1 : 0;
    } elseif ($isCreate) {
        $fields['is_active'] = 1;
    }

    return $fields;
}

// Number of ids from $ids that exist in $table (menus/categories are global when
// company_id is NULL, or belong to the caller's company).
function promoCountExisting(mysqli $conn, string $schema, string $table, string $column, array $ids, string $companyId): int {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(
        "SELECT COUNT(DISTINCT $column) AS c FROM {$schema}.$table WHERE $column IN ($placeholders) AND (company_id IS NULL OR company_id = ?)"
    );
    $stmt->bind_param(str_repeat('s', count($ids) + 1), ...array_merge($ids, [$companyId]));
    $stmt->execute();
    $count = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $count;
}

// Validates the condition arrays that are present. Returns condition_type => values
// (a present-but-empty array clears that dimension).
function promoParseConditions(mysqli $conn, string $schema, array $input, string $companyId): array {
    $conditions = [];

    foreach (PROMO_CONDITION_FIELDS as $field => $type) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $raw = $input[$field] ?? [];
        if (!is_array($raw)) {
            jsonResponse(400, "$field must be an array");
        }

        $values = [];
        foreach ($raw as $item) {
            if ($type === 'day_of_week') {
                $day = promoToInt($item);
                if ($day === null || $day < 1 || $day > 7) {
                    jsonResponse(400, 'days_of_week values must be integers 1-7 (1 = Monday, 7 = Sunday)');
                }
                $values[] = (string)$day;
            } else {
                if (!is_string($item) || trim($item) === '' || mb_strlen(trim($item)) > 100) {
                    jsonResponse(400, "$field values must be non-empty strings");
                }
                $values[] = trim($item);
            }
        }
        $values = array_values(array_unique($values));

        if ($values && $type === 'category') {
            if (promoCountExisting($conn, $schema, 'category_menu', 'category_id', $values, $companyId) !== count($values)) {
                jsonResponse(400, 'One or more category_ids do not exist');
            }
        }
        if ($values && ($type === 'menu' || $type === 'exclude_menu')) {
            if (promoCountExisting($conn, $schema, 'menu', 'menu_id', $values, $companyId) !== count($values)) {
                jsonResponse(400, "One or more $field do not exist");
            }
        }
        if ($values && $type === 'outlet_type') {
            $known = promoOutletTypes($conn);
            $canonical = [];
            foreach ($values as $v) {
                $hit = array_values(array_filter($known, fn($k) => strcasecmp($k, $v) === 0));
                if (!$hit) {
                    jsonResponse(400, "Unknown outlet type '$v'. Valid outlet types: " . implode(', ', $known));
                }
                $canonical[] = $hit[0];
            }
            $values = array_values(array_unique($canonical));
        }

        $conditions[$type] = $values;
    }

    return $conditions;
}

// The outlet types a promo can target = the role names of the RAKI app.
function promoOutletTypes(mysqli $conn): array {
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $stmt = $conn->prepare("SELECT role_name FROM {$coreSchema}.app_role WHERE app_id = ? ORDER BY role_name");
    $appId = PROMO_APP_ID;
    $stmt->bind_param('s', $appId);
    $stmt->execute();
    $names = array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'role_name');
    $stmt->close();
    return $names;
}

function promoInsertConditions(mysqli $conn, string $schema, string $promoId, array $conditions): void {
    $stmt = $conn->prepare(
        "INSERT INTO {$schema}.promo_condition (promo_id, condition_type, condition_value) VALUES (?, ?, ?)"
    );
    foreach ($conditions as $type => $values) {
        foreach ($values as $value) {
            $stmt->bind_param('sss', $promoId, $type, $value);
            $stmt->execute();
        }
    }
    $stmt->close();
}

// promo rows -> API shape, with their conditions attached.
function promoHydrate(mysqli $conn, string $schema, array $rows): array {
    if (!$rows) {
        return [];
    }
    $promos = [];
    foreach ($rows as $row) {
        $promos[$row['promo_id']] = promoNormalizeRow($row);
    }

    $ids = array_keys($promos);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare(
        "SELECT promo_id, condition_type, condition_value FROM {$schema}.promo_condition WHERE promo_id IN ($placeholders)"
    );
    $stmt->bind_param(str_repeat('s', count($ids)), ...$ids);
    $stmt->execute();
    foreach ($stmt->get_result() as $row) {
        promoAddCondition($promos[$row['promo_id']], $row['condition_type'], $row['condition_value']);
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT promo_id, company_id FROM {$schema}.promo_company WHERE promo_id IN ($placeholders)");
    $stmt->bind_param(str_repeat('s', count($ids)), ...$ids);
    $stmt->execute();
    $outlets = [];
    foreach ($stmt->get_result() as $row) {
        $outlets[$row['promo_id']][] = $row['company_id'];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT tp.promo_id, COUNT(*) AS used, COALESCE(SUM(tp.discount_amount),0) AS disc, COALESCE(SUM(t.total_amount),0) AS omzet
        FROM {$schema}.transaction_promo tp LEFT JOIN {$schema}.transaction t ON t.transaction_id = tp.transaction_id
        WHERE tp.promo_id IN ($placeholders) GROUP BY tp.promo_id");
    $stmt->bind_param(str_repeat('s', count($ids)), ...$ids);
    $stmt->execute();
    $usage = [];
    foreach ($stmt->get_result() as $row) {
        $usage[$row['promo_id']] = $row;
    }
    $stmt->close();

    $creators = array_values(array_unique(array_filter(array_column($rows, 'created_by'))));
    $labels = [];
    if ($creators) {
        $core = MOVIRA_CORE_SCHEMA;
        [$cin, $ctypes] = accessInList($creators);
        $stmt = $conn->prepare("SELECT u.username, u.app_role_id, c.company_name FROM {$core}.app_user u LEFT JOIN {$core}.app_company c ON c.company_id = u.company_id WHERE u.username IN ($cin) AND u.app_id = ?");
        $params = array_merge($creators, [ACCOUNT_RAKI_APP_ID]);
        $stmt->bind_param($ctypes . 's', ...$params);
        $stmt->execute();
        foreach ($stmt->get_result() as $row) {
            $labels[$row['username']] = $row['app_role_id'] === ACCESS_ROLE_MITRA ? 'Mitra · ' . ($row['company_name'] ?? '') : ($row['app_role_id'] === ACCESS_ROLE_OWNER ? 'Owner' : $row['username']);
        }
        $stmt->close();
    }

    $out = [];
    foreach ($promos as $p) {
        $c = $p['conditions'];
        sort($c['day_of_week']);
        $out[] = [
            'promo_id'              => $p['promo_id'],
            'company_id'            => $p['company_id'],
            'promo_name'            => $p['promo_name'],
            'promo_type'            => $p['promo_type'],
            'buy_quantity'          => $p['buy_quantity'],
            'discount_amount'       => $p['discount_amount'],
            'max_applications'      => $p['max_applications'],
            'min_item_price'        => $p['min_item_price'],
            'max_item_price'        => $p['max_item_price'],
            'min_eligible_subtotal' => $p['min_eligible_subtotal'],
            'is_active'             => $p['is_active'],
            'is_archived'           => $p['is_archived'],
            'start_date'            => $p['start_date'],
            'end_date'              => $p['end_date'],
            'channels'              => $p['channels'] ?: ['outlet', 'abang'],
            'company_ids'           => $outlets[$p['promo_id']] ?? [],
            'used_count'            => (int)($usage[$p['promo_id']]['used'] ?? 0),
            'discount_total'        => (int)($usage[$p['promo_id']]['disc'] ?? 0),
            'omzet_total'           => (int)($usage[$p['promo_id']]['omzet'] ?? 0),
            'created_by_label'      => $labels[$p['created_by']] ?? $p['created_by'],
            'days_of_week'          => $c['day_of_week'],
            'category_ids'          => $c['category'],
            'menu_ids'              => $c['menu'],
            'exclude_menu_ids'      => $c['exclude_menu'],
            'outlet_types'          => $c['outlet_type'],
            'created_by'            => $p['created_by'],
            'created_at'            => $p['created_at'],
            'updated_by'            => $p['updated_by'],
            'updated_at'            => $p['updated_at'],
        ];
    }
    return $out;
}

// Companies whose promos the caller manages (Owner: all own outlets, Mitra: own).
function promoVisibleCompanies(mysqli $conn, object $decoded): array {
    return accessCompanyIds($conn, $decoded) ?: ['-'];
}

function promoFindOne(mysqli $conn, string $schema, string $promoId, array $companies): ?array {
    [$in, $types] = accessInList($companies);
    $stmt = $conn->prepare("SELECT * FROM {$schema}.promo WHERE promo_id = ? AND company_id IN ($in)");
    $params = array_merge([$promoId], $companies);
    $stmt->bind_param('s' . $types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? promoHydrate($conn, $schema, [$row])[0] : null;
}

function requirePromoManager(?string $roleName): void {
    if (!promoIsManagerRole($roleName)) {
        jsonResponse(403, 'Only ' . implode(' / ', PROMO_MANAGER_ROLES) . ' can manage promos');
    }
}

function assertItemPriceRange(?int $min, ?int $max): void {
    if ($min !== null && $max !== null && $min > $max) {
        jsonResponse(400, 'min_item_price cannot be greater than max_item_price');
    }
}

function promoReplaceOutlets(mysqli $conn, string $schema, string $promoId, array $companyIds): void {
    $stmt = $conn->prepare("DELETE FROM {$schema}.promo_company WHERE promo_id = ?");
    $stmt->bind_param('s', $promoId);
    $stmt->execute();
    $stmt->close();
    $stmt = $conn->prepare("INSERT INTO {$schema}.promo_company (promo_id, company_id) VALUES (?, ?)");
    foreach ($companyIds as $c) {
        $stmt->bind_param('ss', $promoId, $c);
        $stmt->execute();
    }
    $stmt->close();
}

function promoUsedCount(mysqli $conn, string $schema, string $promoId): int {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM {$schema}.transaction_promo WHERE promo_id = ?");
    $stmt->bind_param('s', $promoId);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n;
}

function createPromo(mysqli $conn, string $schema, array $input, string $username, string $companyId, object $decoded): void {
    $fields = promoParseFields($input, true);
    $conditions = promoParseConditions($conn, $schema, $input, $companyId);
    assertItemPriceRange($fields['min_item_price'] ?? null, $fields['max_item_price'] ?? null);
    $outlets = accessParseCompanyIds($conn, $decoded, $input['company_ids'] ?? null);

    $promoId = 'promo' . uniqid();
    $row = array_merge([
        'promo_id' => $promoId, 'company_id' => $companyId, 'max_applications' => null,
        'min_item_price' => null, 'max_item_price' => null, 'min_eligible_subtotal' => null,
    ], $fields, ['created_by' => $username]);

    $columns = array_keys($row);
    $sql = "INSERT INTO {$schema}.promo (" . implode(', ', $columns) . ") VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")";
    $types = implode('', array_map(fn($v) => is_int($v) ? 'i' : 's', $row));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare($sql);
        $values = array_values($row);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
        promoInsertConditions($conn, $schema, $promoId, $conditions);
        promoReplaceOutlets($conn, $schema, $promoId, $outlets);
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    jsonResponse(201, 'Promo created successfully', promoFindOne($conn, $schema, $promoId, promoVisibleCompanies($conn, $decoded)));
}

function updatePromo(mysqli $conn, string $schema, array $input, string $username, string $companyId, object $decoded): void {
    $promoId = $input['promo_id'] ?? null;
    if (!is_string($promoId) || $promoId === '') {
        jsonResponse(400, 'promo_id is required');
    }
    $visible = promoVisibleCompanies($conn, $decoded);
    $existing = promoFindOne($conn, $schema, $promoId, $visible);
    if (!$existing) {
        jsonResponse(404, 'Promo not found');
    }
    if (accessIsMitra($decoded) && $existing['company_id'] !== $companyId) {
        jsonResponse(403, 'Promo ini bukan milik outletmu');
    }

    $fields = promoParseFields($input, false);
    $conditions = promoParseConditions($conn, $schema, $input, $existing['company_id']);
    $outlets = array_key_exists('company_ids', $input) ? accessParseCompanyIds($conn, $decoded, $input['company_ids']) : null;
    if (!$fields && !$conditions && $outlets === null) {
        jsonResponse(400, 'No fields provided for update');
    }
    assertItemPriceRange(
        array_key_exists('min_item_price', $fields) ? $fields['min_item_price'] : $existing['min_item_price'],
        array_key_exists('max_item_price', $fields) ? $fields['max_item_price'] : $existing['max_item_price']
    );

    $fields['updated_by'] = $username;
    $assignments = array_map(fn($c) => "$c = ?", array_keys($fields));
    $sql = "UPDATE {$schema}.promo SET " . implode(', ', $assignments) . ", updated_at = NOW() WHERE promo_id = ?";
    $values = array_merge(array_values($fields), [$promoId]);
    $types = implode('', array_map(fn($v) => is_int($v) ? 'i' : 's', $values));

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();

        // A dimension that was sent is replaced wholesale.
        foreach ($conditions as $type => $unused) {
            $del = $conn->prepare("DELETE FROM {$schema}.promo_condition WHERE promo_id = ? AND condition_type = ?");
            $del->bind_param('ss', $promoId, $type);
            $del->execute();
            $del->close();
        }
        promoInsertConditions($conn, $schema, $promoId, $conditions);
        if ($outlets !== null) {
            promoReplaceOutlets($conn, $schema, $promoId, $outlets);
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    jsonResponse(200, 'Promo updated successfully', promoFindOne($conn, $schema, $promoId, $visible));
}

function deletePromo(mysqli $conn, string $schema, ?string $promoId, string $companyId, object $decoded): void {
    if (!$promoId) {
        jsonResponse(400, 'promo_id is required');
    }
    $existing = promoFindOne($conn, $schema, $promoId, promoVisibleCompanies($conn, $decoded));
    if (!$existing) {
        jsonResponse(404, 'Promo not found');
    }
    if (accessIsMitra($decoded) && $existing['company_id'] !== $companyId) {
        jsonResponse(403, 'Promo ini bukan milik outletmu');
    }
    if (promoUsedCount($conn, $schema, $promoId) > 0) {
        jsonResponse(409, 'Promo sudah pernah dipakai. Arsipkan saja.', ['reason' => 'used']);
    }

    $conn->begin_transaction();
    try {
        foreach (['promo_condition', 'promo_company', 'promo'] as $table) {
            $stmt = $conn->prepare("DELETE FROM {$schema}.$table WHERE promo_id = ?");
            $stmt->bind_param('s', $promoId);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    jsonResponse(200, 'Promo deleted successfully');
}

function listPromos(mysqli $conn, string $schema, array $query, array $companies): void {
    $page = max(1, (int)($query['page'] ?? 1));
    $limit = pageLimit($query['limit'] ?? null);
    $offset = ($page - 1) * $limit;

    [$in, $types] = accessInList($companies);
    $where = "company_id IN ($in)";
    $params = $companies;
    if (isset($query['is_active']) && $query['is_active'] !== '') {
        $where .= ' AND is_active = ?';
        $params[] = (int)(bool)filter_var($query['is_active'], FILTER_VALIDATE_BOOLEAN);
        $types .= 'i';
    }
    if (isset($query['is_archived']) && $query['is_archived'] !== '') {
        $where .= ' AND is_archived = ?';
        $params[] = (int)(bool)filter_var($query['is_archived'], FILTER_VALIDATE_BOOLEAN);
        $types .= 'i';
    }
    if (!empty($query['search'])) {
        $where .= ' AND promo_name LIKE ?';
        $params[] = '%' . $query['search'] . '%';
        $types .= 's';
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM {$schema}.promo WHERE $where");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT * FROM {$schema}.promo WHERE $where ORDER BY created_at DESC, promo_id LIMIT ? OFFSET ?");
    $stmt->bind_param($types . 'ii', ...array_merge($params, [$limit, $offset]));
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    jsonResponse(200, 'Promo list retrieved successfully', [
        'data' => promoHydrate($conn, $schema, $rows),
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

$decoded = requireAuth();
$conn = null;

try {
    $conn = DB::conn();
    $schema = DB_SCHEMA;
    $method = $_SERVER['REQUEST_METHOD'];

    $username = $decoded->username ?? null;
    $companyId = $decoded->company_id ?? null;
    if (!$username || !$companyId) {
        jsonResponse(403, 'company_id not found in token');
    }
    $roleName = promoResolveRoleName($conn, $decoded->role ?? null);

    switch ($method) {
        case 'GET':
            $visible = promoVisibleCompanies($conn, $decoded);
            if (!empty($_GET['promo_id'])) {
                $promo = promoFindOne($conn, $schema, $_GET['promo_id'], $visible);
                if (!$promo) {
                    jsonResponse(404, 'Promo not found');
                }
                jsonResponse(200, 'Promo detail retrieved successfully', $promo);
            }
            listPromos($conn, $schema, $_GET, $visible);
            break;

        case 'POST':
        case 'PUT':
            requirePromoManager($roleName);
            $input = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
                jsonResponse(400, 'Invalid JSON body');
            }
            $method === 'POST'
                ? createPromo($conn, $schema, $input, $username, $companyId, $decoded)
                : updatePromo($conn, $schema, $input, $username, $companyId, $decoded);
            break;

        case 'DELETE':
            requirePromoManager($roleName);
            deletePromo($conn, $schema, $_GET['promo_id'] ?? null, $companyId, $decoded);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Throwable $e) {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/promo/promo.php',
        'method'          => $_SERVER['REQUEST_METHOD'] ?? null,
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error');
}
