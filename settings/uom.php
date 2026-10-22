<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function createUOM($conn, $schema, $input, $token_username) {
    if (!isset($input['uom_name'])) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/settings/uom.php',
            'method'        => 'POST',
            'error_message' => 'Missing required fields (uom_name)',
            'user_identifier' => $token_username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'Missing required fields (uom_name)');
    }

    $uom_name = $input['uom_name'];
    $now = getCurrentDateTimeJakarta();

    $stmt = $conn->prepare("SELECT uom_id FROM {$schema}.unit_of_measurement WHERE uom_name = ?");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/settings/uom.php',
            'method'        => 'POST',
            'error_message' => 'Database query error',
            'user_identifier' => $token_username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $uom_name);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $stmt->close();
        jsonResponse(400, 'Unit of measurement already exists in database');
    }
    $stmt->close();

    $uomID = "uom" . uniqid();

    $stmt = $conn->prepare("INSERT INTO {$schema}.unit_of_measurement (uom_id, uom_name, created_by, created_at) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/settings/uom.php',
            'method'        => 'POST',
            'error_message' => 'Database query error',
            'user_identifier' => $token_username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Database query error');
    }

    $stmt->bind_param('ssss', $uomID, $uom_name, $token_username, $now);

    if ($stmt->execute()) {
        $stmt->close();
        jsonResponse(201, 'New unit of measurement has been created successfully', ['uom_name' => $uom_name]);
    } else {
        $stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/settings/uom.php',
            'method'        => 'POST',
            'error_message' => 'Failed to create a new unit of measurement',
            'user_identifier' => $token_username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Failed to create a new unit of measurement');
    }
}

function getAllUOM($conn, $schema, $params, $page = 1, $limit = 10) {
    $page = (int)$page;
    $limit = pageLimit($limit);
    $offset = ($page - 1) * $limit;
    $search = '%' . ($params ?? '') . '%';

    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM {$schema}.unit_of_measurement WHERE uom_name LIKE ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $search);
    $stmt->execute();
    $result = $stmt->get_result();
    $totalRow = $result->fetch_assoc();
    $total = (int)$totalRow['total'];
    $stmt->close();

    $query = "SELECT uom_id, uom_name FROM {$schema}.unit_of_measurement WHERE uom_name LIKE ? LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('sii', $search, $limit, $offset);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $response = [
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => (int)ceil($total / $limit),
            ]
        ];
        jsonResponse(200, 'Unit of measurement found', $response);
    } else {
        $stmt->close();
        jsonResponse(404, 'Unit of measurement not found');
    }
}

function getDetailUOM($conn, $schema, $uom_id) {
    $stmt = $conn->prepare("SELECT * FROM {$schema}.unit_of_measurement WHERE uom_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $uom_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $response = [
            'data' => $data,
            'pagination' => [
                'total' => 1,
                'page' => 1,
                'limit' => 1,
                'total_pages' => 1,
            ]
        ];
        jsonResponse(200, 'Unit of measurement found', $response);
    } else {
        $stmt->close();
        jsonResponse(404, 'Unit of measurement not found');
    }
}

function updateUOM($conn, $schema, $input, $token_username) {
    if (!isset($input['uom_id']) || !isset($input['uom_name'])) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/settings/uom.php',
            'method'        => 'PUT',
            'error_message' => 'Missing required fields (uom_id and uom_name)',
            'user_identifier' => $token_username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'Missing required fields (uom_id and uom_name)');
    }

    $uom_id = $input['uom_id'];
    $uom_name = $input['uom_name'];

    $stmt = $conn->prepare("SELECT uom_id FROM {$schema}.unit_of_measurement WHERE uom_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $uom_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $stmt->close();

        $stmt = $conn->prepare("UPDATE {$schema}.unit_of_measurement SET uom_name = ? WHERE uom_id = ?");
        if (!$stmt) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/settings/uom.php',
                'method'        => 'PUT',
                'error_message' => 'Database query error',
                'user_identifier' => $token_username ?? null,
                'company_id'      => null,
            ]);
            jsonResponse(500, 'Database query error');
        }
        $stmt->bind_param('ss', $uom_name, $uom_id);

        if ($stmt->execute()) {
            $stmt->close();
            jsonResponse(200, 'Unit of measurement updated successfully', ['uom_id' => $uom_id]);
        } else {
            $stmt->close();
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/settings/uom.php',
                'method'        => 'PUT',
                'error_message' => 'Failed to update unit of measurement',
                'user_identifier' => $token_username ?? null,
                'company_id'      => null,
            ]);
            jsonResponse(500, 'Failed to update unit of measurement');
        }
    } else {
        $stmt->close();
        jsonResponse(404, 'Unit of measurement is not registered in systems');
    }
}

function deleteUOM($conn, $schema, $uom_id, $token_username) {
    if ($uom_id === null || $uom_id === '') {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/settings/uom.php',
            'method'        => 'DELETE',
            'error_message' => 'Missing required fields (uom_id)',
            'user_identifier' => $token_username ?? null,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'Missing required fields (uom_id)');
    }

    $stmt = $conn->prepare("SELECT uom_id FROM {$schema}.unit_of_measurement WHERE uom_id = ?");
    if (!$stmt) {
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $uom_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM {$schema}.unit_of_measurement WHERE uom_id = ?");
        if (!$stmt) {
            jsonResponse(500, 'Database query error');
        }
        $stmt->bind_param('s', $uom_id);

        if ($stmt->execute()) {
            $stmt->close();
            jsonResponse(200, 'Unit of measurement deleted successfully');
        } else {
            $stmt->close();
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/settings/uom.php',
                'method'        => 'DELETE',
                'error_message' => 'Failed to delete unit of measurement',
                'user_identifier' => $token_username ?? null,
                'company_id'      => null,
            ]);
            jsonResponse(500, 'Failed to delete unit of measurement');
        }
    } else {
        $stmt->close();
        jsonResponse(404, 'Unit of measurement is not registered in systems');
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$conn = DB::conn();
$schema = DB_SCHEMA;

$token_username = null;

if ($method !== 'GET') {
    $decoded = requireAuth();
    $token_username = $decoded->username;
}

try {
    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            createUOM($conn, $schema, $input, $token_username);
            break;
        case 'GET':
            $params = $_GET['params'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $uom_id = $_GET['uom_id'] ?? null;
            if ($uom_id != null) {
                getDetailUOM($conn, $schema, $uom_id);
            } else {
                getAllUOM($conn, $schema, $params, $page, $limit);
            }
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updateUOM($conn, $schema, $input, $token_username);
            break;
        case 'DELETE':
            $uom_id = $_GET['uom_id'] ?? null;
            deleteUOM($conn, $schema, $uom_id, $token_username);
            break;
    }

} catch (Exception $e) {
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/settings/uom.php',
        'method'        => $method ?? '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $token_username ?? null,
        'company_id'      => null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
