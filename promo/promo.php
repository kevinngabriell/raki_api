<?php
// /promo/promo.php - configure promos from the dashboard.
//
//   GET    (any logged-in user)  list / detail of the caller's company promos
//   POST   (Owner)               create
//   PUT    (Owner)               partial update
//   DELETE (Owner)               remove
//
// Promos are per company and always scoped to the company in the JWT. Who may
// write is PROMO_MANAGER_ROLES in engine.php. The rules themselves are evaluated
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

    if (array_key_exists('promo_type', $input) && $input['promo_type'] !== PROMO_TYPE_BUY_N_NOMINAL_OFF) {
        jsonResponse(400, 'promo_type must be ' . PROMO_TYPE_BUY_N_NOMINAL_OFF);
    }

    foreach (PROMO_INT_FIELDS as $field => [$min, $nullable]) {
        if (!array_key_exists($field, $input)) {
            if ($isCreate && !$nullable) {
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
    $stmt = $conn->prepare("SELECT role_name FROM movira_core_dev.app_role WHERE app_id = ? ORDER BY role_name");
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

function promoFindOne(mysqli $conn, string $schema, string $promoId, string $companyId): ?array {
    $stmt = $conn->prepare("SELECT * FROM {$schema}.promo WHERE promo_id = ? AND company_id = ?");
    $stmt->bind_param('ss', $promoId, $companyId);
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

function createPromo(mysqli $conn, string $schema, array $input, string $username, string $companyId): void {
    $fields = promoParseFields($input, true);
    $conditions = promoParseConditions($conn, $schema, $input, $companyId);
    assertItemPriceRange($fields['min_item_price'] ?? null, $fields['max_item_price'] ?? null);

    $fields['promo_type'] = PROMO_TYPE_BUY_N_NOMINAL_OFF;
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
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    jsonResponse(201, 'Promo created successfully', promoFindOne($conn, $schema, $promoId, $companyId));
}

function updatePromo(mysqli $conn, string $schema, array $input, string $username, string $companyId): void {
    $promoId = $input['promo_id'] ?? null;
    if (!is_string($promoId) || $promoId === '') {
        jsonResponse(400, 'promo_id is required');
    }
    $existing = promoFindOne($conn, $schema, $promoId, $companyId);
    if (!$existing) {
        jsonResponse(404, 'Promo not found');
    }

    $fields = promoParseFields($input, false);
    $conditions = promoParseConditions($conn, $schema, $input, $companyId);
    if (!$fields && !$conditions) {
        jsonResponse(400, 'No fields provided for update');
    }
    assertItemPriceRange(
        array_key_exists('min_item_price', $fields) ? $fields['min_item_price'] : $existing['min_item_price'],
        array_key_exists('max_item_price', $fields) ? $fields['max_item_price'] : $existing['max_item_price']
    );

    $fields['updated_by'] = $username;
    $assignments = array_map(fn($c) => "$c = ?", array_keys($fields));
    $sql = "UPDATE {$schema}.promo SET " . implode(', ', $assignments) . ", updated_at = NOW() WHERE promo_id = ? AND company_id = ?";
    $values = array_merge(array_values($fields), [$promoId, $companyId]);
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
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    jsonResponse(200, 'Promo updated successfully', promoFindOne($conn, $schema, $promoId, $companyId));
}

function deletePromo(mysqli $conn, string $schema, ?string $promoId, string $companyId): void {
    if (!$promoId) {
        jsonResponse(400, 'promo_id is required');
    }
    $stmt = $conn->prepare("DELETE FROM {$schema}.promo WHERE promo_id = ? AND company_id = ?");
    $stmt->bind_param('ss', $promoId, $companyId);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    if ($deleted === 0) {
        jsonResponse(404, 'Promo not found');
    }
    jsonResponse(200, 'Promo deleted successfully');
}

function listPromos(mysqli $conn, string $schema, array $query, string $companyId): void {
    $page = max(1, (int)($query['page'] ?? 1));
    $limit = min(100, max(1, (int)($query['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = 'company_id = ?';
    $params = [$companyId];
    $types = 's';
    if (isset($query['is_active']) && $query['is_active'] !== '') {
        $where .= ' AND is_active = ?';
        $params[] = (int)(bool)filter_var($query['is_active'], FILTER_VALIDATE_BOOLEAN);
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

$decoded = promoAuthenticate();
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
            if (!empty($_GET['promo_id'])) {
                $promo = promoFindOne($conn, $schema, $_GET['promo_id'], $companyId);
                if (!$promo) {
                    jsonResponse(404, 'Promo not found');
                }
                jsonResponse(200, 'Promo detail retrieved successfully', $promo);
            }
            listPromos($conn, $schema, $_GET, $companyId);
            break;

        case 'POST':
        case 'PUT':
            requirePromoManager($roleName);
            $input = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($input)) {
                jsonResponse(400, 'Invalid JSON body');
            }
            $method === 'POST'
                ? createPromo($conn, $schema, $input, $username, $companyId)
                : updatePromo($conn, $schema, $input, $username, $companyId);
            break;

        case 'DELETE':
            requirePromoManager($roleName);
            deletePromo($conn, $schema, $_GET['promo_id'] ?? null, $companyId);
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
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
