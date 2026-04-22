<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, Content-Type");
    header("Access-Control-Allow-Credentials: true");
    http_response_code(204);
    exit();
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ─────────────────────────────────────────────
// CREATE
// ─────────────────────────────────────────────
function createPackage($conn, $schema, $input, $username) {
    $package_name  = isset($input['package_name'])  ? mysqli_real_escape_string($conn, trim($input['package_name']))  : null;
    $package_price = isset($input['package_price']) ? (int)$input['package_price'] : null;
    $menu_ids      = $input['menu_ids'] ?? [];   // array of menu_id

    if (!$package_name || empty($menu_ids) || !is_array($menu_ids)) {
        jsonResponse(400, 'package_name and menu_ids (array) are required');
    }

    // cek duplikat nama
    $dupCheck = mysqli_query($conn, "SELECT package_id FROM {$schema}.package WHERE package_name = '$package_name'");
    if (mysqli_num_rows($dupCheck) > 0) {
        jsonResponse(400, 'Package name already exists');
    }

    // validasi semua menu_id ada di tabel menu
    foreach ($menu_ids as $mid) {
        $mid = mysqli_real_escape_string($conn, $mid);
        $chk = mysqli_query($conn, "SELECT menu_id FROM {$schema}.menu WHERE menu_id = '$mid' AND is_active = 1");
        if (!$chk || mysqli_num_rows($chk) === 0) {
            jsonResponse(404, "menu_id '$mid' not found or inactive");
        }
    }

    $package_id    = 'pkg' . uniqid();
    $now           = getCurrentDateTimeJakarta();
    $price_sql     = ($package_price !== null) ? $package_price : 'NULL';

    mysqli_begin_transaction($conn);
    try {
        $insertPkg = "INSERT INTO {$schema}.package (package_id, package_name, package_price, created_by, created_at)
                      VALUES ('$package_id', '$package_name', $price_sql, '$username', '$now')";
        if (!mysqli_query($conn, $insertPkg)) {
            throw new Exception('Failed to insert package: ' . mysqli_error($conn));
        }

        foreach ($menu_ids as $mid) {
            $mid   = mysqli_real_escape_string($conn, $mid);
            $pmId  = 'pkgmenu' . uniqid();
            $insertPm = "INSERT INTO {$schema}.package_menu (package_menu_id, package_id, menu_id, created_at)
                         VALUES ('$pmId', '$package_id', '$mid', '$now')";
            if (!mysqli_query($conn, $insertPm)) {
                throw new Exception('Failed to insert package_menu: ' . mysqli_error($conn));
            }
        }

        mysqli_commit($conn);
        jsonResponse(201, 'Package created successfully', ['package_id' => $package_id, 'package_name' => $package_name]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        jsonResponse(500, $e->getMessage());
    }
}

// ─────────────────────────────────────────────
// GET ALL
// ─────────────────────────────────────────────
function getAllPackages($conn, $schema, $params, $page = 1, $limit = 20) {
    $offset = ($page - 1) * $limit;
    $params = mysqli_real_escape_string($conn, $params ?? '');

    $countResult = mysqli_query($conn, "SELECT COUNT(*) as total FROM {$schema}.package WHERE package_name LIKE '%$params%'");
    $total       = (int)mysqli_fetch_assoc($countResult)['total'];

    $query = "SELECT p.package_id, p.package_name, p.package_price,
                     p.created_by, p.created_at, p.updated_by, p.updated_at
              FROM {$schema}.package p
              WHERE p.package_name LIKE '%$params%'
              ORDER BY p.created_at DESC
              LIMIT $limit OFFSET $offset";

    $result = mysqli_query($conn, $query);

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'No packages found');
    }

    $packages = mysqli_fetch_all($result, MYSQLI_ASSOC);

    // attach menus for each package
    foreach ($packages as &$pkg) {
        $pid      = mysqli_real_escape_string($conn, $pkg['package_id']);
        $menuRes  = mysqli_query($conn,
            "SELECT m.menu_id, m.menu_name, m.price, cm.category_name, m.image_url, m.thumb_url
             FROM {$schema}.package_menu pm
             JOIN {$schema}.menu m  ON pm.menu_id = m.menu_id
             LEFT JOIN {$schema}.category_menu cm ON m.category_id = cm.category_id
             WHERE pm.package_id = '$pid'"
        );
        $pkg['menus'] = $menuRes ? mysqli_fetch_all($menuRes, MYSQLI_ASSOC) : [];
    }
    unset($pkg);

    jsonResponse(200, 'Packages found', [
        'data' => $packages,
        'pagination' => [
            'total'       => $total,
            'page'        => (int)$page,
            'limit'       => (int)$limit,
            'total_pages' => (int)ceil($total / $limit),
        ]
    ]);
}

// ─────────────────────────────────────────────
// GET DETAIL
// ─────────────────────────────────────────────
function getDetailPackage($conn, $schema, $package_id) {
    $package_id = mysqli_real_escape_string($conn, $package_id);

    $result = mysqli_query($conn,
        "SELECT package_id, package_name, package_price, created_by, created_at, updated_by, updated_at
         FROM {$schema}.package WHERE package_id = '$package_id'"
    );

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Package not found');
    }

    $package = mysqli_fetch_assoc($result);

    $menuRes = mysqli_query($conn,
        "SELECT m.menu_id, m.menu_name, m.price, cm.category_name, m.image_url, m.thumb_url
         FROM {$schema}.package_menu pm
         JOIN {$schema}.menu m  ON pm.menu_id = m.menu_id
         LEFT JOIN {$schema}.category_menu cm ON m.category_id = cm.category_id
         WHERE pm.package_id = '$package_id'"
    );
    $package['menus'] = $menuRes ? mysqli_fetch_all($menuRes, MYSQLI_ASSOC) : [];

    jsonResponse(200, 'Package found', $package);
}

// ─────────────────────────────────────────────
// UPDATE
// ─────────────────────────────────────────────
function updatePackage($conn, $schema, $input, $username) {
    $package_id = isset($input['package_id']) ? mysqli_real_escape_string($conn, $input['package_id']) : null;

    if (!$package_id) {
        jsonResponse(400, 'package_id is required');
    }

    $check = mysqli_query($conn, "SELECT package_id FROM {$schema}.package WHERE package_id = '$package_id'");
    if (!$check || mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Package not found');
    }

    $fields = [];
    $now    = getCurrentDateTimeJakarta();

    if (isset($input['package_name'])) {
        $name     = mysqli_real_escape_string($conn, trim($input['package_name']));
        $fields[] = "package_name = '$name'";
    }
    if (isset($input['package_price'])) {
        $price    = (int)$input['package_price'];
        $fields[] = "package_price = $price";
    }

    $menu_ids = $input['menu_ids'] ?? null;

    if (empty($fields) && $menu_ids === null) {
        jsonResponse(400, 'No fields provided for update');
    }

    mysqli_begin_transaction($conn);
    try {
        if (!empty($fields)) {
            $fields[] = "updated_by = '$username'";
            $fields[] = "updated_at = '$now'";
            $updateQuery = "UPDATE {$schema}.package SET " . implode(', ', $fields) . " WHERE package_id = '$package_id'";
            if (!mysqli_query($conn, $updateQuery)) {
                throw new Exception('Failed to update package: ' . mysqli_error($conn));
            }
        }

        if ($menu_ids !== null) {
            if (!is_array($menu_ids) || empty($menu_ids)) {
                throw new Exception('menu_ids must be a non-empty array');
            }

            // validasi menu_ids
            foreach ($menu_ids as $mid) {
                $mid = mysqli_real_escape_string($conn, $mid);
                $chk = mysqli_query($conn, "SELECT menu_id FROM {$schema}.menu WHERE menu_id = '$mid' AND is_active = 1");
                if (!$chk || mysqli_num_rows($chk) === 0) {
                    throw new Exception("menu_id '$mid' not found or inactive");
                }
            }

            // hapus lama, insert baru
            if (!mysqli_query($conn, "DELETE FROM {$schema}.package_menu WHERE package_id = '$package_id'")) {
                throw new Exception('Failed to remove old menus: ' . mysqli_error($conn));
            }

            foreach ($menu_ids as $mid) {
                $mid  = mysqli_real_escape_string($conn, $mid);
                $pmId = 'pkgmenu' . uniqid();
                $ins  = "INSERT INTO {$schema}.package_menu (package_menu_id, package_id, menu_id, created_at)
                         VALUES ('$pmId', '$package_id', '$mid', '$now')";
                if (!mysqli_query($conn, $ins)) {
                    throw new Exception('Failed to insert package_menu: ' . mysqli_error($conn));
                }
            }
        }

        mysqli_commit($conn);
        jsonResponse(200, 'Package updated successfully', ['package_id' => $package_id]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        jsonResponse(500, $e->getMessage());
    }
}

// ─────────────────────────────────────────────
// DELETE
// ─────────────────────────────────────────────
function deletePackage($conn, $schema, $package_id) {
    if (!$package_id) {
        jsonResponse(400, 'package_id is required');
    }

    $package_id = mysqli_real_escape_string($conn, $package_id);

    $check = mysqli_query($conn, "SELECT package_id FROM {$schema}.package WHERE package_id = '$package_id'");
    if (!$check || mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Package not found');
    }

    mysqli_begin_transaction($conn);
    try {
        if (!mysqli_query($conn, "DELETE FROM {$schema}.package_menu WHERE package_id = '$package_id'")) {
            throw new Exception('Failed to delete package menus: ' . mysqli_error($conn));
        }
        if (!mysqli_query($conn, "DELETE FROM {$schema}.package WHERE package_id = '$package_id'")) {
            throw new Exception('Failed to delete package: ' . mysqli_error($conn));
        }
        mysqli_commit($conn);
        jsonResponse(200, 'Package deleted successfully');
    } catch (Exception $e) {
        mysqli_rollback($conn);
        jsonResponse(500, $e->getMessage());
    }
}

// ─────────────────────────────────────────────
// ROUTING
// ─────────────────────────────────────────────
$method         = $_SERVER['REQUEST_METHOD'];
$conn           = DB::conn();
$schema         = DB_SCHEMA;
$token_username = null;

// ONLY REQUIRE TOKEN FOR NON-GET REQUESTS
if ($method !== 'GET') {
    $headers    = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);

    if (!$authHeader) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 401,
            'endpoint'        => '/menu/package.php',
            'method'          => $method,
            'error_message'   => 'Authorization header not found',
            'user_identifier' => null,
            'company_id'      => null,
        ]);
        jsonResponse(401, 'Authorization header not found');
    }

    try {
        $token          = preg_replace('/^Bearer\s+/i', '', $authHeader);
        $decoded        = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
        $token_username = $decoded->username;
    } catch (Exception $e) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 401,
            'endpoint'        => '/menu/package.php',
            'method'          => $method,
            'error_message'   => $e->getMessage(),
            'user_identifier' => null,
            'company_id'      => null,
        ]);
        jsonResponse(401, 'Invalid or expired token', ['error' => $e->getMessage()]);
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
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createPackage($conn, $schema, $input, $token_username);
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updatePackage($conn, $schema, $input, $token_username);
            break;

        case 'DELETE':
            $package_id = $_GET['package_id'] ?? null;
            deletePackage($conn, $schema, $package_id);
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
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
