<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../notification/notification.php';
require_once '../log.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

const RAKI_APP_ID = '06660e87-37e7-491b-92c3-c772130eb57c';
const RESET_PASSWORD_PURPOSE = 'reset_password';

// Step 1: request an OTP for the account matching the given username
function requestPasswordResetOTP($conn, $input)
{
    $conn = DB::conn();

    $username = $input['username'] ?? null;

    if (!$username) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/account/forgot_password.php',
            'method'        => 'POST',
            'error_message' => 'Missing username',
            'user_identifier' => null,
            'company_id'      => null,
        ]);

        jsonResponse(400, 'Username is required');
    }

    $username = mysqli_real_escape_string($conn, $username);

    $user_query = "SELECT username, phone_number FROM movira_core_dev.app_user WHERE username = '$username' AND app_id = '" . RAKI_APP_ID . "' LIMIT 1";
    $user_result = mysqli_query($conn, $user_query);

    if (!$user_result) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/forgot_password.php',
            'method'        => 'POST',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    // Don't reveal whether the username exists — respond the same way either way.
    if (mysqli_num_rows($user_result) !== 1) {
        jsonResponse(200, 'If the account exists, an OTP has been sent');
    }

    $user = mysqli_fetch_assoc($user_result);
    $phone_number = $user['phone_number'];

    if (!$phone_number) {
        jsonResponse(200, 'If the account exists, an OTP has been sent');
    }

    // === RATE LIMIT: WAIT 3 MINUTES BETWEEN OTP REQUESTS ===
    $rate_limit_query = "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS diff_seconds FROM otp_codes WHERE identifier = '$username' AND purpose = '" . RESET_PASSWORD_PURPOSE . "' ORDER BY created_at DESC LIMIT 1";
    $rate_limit_result = mysqli_query($conn, $rate_limit_query);

    if ($rate_limit_result && mysqli_num_rows($rate_limit_result) === 1) {
        $row = mysqli_fetch_assoc($rate_limit_result);
        $diff = (int)$row['diff_seconds'];

        if ($diff < 180) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 429,
                'endpoint'      => '/account/forgot_password.php',
                'method'        => 'POST',
                'error_message' => 'Please wait 2–3 minutes before requesting a new OTP',
                'user_identifier' => $username,
                'company_id'      => null,
            ]);

            jsonResponse(429, 'Please wait 2–3 minutes before requesting a new OTP');
        }
    }

    // === LOCK ACCOUNT AFTER 10 OTP REQUESTS IN 24 HOURS ===
    $lock_check_query = "SELECT COUNT(*) AS total_requests FROM otp_codes WHERE identifier = '$username' AND purpose = '" . RESET_PASSWORD_PURPOSE . "' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    $lock_check_result = mysqli_query($conn, $lock_check_query);

    if ($lock_check_result) {
        $lockData = mysqli_fetch_assoc($lock_check_result);

        if ((int)$lockData['total_requests'] >= 10) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 423,
                'endpoint'      => '/account/forgot_password.php',
                'method'        => 'POST',
                'error_message' => 'Too many password reset requests',
                'user_identifier' => $username,
                'company_id'      => null,
            ]);

            jsonResponse(423, 'Too many password reset requests, please try again later');
        }
    }

    // Kill any previous unused reset OTPs for this user
    $kill_old = "UPDATE otp_codes SET is_used = 1 WHERE identifier = '$username' AND purpose = '" . RESET_PASSWORD_PURPOSE . "'";
    mysqli_query($conn, $kill_old);

    $otp       = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otp_id    = uniqid('otp_');
    $expire_at = date('Y-m-d H:i:s', time() + 5 * 60); // 5 menit

    $insert_otp = "INSERT INTO otp_codes (otp_id, identifier, otp_code, purpose, expire_at, is_used, attempt_count, created_at) VALUES ('$otp_id', '$username', '$otp', '" . RESET_PASSWORD_PURPOSE . "', '$expire_at', 0, 0, NOW())";

    if (!mysqli_query($conn, $insert_otp)) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/forgot_password.php',
            'method'        => 'POST',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username,
            'company_id'      => null,
        ]);

        jsonResponse(500, 'Failed to create OTP', ['error' => mysqli_error($conn)]);
    }

    $text = "Kode OTP Reset Password RAKI Anda: $otp\nBerlaku selama 5 menit.\nJangan bagikan kode ini kepada siapapun.";
    sendWhatsAppText($phone_number, $text);

    jsonResponse(200, 'If the account exists, an OTP has been sent');
}

// Step 2: verify the OTP and issue a short-lived reset token
function verifyPasswordResetOTP($conn, $input)
{
    $conn = DB::conn();

    $username = $input['username'] ?? null;
    $otp      = $input['otp'] ?? null;

    if (!$username || !$otp) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/account/forgot_password.php',
            'method'        => 'PUT',
            'error_message' => 'username and otp are required',
            'user_identifier' => $username,
            'company_id'      => null,
        ]);

        jsonResponse(400, 'Username and OTP are required');
    }

    $username = mysqli_real_escape_string($conn, $username);
    $otp      = mysqli_real_escape_string($conn, $otp);

    // Tambah attempt_count untuk OTP aktif user ini
    $inc_attempt = "UPDATE otp_codes SET attempt_count = attempt_count + 1 WHERE identifier = '$username' AND purpose = '" . RESET_PASSWORD_PURPOSE . "' AND is_used = 0";
    mysqli_query($conn, $inc_attempt);

    $otp_query = "SELECT * FROM otp_codes WHERE identifier = '$username' AND otp_code = '$otp' AND purpose = '" . RESET_PASSWORD_PURPOSE . "' AND is_used = 0 ORDER BY created_at DESC LIMIT 1";
    $otp_result = mysqli_query($conn, $otp_query);

    if (!$otp_result || mysqli_num_rows($otp_result) !== 1) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/account/forgot_password.php',
            'method'        => 'PUT',
            'error_message' => 'Invalid OTP',
            'user_identifier' => $username,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'Invalid OTP');
    }

    $otp_row = mysqli_fetch_assoc($otp_result);

    if (strtotime($otp_row['expire_at']) < time()) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/account/forgot_password.php',
            'method'        => 'PUT',
            'error_message' => 'OTP expired',
            'user_identifier' => $username,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'OTP expired');
    }

    // Mark OTP as used — it can't be redeemed again, only the reset token below can be.
    $mark_used = "UPDATE otp_codes SET is_used = 1, used_at = NOW() WHERE otp_id = '{$otp_row['otp_id']}' LIMIT 1";
    mysqli_query($conn, $mark_used);

    $issuedAt       = time();
    $expirationTime = $issuedAt + 10 * 60; // 10 menit untuk submit password baru

    $payload = [
        'iat'      => $issuedAt,
        'exp'      => $expirationTime,
        'purpose'  => RESET_PASSWORD_PURPOSE,
        'username' => $username,
    ];

    $resetToken = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');

    jsonResponse(200, 'OTP verified', [
        'reset_token' => $resetToken,
        'expires_in'  => $expirationTime,
    ]);
}

// Step 3: consume the reset token and set the new password
function resetPassword($conn, $input)
{
    $conn = DB::conn();

    $resetToken  = $input['reset_token'] ?? null;
    $newPassword = $input['new_password'] ?? null;

    if (!$resetToken || !$newPassword) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/account/forgot_password.php',
            'method'        => 'PATCH',
            'error_message' => 'reset_token and new_password are required',
            'user_identifier' => null,
            'company_id'      => null,
        ]);

        jsonResponse(400, 'Reset token and new password are required');
    }

    if (strlen($newPassword) < 6) {
        jsonResponse(400, 'Password must be at least 6 characters');
    }

    try {
        $decoded = JWT::decode($resetToken, new Key($_ENV['JWT_SECRET'], 'HS256'));
    } catch (Exception $e) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 401,
            'endpoint'      => '/account/forgot_password.php',
            'method'        => 'PATCH',
            'error_message' => 'Invalid or expired reset token',
            'user_identifier' => null,
            'company_id'      => null,
        ]);
        jsonResponse(401, 'Invalid or expired reset token');
    }

    if (($decoded->purpose ?? null) !== RESET_PASSWORD_PURPOSE || empty($decoded->username)) {
        jsonResponse(401, 'Invalid reset token');
    }

    $username = mysqli_real_escape_string($conn, $decoded->username);
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

    $update_query = "UPDATE movira_core_dev.app_user SET password = '$hashedPassword' WHERE username = '$username' AND app_id = '" . RAKI_APP_ID . "'";

    if (!mysqli_query($conn, $update_query)) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/forgot_password.php',
            'method'        => 'PATCH',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Failed to reset password', ['error' => mysqli_error($conn)]);
    }

    jsonResponse(200, 'Password has been reset, please log in with your new password');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, PATCH, DELETE");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");
    http_response_code(200);
    exit();
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            requestPasswordResetOTP($conn, $input);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            verifyPasswordResetOTP($conn, $input);
            break;
        case 'PATCH':
            $input = json_decode(file_get_contents('php://input'), true);
            resetPassword($conn, $input);
            break;
        default:
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 405,
                'endpoint'      => '/account/forgot_password.php',
                'method'        => $method,
                'error_message' => 'Method Not Allowed',
                'user_identifier' => null,
                'company_id'      => null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
            break;
    }
} catch (Exception $e) {
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/account/forgot_password.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => null,
        'company_id'      => null,
    ]);

    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>
