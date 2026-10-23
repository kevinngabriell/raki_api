<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function resolveUserId($conn, $username) {
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $stmt = $conn->prepare("SELECT user_id FROM {$coreSchema}.app_user WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    if (!$result || $result->num_rows === 0) return null;
    return $result->fetch_assoc()['user_id'];
}

function getUserPoints($conn, $schema, $user_id, $company_id) {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(points), 0) as balance
         FROM {$schema}.member_point_ledger
         WHERE user_id = ? AND company_id = ?");
    $stmt->bind_param('ss', $user_id, $company_id);
    $stmt->execute();
    $balance = (int)$stmt->get_result()->fetch_assoc()['balance'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT ledger_id, type, points, reference_type, reference_id, notes, expired_at, created_at
         FROM {$schema}.member_point_ledger
         WHERE user_id = ? AND company_id = ?
         ORDER BY created_at DESC
         LIMIT 50");
    $stmt->bind_param('ss', $user_id, $company_id);
    $stmt->execute();
    $ledger_result = $stmt->get_result();
    $stmt->close();

    $ledger = [];
    while ($row = $ledger_result->fetch_assoc()) {
        $ledger[] = $row;
    }

    jsonResponse(200, 'User points', [
        'user_id'    => $user_id,
        'company_id' => $company_id,
        'balance'    => $balance,
        'ledger'     => $ledger,
    ]);
}

function addPoints($conn, $schema, $input, $actor_username) {
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $target_user_id = $input['user_id']  ?? null;
    $company_id     = $input['company_id'] ?? null;
    $points         = isset($input['points']) ? (int)$input['points'] : null;
    $notes          = $input['notes'] ?? null;

    if (!$target_user_id || !$company_id || $points === null || !$notes) {
        jsonResponse(400, 'user_id, company_id, points, and notes are required');
    }

    if ($points === 0) {
        jsonResponse(400, 'points cannot be zero');
    }

    $stmt = $conn->prepare("SELECT user_id FROM {$coreSchema}.app_user WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('s', $target_user_id);
    $stmt->execute();
    $check = $stmt->get_result();
    $stmt->close();

    if ($check->num_rows === 0) {
        jsonResponse(404, 'User not found');
    }

    $ledger_id = 'ledger' . uniqid();
    $type      = 'adjustment';
    $now       = getCurrentDateTimeJakarta();
    $ref_type  = 'manual';

    $stmt = $conn->prepare("INSERT INTO {$schema}.member_point_ledger
               (ledger_id, user_id, company_id, type, points, reference_type, notes, created_by, created_at)
           VALUES
               (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('ssssissss', $ledger_id, $target_user_id, $company_id, $type, $points, $ref_type, $notes, $actor_username, $now);

    if (!$stmt->execute()) {
        jsonResponse(500, 'Failed to add points');
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT COALESCE(SUM(points), 0) as balance
         FROM {$schema}.member_point_ledger
         WHERE user_id = ? AND company_id = ?");
    $stmt->bind_param('ss', $target_user_id, $company_id);
    $stmt->execute();
    $new_balance = (int)$stmt->get_result()->fetch_assoc()['balance'];
    $stmt->close();

    jsonResponse(201, 'Points added successfully', [
        'ledger_id'   => $ledger_id,
        'user_id'     => $target_user_id,
        'points_added' => $points,
        'new_balance' => $new_balance,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $decoded = requireAuth();

    $conn           = DB::conn();
    $schema         = DB_SCHEMA;
    $token_username = $decoded->username;
    $token_company  = $decoded->company_id;
    $method         = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            $user_id = resolveUserId($conn, $token_username);
            if (!$user_id) jsonResponse(404, 'User not found');
            getUserPoints($conn, $schema, $user_id, $token_company);
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            addPoints($conn, $schema, $input, $token_username);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/reward/points.php',
        'method'          => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message'   => $e->getMessage(),
    ]);
    jsonResponse(500, 'Internal Server Error');
}

?>
