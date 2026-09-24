<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../log.php';
require_once __DIR__ . '/account_rules.php';

// Self-registration. The account is created pending, with no role and no company:
// any app_role_id the client sends is ignored (this endpoint is public, so trusting
// it would let anyone create an Owner). An Owner grants the role through
// account/pending.php, and login.php refuses pending/rejected accounts until then.
function register($conn, $input){
    $conn = DB::conn();

    $validated = accountValidateRegistration($input);
    if (isset($validated['error'])) {
        logApiError($conn, [
            'error_level'   => 'warning',
            'http_status'   => 400,
            'endpoint'      => '/account/register.php',
            'method'        => 'POST',
            'error_message' => $validated['error'],
            'user_identifier' => is_array($input) ? ($input['username'] ?? null) : null,
            'company_id'      => null,
        ]);
        jsonResponse(400, $validated['error']);
    }
    $data = $validated['data'];
    $username = $data['username'];

    // Usernames stay unique across every app: other endpoints (profile.php, forgot_password.php)
    // look users up by username alone.
    $stmtUser = $conn->prepare("SELECT user_id FROM movira_core_dev.app_user WHERE username = ? LIMIT 1");
    $stmtUser->bind_param('s', $username);
    $stmtUser->execute();
    if ($stmtUser->get_result()->num_rows > 0) {
        jsonResponse(409, 'Username sudah dipakai');
    }

    // Phone numbers stay unique within RAKI: account/otp.php finds the user by phone_number,
    // and with two matches it would auto-create yet another account instead of logging in.
    $variants = accountPhoneVariants($data['phone_number']);
    $appId = $data['app_id'];
    $stmtPhone = $conn->prepare("SELECT user_id FROM movira_core_dev.app_user WHERE app_id = ? AND phone_number IN (?, ?, ?) LIMIT 1");
    $stmtPhone->bind_param('ssss', $appId, $variants[0], $variants[1], $variants[2]);
    $stmtPhone->execute();
    if ($stmtPhone->get_result()->num_rows > 0) {
        jsonResponse(409, 'Nomor HP sudah terdaftar');
    }

    // login.php escapes the password before password_verify(), so it has to be hashed the same way.
    $hashedPassword = password_hash(mysqli_real_escape_string($conn, $data['password']), PASSWORD_DEFAULT);
    $userID = "user" . uniqid();
    $status = ACCOUNT_STATUS_PENDING;

    $stmtInsert = $conn->prepare("INSERT INTO movira_core_dev.app_user (user_id, username, password, app_id, app_role_id, company_id, first_name, phone_number, email, account_status, created_at) VALUES (?, ?, ?, ?, NULL, NULL, ?, ?, ?, ?, NOW())");
    $stmtInsert->bind_param('ssssssss', $userID, $username, $hashedPassword, $appId, $data['full_name'], $data['phone_number'], $data['email'], $status);

    try {
        $inserted = $stmtInsert->execute();
        $errno = $inserted ? 0 : $stmtInsert->errno;
        $error = $inserted ? '' : $stmtInsert->error;
    } catch (mysqli_sql_exception $e) {
        $inserted = false;
        $errno = $e->getCode();
        $error = $e->getMessage();
    }

    if (!$inserted) {
        // Lost a race with a concurrent sign-up for the same username.
        if ($errno === 1062) {
            jsonResponse(409, 'Username sudah dipakai');
        }
        throw new Exception('Failed to create user: ' . $error);
    }

    jsonResponse(201, 'Pendaftaran berhasil, menunggu persetujuan admin', [
        'username'       => $username,
        'account_status' => $status,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");
    http_response_code(200);
    exit();
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            register($conn, $input);
            break;
        default:
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 405,
                'endpoint'      => '/account/register.php',
                'method'        => $method,
                'error_message' => 'Method Not Allowed',
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/account/register.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}


?>
