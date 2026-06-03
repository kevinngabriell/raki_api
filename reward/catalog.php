<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function getCatalog($conn, $schema, $company_id, $params) {
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $today      = date('Y-m-d');

    $search = '';
    if (!empty($params['search'])) {
        $kw     = mysqli_real_escape_string($conn, $params['search']);
        $search = " AND (reward_name LIKE '%$kw%' OR description LIKE '%$kw%')";
    }

    $page   = isset($params['page'])  ? max(1, (int)$params['page'])  : 1;
    $limit  = isset($params['limit']) ? max(1, (int)$params['limit']) : 10;
    $offset = ($page - 1) * $limit;

    $base = "FROM {$schema}.reward_catalog
             WHERE company_id = '$company_id'
               AND is_active = 1
               AND (valid_from IS NULL OR valid_from <= '$today')
               AND (valid_until IS NULL OR valid_until >= '$today')
               $search";

    $count_res   = mysqli_query($conn, "SELECT COUNT(*) as total $base");
    $total_data  = (int)mysqli_fetch_assoc($count_res)['total'];

    $result = mysqli_query($conn, "SELECT * $base ORDER BY created_at DESC LIMIT $limit OFFSET $offset");

    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }

    jsonResponse(200, 'Reward catalog', [
        'data' => $data,
        'pagination' => [
            'current_page' => $page,
            'limit'        => $limit,
            'total_data'   => $total_data,
            'total_page'   => (int)ceil($total_data / $limit),
        ],
    ]);
}

function getCatalogDetail($conn, $schema, $reward_id, $company_id) {
    $reward_id  = mysqli_real_escape_string($conn, $reward_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $result = mysqli_query($conn,
        "SELECT * FROM {$schema}.reward_catalog
         WHERE reward_id = '$reward_id' AND company_id = '$company_id'
         LIMIT 1"
    );

    if (!$result || mysqli_num_rows($result) === 0) {
        jsonResponse(404, 'Reward not found');
    }

    jsonResponse(200, 'Reward detail', mysqli_fetch_assoc($result));
}

function createCatalog($conn, $schema, $input, $username, $company_id) {
    $required = ['reward_name', 'point_cost'];
    foreach ($required as $f) {
        if (!isset($input[$f]) || $input[$f] === '') {
            jsonResponse(400, "$f is required");
        }
    }

    $reward_name = mysqli_real_escape_string($conn, trim($input['reward_name']));
    $description = isset($input['description']) ? mysqli_real_escape_string($conn, $input['description']) : null;
    $point_cost  = (int)$input['point_cost'];
    $image_url   = isset($input['image_url'])  ? mysqli_real_escape_string($conn, $input['image_url'])  : null;
    $stock       = isset($input['stock'])      ? (int)$input['stock'] : null;
    $is_active   = isset($input['is_active'])  ? (int)$input['is_active'] : 1;
    $valid_from  = isset($input['valid_from'])  && $input['valid_from']  !== '' ? mysqli_real_escape_string($conn, $input['valid_from'])  : null;
    $valid_until = isset($input['valid_until']) && $input['valid_until'] !== '' ? mysqli_real_escape_string($conn, $input['valid_until']) : null;
    $company_id  = mysqli_real_escape_string($conn, $company_id);
    $username    = mysqli_real_escape_string($conn, $username);

    if ($point_cost <= 0) jsonResponse(400, 'point_cost must be greater than 0');
    if (!in_array($is_active, [0, 1])) jsonResponse(400, 'is_active must be 0 or 1');

    $reward_id = 'reward' . uniqid();
    $now       = getCurrentDateTimeJakarta();

    $desc_sql  = $description  !== null ? "'$description'"  : 'NULL';
    $img_sql   = $image_url    !== null ? "'$image_url'"    : 'NULL';
    $stock_sql = $stock        !== null ? $stock            : 'NULL';
    $vf_sql    = $valid_from   !== null ? "'$valid_from'"   : 'NULL';
    $vu_sql    = $valid_until  !== null ? "'$valid_until'"  : 'NULL';

    $insert = "INSERT INTO {$schema}.reward_catalog
                   (reward_id, company_id, reward_name, description, point_cost, image_url, stock, is_active, valid_from, valid_until, created_by, created_at)
               VALUES
                   ('$reward_id', '$company_id', '$reward_name', $desc_sql, $point_cost, $img_sql, $stock_sql, $is_active, $vf_sql, $vu_sql, '$username', '$now')";

    if (!mysqli_query($conn, $insert)) {
        jsonResponse(500, 'Failed to create reward: ' . mysqli_error($conn));
    }

    jsonResponse(201, 'Reward created successfully', ['reward_id' => $reward_id]);
}

function updateCatalog($conn, $schema, $input, $username, $company_id) {
    $reward_id  = $input['reward_id'] ?? null;
    if (!$reward_id) jsonResponse(400, 'reward_id is required');

    $reward_id  = mysqli_real_escape_string($conn, $reward_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn,
        "SELECT reward_id FROM {$schema}.reward_catalog
         WHERE reward_id = '$reward_id' AND company_id = '$company_id' LIMIT 1"
    );
    if (!$check || mysqli_num_rows($check) === 0) jsonResponse(404, 'Reward not found');

    $fields = [];
    if (isset($input['reward_name'])) $fields[] = "reward_name = '" . mysqli_real_escape_string($conn, $input['reward_name']) . "'";
    if (isset($input['description'])) $fields[] = "description = '" . mysqli_real_escape_string($conn, $input['description']) . "'";
    if (isset($input['point_cost']))  {
        $pc = (int)$input['point_cost'];
        if ($pc <= 0) jsonResponse(400, 'point_cost must be greater than 0');
        $fields[] = "point_cost = $pc";
    }
    if (isset($input['image_url']))   $fields[] = "image_url = '" . mysqli_real_escape_string($conn, $input['image_url']) . "'";
    if (array_key_exists('stock', $input)) {
        $fields[] = $input['stock'] !== null ? "stock = " . (int)$input['stock'] : "stock = NULL";
    }
    if (isset($input['is_active'])) {
        $ia = (int)$input['is_active'];
        if (!in_array($ia, [0, 1])) jsonResponse(400, 'is_active must be 0 or 1');
        $fields[] = "is_active = $ia";
    }
    if (isset($input['valid_from']))  $fields[] = $input['valid_from']  !== '' ? "valid_from = '"  . mysqli_real_escape_string($conn, $input['valid_from'])  . "'" : "valid_from = NULL";
    if (isset($input['valid_until'])) $fields[] = $input['valid_until'] !== '' ? "valid_until = '" . mysqli_real_escape_string($conn, $input['valid_until']) . "'" : "valid_until = NULL";

    if (empty($fields)) jsonResponse(400, 'No fields provided for update');

    $fields[] = "updated_by = '" . mysqli_real_escape_string($conn, $username) . "'";

    $update = "UPDATE {$schema}.reward_catalog SET " . implode(', ', $fields) . " WHERE reward_id = '$reward_id' AND company_id = '$company_id'";
    if (!mysqli_query($conn, $update)) {
        jsonResponse(500, 'Failed to update reward: ' . mysqli_error($conn));
    }

    jsonResponse(200, 'Reward updated successfully');
}

function deleteCatalog($conn, $schema, $reward_id, $company_id) {
    if (!$reward_id) jsonResponse(400, 'reward_id is required');

    $reward_id  = mysqli_real_escape_string($conn, $reward_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $check = mysqli_query($conn,
        "SELECT reward_id FROM {$schema}.reward_catalog
         WHERE reward_id = '$reward_id' AND company_id = '$company_id' LIMIT 1"
    );
    if (!$check || mysqli_num_rows($check) === 0) jsonResponse(404, 'Reward not found');

    if (!mysqli_query($conn, "DELETE FROM {$schema}.reward_catalog WHERE reward_id = '$reward_id' AND company_id = '$company_id'")) {
        jsonResponse(500, 'Failed to delete reward: ' . mysqli_error($conn));
    }

    jsonResponse(200, 'Reward deleted successfully');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ── Public GET (no auth required) ────────────────────────────────────────────
if ($method === 'GET') {
    $conn       = DB::conn();
    $schema     = DB_SCHEMA;
    $company_id = $_GET['company_id'] ?? null;
    $reward_id  = $_GET['reward_id']  ?? null;

    if (!$company_id) jsonResponse(400, 'company_id is required');

    if ($reward_id) {
        getCatalogDetail($conn, $schema, $reward_id, $company_id);
    } else {
        getCatalog($conn, $schema, $company_id, $_GET);
    }
    exit;
}

// ── Protected: POST, PUT, DELETE ─────────────────────────────────────────────
$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 401,
        'endpoint'        => '/reward/catalog.php',
        'method'          => $method,
        'error_message'   => 'Authorization header not found',
        'user_identifier' => null,
        'company_id'      => null,
    ]);
    jsonResponse(401, 'Authorization header not found');
}

try {
    $token   = str_replace('Bearer ', '', $headers['Authorization']);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn           = DB::conn();
    $schema         = DB_SCHEMA;
    $token_username = $decoded->username;
    $token_company  = $decoded->company_id;

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            createCatalog($conn, $schema, $input, $token_username, $token_company);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateCatalog($conn, $schema, $input, $token_username, $token_company);
            break;
        case 'DELETE':
            $reward_id = $_GET['reward_id'] ?? null;
            deleteCatalog($conn, $schema, $reward_id, $token_company);
            break;
        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/reward/catalog.php',
                'method'          => $method,
                'error_message'   => 'Method Not Allowed',
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/reward/catalog.php',
        'method'          => $method,
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>
