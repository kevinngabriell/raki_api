<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once __DIR__ . '/../account/account_rules.php';

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

function getRedemptions($conn, $schema, $user_id, $company_id) {
    $stmt = $conn->prepare("SELECT r.redemption_id, r.reward_id, c.reward_name, c.point_cost, c.image_url,
                r.points_used, r.status, r.redemption_code, r.claimed_at, r.expired_at,
                r.notes, r.created_at
         FROM {$schema}.reward_redemption r
         JOIN {$schema}.reward_catalog c ON c.reward_id = r.reward_id
         WHERE r.user_id = ? AND r.company_id = ?
         ORDER BY r.created_at DESC
         LIMIT 50");
    $stmt->bind_param('ss', $user_id, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    $data = [];
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }

    jsonResponse(200, 'Redemption history', $data);
}

function createRedemption($conn, $schema, $input, $user_id, $company_id, $username) {
    $reward_id  = $input['reward_id'] ?? null;
    if (!$reward_id) jsonResponse(400, 'reward_id is required');

    $today = date('Y-m-d');

    $stmt = $conn->prepare("SELECT * FROM {$schema}.reward_catalog
         WHERE reward_id = ?
           AND company_id = ?
           AND is_active = 1
           AND (valid_from IS NULL OR valid_from <= ?)
           AND (valid_until IS NULL OR valid_until >= ?)
         LIMIT 1");
    $stmt->bind_param('ssss', $reward_id, $company_id, $today, $today);
    $stmt->execute();
    $reward_result = $stmt->get_result();
    $stmt->close();

    if (!$reward_result || $reward_result->num_rows === 0) {
        jsonResponse(404, 'Reward not found or not available');
    }
    $reward = $reward_result->fetch_assoc();

    if ($reward['stock'] !== null && (int)$reward['stock'] <= 0) {
        jsonResponse(400, 'Reward is out of stock');
    }

    $point_cost = (int)$reward['point_cost'];

    // Serialize redemptions per member so the balance check can't race.
    $lock_name = 'redeem_' . md5($company_id . '|' . $user_id);
    $stmt = $conn->prepare("SELECT GET_LOCK(?, 5) AS got");
    $stmt->bind_param('s', $lock_name);
    $stmt->execute();
    $got_lock = (int)$stmt->get_result()->fetch_assoc()['got'];
    $stmt->close();
    if ($got_lock !== 1) jsonResponse(409, 'Another redemption is in progress, please retry');

    $release_lock = function () use ($conn, $lock_name) {
        $s = $conn->prepare("SELECT RELEASE_LOCK(?)");
        $s->bind_param('s', $lock_name);
        $s->execute();
        $s->close();
    };

    $redemption_code = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));

    $redemption_id = 'redeem' . uniqid();
    $ledger_id     = 'ledger' . uniqid();
    $now           = getCurrentDateTimeJakarta();
    $notes         = $input['notes'] ?? null;
    $status        = 'pending';
    $ref_type      = 'redemption';
    $ledger_notes  = 'Redeemed: ' . $reward['reward_name'];
    $negative_pts  = -$point_cost;

    mysqli_begin_transaction($conn);

    try {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(points), 0) AS balance
             FROM {$schema}.member_point_ledger
             WHERE user_id = ? AND company_id = ?");
        $stmt->bind_param('ss', $user_id, $company_id);
        $stmt->execute();
        $balance = (int)$stmt->get_result()->fetch_assoc()['balance'];
        $stmt->close();

        if ($balance < $point_cost) {
            mysqli_rollback($conn);
            $release_lock();
            jsonResponse(400, 'Insufficient points', [
                'balance'  => $balance,
                'required' => $point_cost,
            ]);
        }

        $stmt = $conn->prepare("INSERT INTO {$schema}.reward_redemption
                    (redemption_id, reward_id, user_id, company_id, points_used, status, redemption_code, notes, created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssissss', $redemption_id, $reward_id, $user_id, $company_id, $point_cost, $status, $redemption_code, $notes, $now);
        if (!$stmt->execute()) throw new Exception('Failed to create redemption');
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO {$schema}.member_point_ledger
                    (ledger_id, user_id, company_id, type, points, reference_type, reference_id, notes, created_by, created_at)
                VALUES
                    (?, ?, ?, 'redeem', ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('sssisssss', $ledger_id, $user_id, $company_id, $negative_pts, $ref_type, $redemption_id, $ledger_notes, $username, $now);
        if (!$stmt->execute()) throw new Exception('Failed to record point deduction');
        $stmt->close();

        if ($reward['stock'] !== null) {
            $stmt = $conn->prepare("UPDATE {$schema}.reward_catalog SET stock = stock - 1 WHERE reward_id = ? AND stock > 0");
            $stmt->bind_param('s', $reward_id);
            if (!$stmt->execute()) throw new Exception('Failed to update stock');
            $stock_updated = $stmt->affected_rows;
            $stmt->close();
            if ($stock_updated < 1) {
                mysqli_rollback($conn);
                $release_lock();
                jsonResponse(400, 'Reward is out of stock');
            }
        }

        mysqli_commit($conn);
        $release_lock();

    } catch (Exception $e) {
        mysqli_rollback($conn);
        $release_lock();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/reward/redeem.php',
            'method'        => 'POST',
            'error_message' => $e->getMessage(),
        ]);
        jsonResponse(500, 'Redemption failed');
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

    mysqli_begin_transaction($conn);

    $stmt = $conn->prepare("SELECT * FROM {$schema}.reward_redemption WHERE redemption_id = ? AND company_id = ? LIMIT 1 FOR UPDATE");
    $stmt->bind_param('ss', $redemption_id, $company_id);
    $stmt->execute();
    $check = $stmt->get_result();
    $stmt->close();

    if ($check->num_rows === 0) {
        mysqli_rollback($conn);
        jsonResponse(404, 'Redemption not found');
    }

    $redemption = $check->fetch_assoc();

    $transitions = [
        'pending'  => ['approved', 'rejected', 'expired'],
        'approved' => ['claimed', 'expired'],
    ];
    if (!in_array($status, $transitions[$redemption['status']] ?? [], true)) {
        mysqli_rollback($conn);
        jsonResponse(409, "Cannot change status from '{$redemption['status']}' to '{$status}'");
    }
    $now        = getCurrentDateTimeJakarta();

    $fields = [];
    $types  = '';
    $values = [];

    $fields[] = "status = ?";
    $types .= 's';
    $values[] = $status;

    if ($status === 'claimed') {
        $actor_user_id = resolveUserId($conn, $actor_username) ?? $actor_username;
        $fields[] = "claimed_at = ?";
        $types .= 's';
        $values[] = $now;
        $fields[] = "claimed_by = ?";
        $types .= 's';
        $values[] = $actor_user_id;
    }

    if ($status === 'expired') {
        $fields[] = "expired_at = ?";
        $types .= 's';
        $values[] = $now;
    }

    if ($status === 'rejected' && $redemption['status'] === 'pending') {
        $ledger_id    = 'ledger' . uniqid();
        $refund_pts   = (int)$redemption['points_used'];
        $user_id      = $redemption['user_id'];
        $ledger_type  = 'adjustment';
        $ref_type     = 'redemption';
        $refund_notes = 'Refund: rejected redemption';

        $stmt = $conn->prepare("INSERT INTO {$schema}.member_point_ledger
                    (ledger_id, user_id, company_id, type, points, reference_type, reference_id, notes, created_by, created_at)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssssisssss', $ledger_id, $user_id, $company_id, $ledger_type, $refund_pts, $ref_type, $redemption_id, $refund_notes, $actor_username, $now);
        if (!$stmt->execute()) {
            mysqli_rollback($conn);
            jsonResponse(500, 'Failed to refund points');
        }
        $stmt->close();

        $reward_id = $redemption['reward_id'];
        $stmt = $conn->prepare("UPDATE {$schema}.reward_catalog SET stock = stock + 1 WHERE reward_id = ? AND stock IS NOT NULL");
        $stmt->bind_param('s', $reward_id);
        $stmt->execute();
        $stmt->close();
    }

    $fields[] = "updated_at = ?";
    $types .= 's';
    $values[] = $now;

    $setClause = implode(', ', $fields);
    $types .= 's';
    $values[] = $redemption_id;

    $stmt = $conn->prepare("UPDATE {$schema}.reward_redemption SET {$setClause} WHERE redemption_id = ?");
    $stmt->bind_param($types, ...$values);

    if (!$stmt->execute()) {
        mysqli_rollback($conn);
        jsonResponse(500, 'Failed to update redemption');
    }
    $stmt->close();
    mysqli_commit($conn);

    jsonResponse(200, 'Redemption updated successfully', [
        'redemption_id' => $redemption_id,
        'status'        => $status,
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

    if ($method === 'PUT') {
        $roleName = accountRoleName($conn, $decoded->role ?? null);
        if (!accountIsManagerRole($roleName)) {
            jsonResponse(403, 'Only Owner/Mitra/Franchise can update redemption status');
        }
    }

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
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/reward/redeem.php',
        'method'          => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message'   => $e->getMessage(),
    ]);
    jsonResponse(500, 'Internal Server Error');
}

?>
