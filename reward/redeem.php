<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function resolveUserId($conn, $username) {
    $username = mysqli_real_escape_string($conn, $username);
    $result   = mysqli_query($conn,
        "SELECT user_id FROM movira_core_dev.app_user WHERE username = '$username' LIMIT 1"
    );
    if (!$result || mysqli_num_rows($result) === 0) return null;
    return mysqli_fetch_assoc($result)['user_id'];
}

function getRedemptions($conn, $schema, $user_id, $company_id) {
    $user_id    = mysqli_real_escape_string($conn, $user_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $result = mysqli_query($conn,
        "SELECT r.redemption_id, r.reward_id, c.reward_name, c.point_cost, c.image_url,
                r.points_used, r.status, r.redemption_code, r.claimed_at, r.expired_at,
                r.notes, r.created_at
         FROM {$schema}.reward_redemption r
         JOIN {$schema}.reward_catalog c ON c.reward_id = r.reward_id
         WHERE r.user_id = '$user_id' AND r.company_id = '$company_id'
         ORDER BY r.created_at DESC
         LIMIT 50"
    );

    $data = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $data[] = $row;
    }

    jsonResponse(200, 'Redemption history', $data);
}

function createRedemption($conn, $schema, $input, $user_id, $company_id, $username) {
    $reward_id  = $input['reward_id'] ?? null;
    if (!$reward_id) jsonResponse(400, 'reward_id is required');

    $reward_id  = mysqli_real_escape_string($conn, $reward_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);
    $user_id    = mysqli_real_escape_string($conn, $user_id);
    $username   = mysqli_real_escape_string($conn, $username);
    $today      = date('Y-m-d');

    // check reward exists and is valid
    $reward_result = mysqli_query($conn,
        "SELECT * FROM {$schema}.reward_catalog
         WHERE reward_id = '$reward_id'
           AND company_id = '$company_id'
           AND is_active = 1
           AND (valid_from IS NULL OR valid_from <= '$today')
           AND (valid_until IS NULL OR valid_until >= '$today')
         LIMIT 1"
    );
    if (!$reward_result || mysqli_num_rows($reward_result) === 0) {
        jsonResponse(404, 'Reward not found or not available');
    }
    $reward = mysqli_fetch_assoc($reward_result);

    // check stock
    if ($reward['stock'] !== null && (int)$reward['stock'] <= 0) {
        jsonResponse(400, 'Reward is out of stock');
    }

    $point_cost = (int)$reward['point_cost'];

    // check user balance
    $bal_result = mysqli_query($conn,
        "SELECT COALESCE(SUM(points), 0) as balance
         FROM {$schema}.member_point_ledger
         WHERE user_id = '$user_id' AND company_id = '$company_id'"
    );
    $balance = (int)mysqli_fetch_assoc($bal_result)['balance'];

    if ($balance < $point_cost) {
        jsonResponse(400, 'Insufficient points', [
            'balance'    => $balance,
            'required'   => $point_cost,
        ]);
    }

    // generate short redemption code (8 alphanumeric chars)
    $redemption_code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));

    // ensure uniqueness
    $code_check = mysqli_query($conn,
        "SELECT redemption_id FROM {$schema}.reward_redemption WHERE redemption_code = '$redemption_code' LIMIT 1"
    );
    if ($code_check && mysqli_num_rows($code_check) > 0) {
        $redemption_code = strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
    }

    $redemption_id = 'redeem' . uniqid();
    $ledger_id     = 'ledger' . uniqid();
    $now           = getCurrentDateTimeJakarta();
    $notes         = isset($input['notes']) ? mysqli_real_escape_string($conn, $input['notes']) : null;
    $notes_sql     = $notes !== null ? "'$notes'" : 'NULL';

    // begin transaction
    mysqli_begin_transaction($conn);

    try {
        // insert redemption record
        $insert_redeem = "INSERT INTO {$schema}.reward_redemption
                              (redemption_id, reward_id, user_id, company_id, points_used, status, redemption_code, notes, created_at)
                          VALUES
                              ('$redemption_id', '$reward_id', '$user_id', '$company_id', $point_cost, 'pending', '$redemption_code', $notes_sql, '$now')";
        if (!mysqli_query($conn, $insert_redeem)) {
            throw new Exception('Failed to create redemption: ' . mysqli_error($conn));
        }

        // deduct points via ledger
        $insert_ledger = "INSERT INTO {$schema}.member_point_ledger
                              (ledger_id, user_id, company_id, type, points, reference_type, reference_id, notes, created_by, created_at)
                          VALUES
                              ('$ledger_id', '$user_id', '$company_id', 'redeem', " . (-$point_cost) . ", 'redemption', '$redemption_id', 'Redeemed: {$reward['reward_name']}', '$username', '$now')";
        if (!mysqli_query($conn, $insert_ledger)) {
            throw new Exception('Failed to record point deduction: ' . mysqli_error($conn));
        }

        // decrement stock if limited
        if ($reward['stock'] !== null) {
            if (!mysqli_query($conn, "UPDATE {$schema}.reward_catalog SET stock = stock - 1 WHERE reward_id = '$reward_id'")) {
                throw new Exception('Failed to update stock: ' . mysqli_error($conn));
            }
        }

        mysqli_commit($conn);

    } catch (Exception $e) {
        mysqli_rollback($conn);
        jsonResponse(500, 'Redemption failed: ' . $e->getMessage());
    }

    $new_balance = $balance - $point_cost;

    jsonResponse(201, 'Redemption created successfully', [
        'redemption_id'   => $redemption_id,
        'redemption_code' => $redemption_code,
        'reward_name'     => $reward['reward_name'],
        'points_used'     => $point_cost,
        'new_balance'     => $new_balance,
        'status'          => 'pending',
    ]);
}

function updateRedemption($conn, $schema, $input, $actor_username, $company_id) {
    $redemption_id = $input['redemption_id'] ?? null;
    $status        = $input['status']        ?? null;

    if (!$redemption_id || !$status) {
        jsonResponse(400, 'redemption_id and status are required');
    }

    $allowed_statuses = ['approved', 'rejected', 'claimed', 'expired'];
    if (!in_array($status, $allowed_statuses)) {
        jsonResponse(400, 'Invalid status. Allowed: ' . implode(', ', $allowed_statuses));
    }

    $redemption_id  = mysqli_real_escape_string($conn, $redemption_id);
    $company_id     = mysqli_real_escape_string($conn, $company_id);
    $actor_username = mysqli_real_escape_string($conn, $actor_username);

    $check = mysqli_query($conn,
        "SELECT * FROM {$schema}.reward_redemption
         WHERE redemption_id = '$redemption_id' AND company_id = '$company_id'
         LIMIT 1"
    );
    if (!$check || mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'Redemption not found');
    }

    $redemption = mysqli_fetch_assoc($check);
    $now        = getCurrentDateTimeJakarta();

    $fields = ["status = '$status'"];

    if ($status === 'claimed') {
        // resolve actor user_id for claimed_by
        $actor_result = mysqli_query($conn,
            "SELECT user_id FROM movira_core_dev.app_user WHERE username = '$actor_username' LIMIT 1"
        );
        $actor_user_id = $actor_result && mysqli_num_rows($actor_result) > 0
            ? mysqli_real_escape_string($conn, mysqli_fetch_assoc($actor_result)['user_id'])
            : $actor_username;

        $fields[] = "claimed_at = '$now'";
        $fields[] = "claimed_by = '$actor_user_id'";
    }

    if ($status === 'expired') {
        $fields[] = "expired_at = '$now'";
    }

    // if rejected → refund points
    if ($status === 'rejected' && $redemption['status'] === 'pending') {
        $ledger_id    = 'ledger' . uniqid();
        $refund_pts   = (int)$redemption['points_used'];
        $user_id      = mysqli_real_escape_string($conn, $redemption['user_id']);
        $insert_refund = "INSERT INTO {$schema}.member_point_ledger
                              (ledger_id, user_id, company_id, type, points, reference_type, reference_id, notes, created_by, created_at)
                          VALUES
                              ('$ledger_id', '$user_id', '$company_id', 'adjustment', $refund_pts, 'redemption', '$redemption_id', 'Refund: rejected redemption', '$actor_username', '$now')";
        if (!mysqli_query($conn, $insert_refund)) {
            jsonResponse(500, 'Failed to refund points: ' . mysqli_error($conn));
        }

        // restore stock if applicable
        $reward_id = mysqli_real_escape_string($conn, $redemption['reward_id']);
        mysqli_query($conn,
            "UPDATE {$schema}.reward_catalog
             SET stock = stock + 1
             WHERE reward_id = '$reward_id' AND stock IS NOT NULL"
        );
    }

    $update = "UPDATE {$schema}.reward_redemption SET " . implode(', ', $fields) . " WHERE redemption_id = '$redemption_id'";
    if (!mysqli_query($conn, $update)) {
        jsonResponse(500, 'Failed to update redemption: ' . mysqli_error($conn));
    }

    jsonResponse(200, 'Redemption updated successfully', [
        'redemption_id' => $redemption_id,
        'status'        => $status,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, PUT, OPTIONS");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
    http_response_code(200);
    exit();
}

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 401,
        'endpoint'        => '/reward/redeem.php',
        'method'          => $_SERVER['REQUEST_METHOD'],
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
    $method         = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            $user_id = resolveUserId($conn, $token_username);
            if (!$user_id) jsonResponse(404, 'User not found');
            getRedemptions($conn, $schema, $user_id, $token_company);
            break;

        case 'POST':
            $user_id = resolveUserId($conn, $token_username);
            if (!$user_id) jsonResponse(404, 'User not found');
            $input = json_decode(file_get_contents('php://input'), true);
            createRedemption($conn, $schema, $input, $user_id, $token_company, $token_username);
            break;

        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            updateRedemption($conn, $schema, $input, $token_username, $token_company);
            break;

        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/reward/redeem.php',
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
        'endpoint'        => '/reward/redeem.php',
        'method'          => $_SERVER['REQUEST_METHOD'],
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>
