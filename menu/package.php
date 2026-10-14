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
    $menu_ids      = packageMenuIds($input['menu_ids'] ?? null) ?? [];

    if (!$package_name || empty($menu_ids) || !is_array($menu_ids)) {
        jsonResponse(400, 'package_name and menu_ids (array) are required');
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

    foreach ($menu_ids as $mid) {
        $stmt = $conn->prepare("SELECT menu_id FROM {$schema}.menu WHERE menu_id = ? AND is_active = 1");
        if (!$stmt) {
            jsonResponse(500, 'Database query error');
        }
        $stmt->bind_param('s', $mid);
        $stmt->execute();
        $chk = $stmt->get_result();
        if (!$chk || $chk->num_rows === 0) {
            $stmt->close();
            jsonResponse(404, "menu_id not found or inactive");
        }
        $stmt->close();
    }

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

function getAllPackages($conn, $schema, $params, $page = 1, $limit = 20) {
    $page = (int)$page;
    $limit = (int)$limit;
    $offset = ($page - 1) * $limit;
    $search = '%' . ($params ?? '') . '%';

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM {$schema}.package WHERE package_name LIKE ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $search);
    $stmt->execute();
    $countResult = $stmt->get_result();
    $total = (int)$countResult->fetch_assoc()['total'];
    $stmt->close();

    $query = "SELECT p.package_id, p.package_name, p.package_price, p.image_url, p.thumb_url,
                     p.created_by, p.created_at, p.updated_by, p.updated_at
              FROM {$schema}.package p
              WHERE p.package_name LIKE ?
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

    $packages = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($packages as &$pkg) {
        $stmt = $conn->prepare("SELECT m.menu_id, m.menu_name, m.price, cm.category_name, m.image_url, m.thumb_url
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
    $stmt = $conn->prepare("SELECT package_id, package_name, package_price, image_url, thumb_url, created_by, created_at, updated_by, updated_at FROM {$schema}.package WHERE package_id = ?");
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

    $package = $result->fetch_assoc();
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
    $menu_ids = packageMenuIds($input['menu_ids'] ?? null);
    if ($menu_ids !== null) {
        if (!is_array($menu_ids) || empty($menu_ids)) {
            apiReject($conn, '/menu/package.php', 'PUT', $username, 400, 'menu_ids must be a non-empty array');
        }
        $menu_ids = array_values(array_unique(array_map('strval', $menu_ids)));
        foreach ($menu_ids as $mid) {
            if (referenceCount($conn, "SELECT COUNT(*) FROM {$schema}.menu WHERE menu_id = ? AND is_active = 1", $mid) === 0) {
                apiReject($conn, '/menu/package.php', 'PUT', $username, 404, "menu_id not found or inactive: $mid");
            }
        }
    }

    [$image_url, $thumb_url] = packageImageUpload();
    if ($image_url !== null) {
        $fields[] = "image_url = ?";
        $types .= 's';
        $values[] = $image_url;
        $fields[] = "thumb_url = ?";
        $types .= 's';
        $values[] = $thumb_url;
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

    if ($image_url !== null) {
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

if ($method !== 'GET') {
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
                getAllPackages($conn, $schema, $params, $page, $limit);
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
