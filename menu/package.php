<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function packageMenuIds($value) {
    if ($value === null) {
        return null;
    }
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        $value = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $value)), 'strlen');
    }
    return is_array($value) ? array_values($value) : $value;
}

// --- Contents with choices -------------------------------------------------------------------------
// items: [{ type:"fixed", menu_id, qty } | { type:"slot", name, pick, options:[menu_id], extras:{menu_id:rupiah} }]
// menu_ids keeps being sent/stored for the fixed menus (a menu listed twice = qty 2), so older clients keep working.

function packageParseItems($raw): ?array {
    if ($raw === null || $raw === '') {
        return null;
    }
    if (is_string($raw)) {
        $raw = json_decode($raw, true);
    }
    if (!is_array($raw) || !$raw) {
        jsonResponse(400, 'items must be a non-empty array');
    }
    $out = [];
    foreach (array_values($raw) as $i => $it) {
        $type = is_array($it) ? ($it['type'] ?? '') : '';
        if ($type === 'fixed') {
            $qty = (int)($it['qty'] ?? 1);
            if (empty($it['menu_id']) || $qty < 1 || $qty > 99) {
                jsonResponse(400, "items[$i]: fixed needs menu_id and qty 1-99");
            }
            $out[] = ['type' => 'fixed', 'menu_id' => (string)$it['menu_id'], 'qty' => $qty];
        } elseif ($type === 'slot') {
            $options = array_values(array_unique(array_map('strval', (array)($it['options'] ?? []))));
            $pick = (int)($it['pick'] ?? 1);
            $name = trim((string)($it['name'] ?? ''));
            if ($name === '' || !$options || $pick < 1 || $pick > count($options)) {
                jsonResponse(400, "items[$i]: slot needs name, options and pick between 1 and the number of options");
            }
            $extras = [];
            foreach ((array)($it['extras'] ?? []) as $mid => $rupiah) {
                if (!in_array((string)$mid, $options, true) || !is_numeric($rupiah) || $rupiah < 0) {
                    jsonResponse(400, "items[$i]: extras must be rupiah (>= 0) for menus in options");
                }
                if ((int)$rupiah > 0) $extras[(string)$mid] = (int)$rupiah;
            }
            $out[] = ['type' => 'slot', 'name' => $name, 'pick' => $pick, 'options' => $options, 'extras' => (object)$extras];
        } else {
            jsonResponse(400, "items[$i]: type must be fixed or slot");
        }
    }
    return $out;
}

/** menu_ids for the fixed menus of items (repeated by qty). */
function packageFixedMenuIds(array $items): array {
    $ids = [];
    foreach ($items as $it) {
        if ($it['type'] === 'fixed') {
            for ($q = 0; $q < $it['qty']; $q++) $ids[] = $it['menu_id'];
        }
    }
    return $ids;
}

function packageAssertMenusExist($conn, $schema, array $ids): void {
    foreach (array_unique($ids) as $mid) {
        // Nonaktif / archived menus are allowed: the package simply becomes unavailable.
        if (referenceCount($conn, "SELECT COUNT(*) FROM {$schema}.menu WHERE menu_id = ?", (string)$mid) === 0) {
            jsonResponse(404, "menu_id not found: $mid");
        }
    }
}

function packageTimeOrNull($v, string $field): ?string {
    if ($v === null || $v === '') return null;
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$v)) jsonResponse(400, "$field must be HH:MM");
    return (string)$v;
}

function packageDateOrNull($v, string $field): ?string {
    if ($v === null || $v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', (string)$v);
    if (!$d || $d->format('Y-m-d') !== $v) jsonResponse(400, "$field must be YYYY-MM-DD");
    return (string)$v;
}

/**
 * Columns of the revamp that are present in $input, as [assignments, types, values]:
 * channels, status, availability, items and remove_image.
 */
function packageExtraColumns(array $input, ?array $items): array {
    $set = [];
    $types = '';
    $vals = [];
    $add = function (string $col, string $t, $v) use (&$set, &$types, &$vals) {
        $set[] = "$col = ?";
        $types .= $t;
        $vals[] = $v;
    };
    foreach (['sell_outlet', 'sell_abang', 'is_active', 'is_archived'] as $f) {
        if (isset($input[$f]) && $input[$f] !== '') {
            $v = filter_var($input[$f], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1]]);
            if ($v === false) jsonResponse(400, "$f must be 0 or 1");
            $add($f, 'i', $v);
        }
    }
    foreach (['available_from', 'available_to'] as $f) {
        if (array_key_exists($f, $input)) $add($f, 's', packageDateOrNull($input[$f], $f));
    }
    foreach (['available_time_from', 'available_time_to'] as $f) {
        if (array_key_exists($f, $input)) $add($f, 's', packageTimeOrNull($input[$f], $f));
    }
    if (array_key_exists('available_days', $input)) {
        $days = $input['available_days'];
        if (is_string($days)) $days = json_decode($days, true);
        if ($days !== null && $days !== [] && (!is_array($days) || array_diff(array_map('intval', $days), range(0, 6)))) {
            jsonResponse(400, 'available_days must be an array of 0 (Minggu) .. 6 (Sabtu)');
        }
        $add('available_days', 's', $days ? json_encode(array_values(array_unique(array_map('intval', $days)))) : null);
    }
    if ($items !== null) $add('items', 's', json_encode($items));
    return [$set, $types, $vals];
}

function packageBool($v): int { return (int)(bool)$v; }

/** Decodes the JSON columns and casts flags; adds `available_now` (is the package inside its window right now). */
function packageDecorate(array $p): array {
    $p['items'] = isset($p['items']) && $p['items'] !== null ? json_decode($p['items'], true) : null;
    $p['available_days'] = isset($p['available_days']) && $p['available_days'] !== null ? json_decode($p['available_days'], true) : [];
    foreach (['sell_outlet', 'sell_abang', 'is_active', 'is_archived'] as $f) {
        if (array_key_exists($f, $p)) $p[$f] = (int)$p[$f];
    }
    return $p;
}

/** Pure: is a package inside its availability window at $now (Asia/Jakarta)? */
function packageInWindow(array $p, DateTimeInterface $now): bool {
    $date = $now->format('Y-m-d');
    if (!empty($p['available_from']) && $date < $p['available_from']) return false;
    if (!empty($p['available_to']) && $date > $p['available_to']) return false;
    if (!empty($p['available_days']) && !in_array((int)$now->format('w'), $p['available_days'], true)) return false;
    $time = $now->format('H:i');
    if (!empty($p['available_time_from']) && $time < $p['available_time_from']) return false;
    if (!empty($p['available_time_to']) && $time > $p['available_time_to']) return false;
    return true;
}

function packageImageUpload() {
    if (empty($_FILES['image']) || !isset($_FILES['image']['error']) || $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(400, 'Upload error code: ' . $_FILES['image']['error']);
    }
    try {
        return handle_menu_image_upload($_FILES['image'], 'package');
    } catch (ApiException $e) {
        throw $e;
    } catch (RuntimeException $e) {
        error_log('packageImageUpload: ' . $e->getMessage());
        jsonResponse(500, 'Image upload failed');
    }
}

function createPackage($conn, $schema, $input, $username) {
    $package_name  = isset($input['package_name']) ? trim($input['package_name']) : null;
    $package_price = isset($input['package_price']) ? $input['package_price'] : null;
    $items         = packageParseItems($input['items'] ?? null);
    $menu_ids      = packageMenuIds($input['menu_ids'] ?? null) ?? [];
    if ($items !== null && !$menu_ids) {
        $menu_ids = packageFixedMenuIds($items);
    }

    if (!$package_name || (empty($menu_ids) && $items === null) || !is_array($menu_ids)) {
        jsonResponse(400, 'package_name and menu_ids (array) or items are required');
    }

    if ($package_price !== null && $package_price !== '' && !is_numeric($package_price)) {
        jsonResponse(400, 'package_price must be numeric');
    }
    $price_val = ($package_price !== null && $package_price !== '') ? (float)$package_price : null;

    $stmt = $conn->prepare("SELECT package_id FROM {$schema}.package WHERE package_name = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $package_name);
    $stmt->execute();
    $dupResult = $stmt->get_result();
    if ($dupResult && $dupResult->num_rows > 0) {
        $stmt->close();
        jsonResponse(400, 'Package name already exists');
    }
    $stmt->close();

    packageAssertMenusExist($conn, $schema, array_merge($menu_ids, ...array_map(fn($it) => $it['type'] === 'slot' ? $it['options'] : [], $items ?? [])));
    [$extraSet, $extraTypes, $extraVals] = packageExtraColumns($input, $items);

    $package_id = 'pkg' . uniqid();
    $now        = getCurrentDateTimeJakarta();

    [$image_url, $thumb_url] = packageImageUpload();

    mysqli_begin_transaction($conn);
    try {
        $stmt = $conn->prepare("INSERT INTO {$schema}.package (package_id, package_name, package_price, image_url, thumb_url, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception('Database query error');
        }
        $stmt->bind_param('ssdssss', $package_id, $package_name, $price_val, $image_url, $thumb_url, $username, $now);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to insert package');
        }
        $stmt->close();

        foreach ($menu_ids as $mid) {
            $pmId = 'pkgmenu' . uniqid();
            $stmt = $conn->prepare("INSERT INTO {$schema}.package_menu (package_menu_id, package_id, menu_id, created_at) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception('Database query error');
            }
            $stmt->bind_param('ssss', $pmId, $package_id, $mid, $now);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to insert package_menu');
            }
            $stmt->close();
        }

        if ($extraSet) {
            $stmt = $conn->prepare("UPDATE {$schema}.package SET " . implode(', ', $extraSet) . " WHERE package_id = ?");
            $vals = array_merge($extraVals, [$package_id]);
            $stmt->bind_param($extraTypes . 's', ...$vals);
            $stmt->execute();
            $stmt->close();
        }

        mysqli_commit($conn);
        jsonResponse(201, 'Package created successfully', ['package_id' => $package_id, 'package_name' => $package_name, 'image_url' => $image_url, 'thumb_url' => $thumb_url]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        delete_menu_image_urls([$image_url, $thumb_url]);
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/package.php',
            'method'        => 'POST',
            'error_message' => $e->getMessage(),
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Failed to create package');
    }
}

/** POS rules: inside the window, every fixed menu aktif and unarchived, every choice with at least one aktif option (nonaktif options are hidden). */
function packageFilterForTill($conn, $schema, array $packages): array {
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
    $menuOk = [];
    foreach ($conn->query("SELECT menu_id, is_active, is_archived FROM {$schema}.menu")->fetch_all(MYSQLI_ASSOC) as $m) {
        $menuOk[$m['menu_id']] = (int)$m['is_active'] === 1 && (int)$m['is_archived'] === 0;
    }
    $out = [];
    foreach ($packages as $p) {
        if (!packageInWindow($p, $now)) continue;
        if ($p['items'] === null) {
            // Legacy package: only its plain menus.
            foreach ($p['menus'] as $m) if (!$menuOk[$m['menu_id']]) continue 2;
            $out[] = $p;
            continue;
        }
        $items = [];
        foreach ($p['items'] as $it) {
            if ($it['type'] === 'fixed') {
                if (empty($menuOk[$it['menu_id']])) continue 2;
            } else {
                $it['options'] = array_values(array_filter($it['options'], fn($id) => !empty($menuOk[$id])));
                if (!$it['options']) continue 2;
                $it['pick'] = min($it['pick'], count($it['options']));
            }
            $items[] = $it;
        }
        $p['items'] = $items;
        $out[] = $p;
    }
    return $out;
}

function getAllPackages($conn, $schema, $params, $page = 1, $limit = 20, $showAll = false, $channel = null) {
    $page = (int)$page;
    $limit = pageLimit($limit);
    $offset = ($page - 1) * $limit;
    $search = '%' . ($params ?? '') . '%';

    // The till only sees active, unarchived packages sold on its channel; availability and menu rules are applied below.
    $visible = $showAll ? '' : ' AND p.is_active = 1 AND p.is_archived = 0' . ($channel === 'outlet' ? ' AND p.sell_outlet = 1' : '') . ($channel === 'abang' ? ' AND p.sell_abang = 1' : '');

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM {$schema}.package p WHERE p.package_name LIKE ?{$visible}");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $search);
    $stmt->execute();
    $countResult = $stmt->get_result();
    $total = (int)$countResult->fetch_assoc()['total'];
    $stmt->close();

    $query = "SELECT p.package_id, p.package_name, p.package_price, p.image_url, p.thumb_url,
                     p.created_by, p.created_at, p.updated_by, p.updated_at,
                     p.items, p.sell_outlet, p.sell_abang, p.is_active, p.is_archived,
                     p.available_from, p.available_to, p.available_days, p.available_time_from, p.available_time_to
              FROM {$schema}.package p
              WHERE p.package_name LIKE ?{$visible}
              ORDER BY p.created_at DESC
              LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('sii', $search, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        jsonResponse(404, 'No packages found');
    }

    $packages = array_map('packageDecorate', $result->fetch_all(MYSQLI_ASSOC));
    $stmt->close();

    foreach ($packages as &$pkg) {
        $stmt = $conn->prepare("SELECT m.menu_id, m.menu_name, m.price, cm.category_name, m.image_url, m.thumb_url, m.is_active, m.is_archived
                 FROM {$schema}.package_menu pm
                 JOIN {$schema}.menu m ON pm.menu_id = m.menu_id
                 LEFT JOIN {$schema}.category_menu cm ON m.category_id = cm.category_id
                 WHERE pm.package_id = ?");
        if ($stmt) {
            $stmt->bind_param('s', $pkg['package_id']);
            $stmt->execute();
            $menuRes = $stmt->get_result();
            $pkg['menus'] = $menuRes ? $menuRes->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
        } else {
            $pkg['menus'] = [];
        }
    }
    unset($pkg);

    if (!$showAll) {
        $packages = packageFilterForTill($conn, $schema, $packages);
    }

    jsonResponse(200, 'Packages found', [
        'data' => $packages,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ]
    ]);
}

function getDetailPackage($conn, $schema, $package_id) {
    $stmt = $conn->prepare("SELECT package_id, package_name, package_price, image_url, thumb_url, created_by, created_at, updated_by, updated_at, items, sell_outlet, sell_abang, is_active, is_archived, available_from, available_to, available_days, available_time_from, available_time_to FROM {$schema}.package WHERE package_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $package_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$result || $result->num_rows === 0) {
        $stmt->close();
        jsonResponse(404, 'Package not found');
    }

    $package = packageDecorate($result->fetch_assoc());
    $stmt->close();

    $stmt = $conn->prepare("SELECT m.menu_id, m.menu_name, m.price, cm.category_name, m.image_url, m.thumb_url
             FROM {$schema}.package_menu pm
             JOIN {$schema}.menu m ON pm.menu_id = m.menu_id
             LEFT JOIN {$schema}.category_menu cm ON m.category_id = cm.category_id
             WHERE pm.package_id = ?");
    if ($stmt) {
        $stmt->bind_param('s', $package_id);
        $stmt->execute();
        $menuRes = $stmt->get_result();
        $package['menus'] = $menuRes ? $menuRes->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();
    } else {
        $package['menus'] = [];
    }

    jsonResponse(200, 'Package found', $package);
}

function updatePackage($conn, $schema, $input, $username) {
    $package_id = trim((string)($input['package_id'] ?? ''));
    if ($package_id === '') {
        apiReject($conn, '/menu/package.php', 'PUT', $username, 400, 'package_id is required');
    }

    $stmt = $conn->prepare("SELECT package_name, image_url, thumb_url FROM {$schema}.package WHERE package_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $package_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$existing) {
        apiReject($conn, '/menu/package.php', 'PUT', $username, 404, 'Package not found');
    }

    $fields = [];
    $types = '';
    $values = [];
    $now = getCurrentDateTimeJakarta();

    if (isset($input['package_name'])) {
        $name = trim((string)$input['package_name']);
        if ($name === '') {
            apiReject($conn, '/menu/package.php', 'PUT', $username, 400, 'package_name cannot be empty');
        }
        if ($name !== $existing['package_name']) {
            $stmt = $conn->prepare("SELECT package_id FROM {$schema}.package WHERE package_name = ? AND package_id <> ?");
            if (!$stmt) {
                jsonResponse(500, 'Database query error');
            }
            $stmt->bind_param('ss', $name, $package_id);
            $stmt->execute();
            $taken = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($taken) {
                apiReject($conn, '/menu/package.php', 'PUT', $username, 400, 'Package name already exists');
            }
        }
        $fields[] = "package_name = ?";
        $types .= 's';
        $values[] = $name;
    }
    if (isset($input['package_price']) && $input['package_price'] !== '') {
        if (!is_numeric($input['package_price']) || (float)$input['package_price'] < 0) {
            apiReject($conn, '/menu/package.php', 'PUT', $username, 400, 'package_price must be a non-negative number');
        }
        $fields[] = "package_price = ?";
        $types .= 'd';
        $values[] = (float)$input['package_price'];
    }

    // Validate the menu list up front so bad input is a 400, not a rolled-back 500.
    $items = packageParseItems($input['items'] ?? null);
    $menu_ids = packageMenuIds($input['menu_ids'] ?? null);
    if ($items !== null && $menu_ids === null) {
        $menu_ids = packageFixedMenuIds($items);
    }
    if ($items !== null) {
        packageAssertMenusExist($conn, $schema, array_merge(...array_map(fn($it) => $it['type'] === 'slot' ? $it['options'] : [$it['menu_id']], $items)));
    }
    if ($menu_ids !== null) {
        if (!is_array($menu_ids) || empty($menu_ids)) {
            apiReject($conn, '/menu/package.php', 'PUT', $username, 400, 'menu_ids must be a non-empty array');
        }
        $menu_ids = array_values(array_map('strval', $menu_ids));
        packageAssertMenusExist($conn, $schema, $menu_ids);
    }

    [$extraSet, $extraTypes, $extraVals] = packageExtraColumns($input, $items);
    foreach ($extraSet as $i => $clause) {
        $fields[] = $clause;
    }
    $types .= $extraTypes;
    $values = array_merge($values, $extraVals);

    [$image_url, $thumb_url] = packageImageUpload();
    if ($image_url !== null) {
        $fields[] = "image_url = ?";
        $types .= 's';
        $values[] = $image_url;
        $fields[] = "thumb_url = ?";
        $types .= 's';
        $values[] = $thumb_url;
    }

    $removeImage = $image_url === null && filter_var($input['remove_image'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($removeImage) {
        $fields[] = "image_url = NULL";
        $fields[] = "thumb_url = NULL";
    }

    if (empty($fields) && $menu_ids === null) {
        apiReject($conn, '/menu/package.php', 'PUT', $username, 400, 'No fields provided for update');
    }

    mysqli_begin_transaction($conn);
    try {
        if (!empty($fields)) {
            $fields[] = "updated_by = ?";
            $types .= 's';
            $values[] = $username;
            $fields[] = "updated_at = ?";
            $types .= 's';
            $values[] = $now;
            $types .= 's';
            $values[] = $package_id;

            $stmt = $conn->prepare("UPDATE {$schema}.package SET " . implode(', ', $fields) . " WHERE package_id = ?");
            if (!$stmt) {
                throw new Exception('Database query error');
            }
            $stmt->bind_param($types, ...$values);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to update package');
            }
            $stmt->close();
        }

        if ($menu_ids !== null) {
            $stmt = $conn->prepare("DELETE FROM {$schema}.package_menu WHERE package_id = ?");
            if (!$stmt) {
                throw new Exception('Database query error');
            }
            $stmt->bind_param('s', $package_id);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new Exception('Failed to remove old menus');
            }
            $stmt->close();

            foreach ($menu_ids as $mid) {
                $pmId = 'pkgmenu' . uniqid();
                $stmt = $conn->prepare("INSERT INTO {$schema}.package_menu (package_menu_id, package_id, menu_id, created_at) VALUES (?, ?, ?, ?)");
                if (!$stmt) {
                    throw new Exception('Database query error');
                }
                $stmt->bind_param('ssss', $pmId, $package_id, $mid, $now);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new Exception('Failed to insert package_menu');
                }
                $stmt->close();
            }
        }

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        delete_menu_image_urls([$image_url, $thumb_url]);
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/package.php',
            'method'        => 'PUT',
            'error_message' => $e->getMessage(),
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Failed to update package');
    }

    if ($image_url !== null || $removeImage) {
        delete_menu_image_urls([$existing['image_url'], $existing['thumb_url']]);
    }
    jsonResponse(200, 'Package updated successfully', ['package_id' => $package_id]);
}

function deletePackage($conn, $schema, $package_id, $username) {
    if (!$package_id) {
        apiReject($conn, '/menu/package.php', 'DELETE', $username, 400, 'package_id is required');
    }

    $stmt = $conn->prepare("SELECT image_url, thumb_url FROM {$schema}.package WHERE package_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $package_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$existing) {
        apiReject($conn, '/menu/package.php', 'DELETE', $username, 404, 'Package not found');
    }

    $sales = referenceCount($conn,
        "SELECT COUNT(DISTINCT transaction_id) FROM {$schema}.transaction_detail WHERE package_id = ?", $package_id);
    if ($sales > 0) {
        apiReject($conn, '/menu/package.php', 'DELETE', $username, 409,
            "Can't delete this package: it appears in $sales sale(s) and is needed for sales history.");
    }

    mysqli_begin_transaction($conn);
    try {
        $stmt = $conn->prepare("DELETE FROM {$schema}.package_menu WHERE package_id = ?");
        if (!$stmt) {
            throw new Exception('Database query error');
        }
        $stmt->bind_param('s', $package_id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to delete package menus');
        }
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM {$schema}.package WHERE package_id = ?");
        if (!$stmt) {
            throw new Exception('Database query error');
        }
        $stmt->bind_param('s', $package_id);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('Failed to delete package');
        }
        $stmt->close();

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/menu/package.php',
            'method'        => 'DELETE',
            'error_message' => $e->getMessage(),
            'user_identifier' => $username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Failed to delete package');
    }

    delete_menu_image_urls([$existing['image_url'], $existing['thumb_url']]);
    jsonResponse(200, 'Package deleted successfully');
}

$method         = $_SERVER['REQUEST_METHOD'];
$conn           = DB::conn();
$schema         = DB_SCHEMA;
$token_username = null;

$showAll = $method === 'GET' && in_array((string)($_GET['show_all'] ?? ''), ['1', 'true'], true);

if ($method !== 'GET' || $showAll) {
    $decoded = requireAuth();
    $token_username = $decoded->username;
    if (!isMenuManager($decoded)) {
        logApiError($conn, [
            'error_level'     => 'warning',
            'http_status'     => 403,
            'endpoint'        => '/menu/package.php',
            'method'          => $method,
            'error_message'   => 'Only the Owner can manage packages',
            'user_identifier' => $token_username,
            'company_id'      => null,
        ]);
        jsonResponse(403, 'Only the Owner can manage packages');
    }
    if ($method === 'POST' || $method === 'PUT') {
        rejectOversizedBody();
    }
}

try {
    switch ($method) {
        case 'GET':
            $package_id = $_GET['package_id'] ?? null;
            if ($package_id) {
                getDetailPackage($conn, $schema, $package_id);
            } else {
                $params = $_GET['params'] ?? null;
                $page   = $_GET['page']   ?? 1;
                $limit  = $_GET['limit']  ?? 20;
                $channel = in_array($_GET['channel'] ?? null, ['outlet', 'abang'], true) ? $_GET['channel'] : null;
                getAllPackages($conn, $schema, $params, $page, $limit, $showAll, $channel);
            }
            break;

        case 'POST':
            $input = !empty($_POST) ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);

            if (!empty($input['package_id'])) {
                updatePackage($conn, $schema, $input, $token_username);
            } else {
                createPackage($conn, $schema, $input, $token_username);
            }
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updatePackage($conn, $schema, $input, $token_username);
            break;

        case 'DELETE':
            $package_id = $_GET['package_id'] ?? null;
            deletePackage($conn, $schema, $package_id, $token_username);
            break;

        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/menu/package.php',
                'method'          => $method,
                'error_message'   => 'Method Not Allowed',
                'user_identifier' => $token_username,
                'company_id'      => null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (ApiException $e) {
    throw $e;
} catch (Exception $e) {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/menu/package.php',
        'method'          => $method ?? '',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $token_username,
        'company_id'      => null,
    ]);
    jsonResponse(500, 'Internal Server Error');
}
