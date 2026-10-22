<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function getPointsHistory($conn, $schema, $username, $company_id, array $params) {
    $coreSchema = MOVIRA_CORE_SCHEMA;
    // 1. Resolve user_id
    $stmtUser = $conn->prepare("SELECT user_id FROM {$coreSchema}.app_user WHERE username = ? LIMIT 1");

    if (!$stmtUser) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/member/points-history.php',
            'method'          => 'GET',
            'error_message'   => 'Failed to prepare statement for user lookup',
            'user_identifier' => $username,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Failed to prepare statement');
    }

    $stmtUser->bind_param('s', $username);
    $stmtUser->execute();
    $userResult = $stmtUser->get_result();

    if (!$userResult || $userResult->num_rows === 0) {
        jsonResponse(404, 'User not found');
    }

    $user_id = $userResult->fetch_assoc()['user_id'];
    $stmtUser->close();

    // 2. Pagination
    $page   = isset($params['page'])  ? max(1, (int)$params['page'])  : 1;
    $limit  = pageLimit($params['limit'] ?? null, 15);
    $offset = ($page - 1) * $limit;
    $filterType = !empty($params['type']) ? $params['type'] : null;

    // 3. Count query
    $sqlCount = "SELECT COUNT(*) AS total FROM {$schema}.member_point_ledger WHERE user_id = ? AND company_id = ?";
    $bindTypes = 'ss';
    $bindVals = [$user_id, $company_id];
    if ($filterType) {
        $sqlCount .= " AND type = ?";
        $bindTypes .= 's';
        $bindVals[] = $filterType;
    }

    $stmtCount = $conn->prepare($sqlCount);
    if (!$stmtCount) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/member/points-history.php',
            'method'          => 'GET',
            'error_message'   => 'Failed to prepare statement for count',
            'user_identifier' => $username,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Failed to prepare statement');
    }
    $stmtCount->bind_param($bindTypes, ...$bindVals);
    $stmtCount->execute();
    $total = (int) $stmtCount->get_result()->fetch_assoc()['total'];
    $stmtCount->close();

    // 4. Data query
    $sqlData = "SELECT ledger_id AS id,
                notes       AS description,
                points,
                type,
                created_at
         FROM {$schema}.member_point_ledger
         WHERE user_id = ? AND company_id = ?";
    $bindTypes2 = 'ss';
    $bindVals2 = [$user_id, $company_id];
    if ($filterType) {
        $sqlData .= " AND type = ?";
        $bindTypes2 .= 's';
        $bindVals2[] = $filterType;
    }
    $sqlData .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $bindTypes2 .= 'ii';
    $bindVals2[] = $limit;
    $bindVals2[] = $offset;

    $stmtData = $conn->prepare($sqlData);
    if (!$stmtData) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/member/points-history.php',
            'method'          => 'GET',
            'error_message'   => 'Failed to prepare statement for data query',
            'user_identifier' => $username,
            'company_id'      => $company_id,
        ]);
        jsonResponse(500, 'Failed to prepare statement');
    }
    $stmtData->bind_param($bindTypes2, ...$bindVals2);
    $stmtData->execute();
    $dataResult = $stmtData->get_result();

    $data = [];
    while ($row = $dataResult->fetch_assoc()) {
        $row['points'] = (int) $row['points'];
        $data[] = $row;
    }
    $stmtData->close();

    jsonResponse(200, 'Points history', [
        'data' => $data,
        'pagination' => [
            'current_page' => $page,
            'limit'        => $limit,
            'total_data'   => $total,
            'total_page'   => (int) ceil($total / $limit),
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $decoded = requireAuth();

    $conn   = DB::conn();
    $schema = DB_SCHEMA;
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            getPointsHistory($conn, $schema, $decoded->username, $decoded->company_id, $_GET);
            break;
        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/member/points-history.php',
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
        'endpoint'        => '/member/points-history.php',
        'method'          => '',
        'error_message'   => $e->getMessage(),
        'user_identifier' => null,
        'company_id'      => null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
