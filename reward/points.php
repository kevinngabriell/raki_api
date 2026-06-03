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
    $result = mysqli_query($conn, "SELECT user_id FROM movira_core_dev.app_user WHERE username = '$username' LIMIT 1");
    if (!$result || mysqli_num_rows($result) === 0) return null;
    return mysqli_fetch_assoc($result)['user_id'];
}

function getUserPoints($conn, $schema, $user_id, $company_id) {
    $user_id    = mysqli_real_escape_string($conn, $user_id);
    $company_id = mysqli_real_escape_string($conn, $company_id);

    $balance_result = mysqli_query($conn,
        "SELECT COALESCE(SUM(points), 0) as balance
         FROM {$schema}.member_point_ledger
         WHERE user_id = '$user_id' AND company_id = '$company_id'"
    );
    $balance = (int)mysqli_fetch_assoc($balance_result)['balance'];

    $ledger_result = mysqli_query($conn,
        "SELECT ledger_id, type, points, reference_type, reference_id, notes, expired_at, created_at
         FROM {$schema}.member_point_ledger
         WHERE user_id = '$user_id' AND company_id = '$company_id'
         ORDER BY created_at DESC
         LIMIT 50"
    );

    $ledger = [];
    while ($row = mysqli_fetch_assoc($ledger_result)) {
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

    $target_user_id = mysqli_real_escape_string($conn, $target_user_id);
    $company_id     = mysqli_real_escape_string($conn, $company_id);
    $notes          = mysqli_real_escape_string($conn, $notes);
    $actor_username = mysqli_real_escape_string($conn, $actor_username);

    // verify user exists
    $check = mysqli_query($conn,
        "SELECT user_id FROM movira_core_dev.app_user WHERE user_id = '$target_user_id' LIMIT 1"
    );
    if (!$check || mysqli_num_rows($check) === 0) {
        jsonResponse(404, 'User not found');
    }

    $ledger_id = 'ledger' . uniqid();
    $type      = $points > 0 ? 'adjustment' : 'adjustment';
    $now       = getCurrentDateTimeJakarta();

    $insert = "INSERT INTO {$schema}.member_point_ledger
                   (ledger_id, user_id, company_id, type, points, reference_type, notes, created_by, created_at)
               VALUES
                   ('$ledger_id', '$target_user_id', '$company_id', '$type', $points, 'manual', '$notes', '$actor_username', '$now')";

    if (!mysqli_query($conn, $insert)) {
        jsonResponse(500, 'Failed to add points: ' . mysqli_error($conn));
    }

    // return new balance
    $bal_result = mysqli_query($conn,
        "SELECT COALESCE(SUM(points), 0) as balance
         FROM {$schema}.member_point_ledger
         WHERE user_id = '$target_user_id' AND company_id = '$company_id'"
    );
    $new_balance = (int)mysqli_fetch_assoc($bal_result)['balance'];

    jsonResponse(201, 'Points added successfully', [
        'ledger_id'   => $ledger_id,
        'user_id'     => $target_user_id,
        'points_added' => $points,
        'new_balance' => $new_balance,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
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
        'endpoint'        => '/reward/points.php',
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
            getUserPoints($conn, $schema, $user_id, $token_company);
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            addPoints($conn, $schema, $input, $token_username);
            break;

        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/reward/points.php',
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
        'endpoint'        => '/reward/points.php',
        'method'          => $_SERVER['REQUEST_METHOD'],
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>
