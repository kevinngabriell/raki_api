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
const RESET_PASSWORD_PURPOSE = 'forgot_password';
const OTP_MAX_ATTEMPTS = 5;

function requestPasswordResetOTP($conn, $input)
{
    $conn = DB::conn();
    $appId = RAKI_APP_ID;
    $purpose = RESET_PASSWORD_PURPOSE;
    $coreSchema = MOVIRA_CORE_SCHEMA;

    $username = trim($input['username'] ?? '');

    if (!$username) {
        jsonResponse(400, 'Username is required');
    }

    $stmt = $conn->prepare("SELECT username, phone_number, phone_e164, identity_id FROM {$coreSchema}.app_user WHERE username = ? AND app_id = ? LIMIT 1");
    $stmt->bind_param('ss', $username, $appId);
    $stmt->execute();
    $user_result = $stmt->get_result();
    $stmt->close();

    if (!$user_result) {
        jsonResponse(500, 'Internal Server Error');
    }

    if ($user_result->num_rows !== 1) {
        jsonResponse(404, 'User not found');
    }

    $user = $user_result->fetch_assoc();
    $phone_number = $user['phone_number'];
    $username = $user['username'];
    $identifier = $user['phone_e164'] ?: $phone_number;
    $identityId = $user['identity_id'];

    if (!$phone_number) {
        jsonResponse(422, 'This account has no phone number registered');
    }

    $stmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS diff_seconds
                            FROM {$coreSchema}.otp_codes
                            WHERE identifier = ? AND purpose = ?
                            ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param('ss', $identifier, $purpose);
    $stmt->execute();
    $rate_limit_result = $stmt->get_result();
    $stmt->close();

    if ($rate_limit_result && $rate_limit_result->num_rows === 1) {
        $row = $rate_limit_result->fetch_assoc();
        $diff = (int)$row['diff_seconds'];

        if ($diff < 180) {
            jsonResponse(429, 'Please wait 2–3 minutes before requesting a new OTP');
        }
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS total_requests
                            FROM {$coreSchema}.otp_codes
                            WHERE identifier = ? AND purpose = ?
                            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->bind_param('ss', $identifier, $purpose);
    $stmt->execute();
    $lock_check_result = $stmt->get_result();
    $stmt->close();

    if ($lock_check_result) {
        $lockData = $lock_check_result->fetch_assoc();

        if ((int)$lockData['total_requests'] >= 10) {
            jsonResponse(423, 'Too many password reset requests, please try again later');
        }
    }

    $stmt = $conn->prepare("UPDATE {$coreSchema}.otp_codes SET used_at = NOW() WHERE identifier = ? AND purpose = ? AND used_at IS NULL");
    $stmt->bind_param('ss', $identifier, $purpose);
    $stmt->execute();
    $stmt->close();

    $otp       = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otp_id    = uniqid('otp_');
    $expire_at = date('Y-m-d H:i:s', time() + 5 * 60);

    $otpHash     = otpHash($otp);
    $channel     = 'whatsapp';
    $ip          = $_SERVER['REMOTE_ADDR'] ?? null;
    $maxAttempts = OTP_MAX_ATTEMPTS;

    $stmt = $conn->prepare("INSERT INTO {$coreSchema}.otp_codes (otp_id, identity_id, identifier, channel, purpose, otp_hash, expire_at, attempt_count, max_attempts, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())");
    $stmt->bind_param('sssssssis', $otp_id, $identityId, $identifier, $channel, $purpose, $otpHash, $expire_at, $maxAttempts, $ip);

    if (!$stmt->execute()) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/forgot_password.php',
            'method'        => 'POST',
            'error_message' => 'Failed to create OTP',
            'user_identifier' => $username,
        ]);
        jsonResponse(500, 'Failed to create OTP');
    }
    $stmt->close();

    $text = "Kode OTP Reset Password RAKI Anda: $otp\nBerlaku selama 5 menit.\nJangan bagikan kode ini kepada siapapun.";
    sendWhatsAppText($phone_number, $text);

    jsonResponse(200, 'OTP sent');
}

function verifyPasswordResetOTP($conn, $input)
{
    $conn = DB::conn();
    $appId = RAKI_APP_ID;
    $purpose = RESET_PASSWORD_PURPOSE;
    $coreSchema = MOVIRA_CORE_SCHEMA;

    $username = trim($input['username'] ?? '');
    $otp      = $input['otp'] ?? null;

    if (!$username || !$otp) {
        jsonResponse(400, 'Username and OTP are required');
    }

    $stmt = $conn->prepare("SELECT username, phone_number, phone_e164 FROM {$coreSchema}.app_user WHERE username = ? AND app_id = ? LIMIT 1");
    $stmt->bind_param('ss', $username, $appId);
    $stmt->execute();
    $user_result = $stmt->get_result();
    $stmt->close();

    if (!$user_result || $user_result->num_rows !== 1) {
        jsonResponse(400, 'Invalid OTP');
    }

    $user = $user_result->fetch_assoc();
    $username   = $user['username'];
    $identifier = $user['phone_e164'] ?: $user['phone_number'];

    $stmt = $conn->prepare("SELECT otp_id, otp_hash, expire_at, attempt_count, max_attempts
                            FROM {$coreSchema}.otp_codes
                            WHERE identifier = ? AND purpose = ? AND used_at IS NULL
                            ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param('ss', $identifier, $purpose);
    $stmt->execute();
    $otp_result = $stmt->get_result();
    $stmt->close();

    if (!$otp_result || $otp_result->num_rows !== 1) {
        jsonResponse(400, 'Invalid OTP');
    }

    $otp_row = $otp_result->fetch_assoc();

    if ((int)$otp_row['attempt_count'] >= (int)$otp_row['max_attempts']) {
        $stmt = $conn->prepare("UPDATE {$coreSchema}.otp_codes SET used_at = NOW() WHERE otp_id = ?");
        $stmt->bind_param('s', $otp_row['otp_id']);
        $stmt->execute();
        $stmt->close();

        jsonResponse(423, 'Too many failed OTP attempts. Please request a new OTP.');
    }

    $stmt = $conn->prepare("UPDATE {$coreSchema}.otp_codes SET attempt_count = attempt_count + 1 WHERE otp_id = ?");
    $stmt->bind_param('s', $otp_row['otp_id']);
    $stmt->execute();
    $stmt->close();

    if (!hash_equals($otp_row['otp_hash'], otpHash((string)$otp))) {
        jsonResponse(400, 'Invalid OTP');
    }

    if (strtotime($otp_row['expire_at']) < time()) {
        jsonResponse(400, 'OTP expired');
    }

    $stmt = $conn->prepare("UPDATE {$coreSchema}.otp_codes SET used_at = NOW() WHERE otp_id = ? LIMIT 1");
    $stmt->bind_param('s', $otp_row['otp_id']);
    $stmt->execute();
    $stmt->close();

    $issuedAt       = time();
    $expirationTime = $issuedAt + 10 * 60;

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

function resetPassword($conn, $input)
{
    $conn = DB::conn();
    $appId = RAKI_APP_ID;
    $coreSchema = MOVIRA_CORE_SCHEMA;

    $resetToken  = $input['reset_token'] ?? null;
    $newPassword = $input['new_password'] ?? null;

    if (!$resetToken || !$newPassword) {
        jsonResponse(400, 'Reset token and new password are required');
    }

    if (strlen($newPassword) < 6) {
        jsonResponse(400, 'Password must be at least 6 characters');
    }

    try {
        $decoded = JWT::decode($resetToken, new Key($_ENV['JWT_SECRET'], 'HS256'));
    } catch (Exception $e) {
        logApiError($conn, [
            'error_level'   => 'warning',
            'http_status'   => 401,
            'endpoint'      => '/account/forgot_password.php',
            'method'        => 'PATCH',
            'error_message' => 'Invalid or expired reset token',
        ]);
        jsonResponse(401, 'Invalid or expired reset token');
    }

    if (($decoded->purpose ?? null) !== RESET_PASSWORD_PURPOSE || empty($decoded->username)) {
        jsonResponse(401, 'Invalid reset token');
    }

    $username = $decoded->username;

    $escapedPassword = mysqli_real_escape_string($conn, $newPassword);
    $hashedPassword = password_hash($escapedPassword, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE {$coreSchema}.app_user SET password = ? WHERE username = ? AND app_id = ?");
    $stmt->bind_param('sss', $hashedPassword, $username, $appId);

    if (!$stmt->execute()) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/forgot_password.php',
            'method'        => 'PATCH',
            'error_message' => 'Failed to reset password',
            'user_identifier' => $username,
        ]);
        jsonResponse(500, 'Failed to reset password');
    }
    $stmt->close();

    jsonResponse(200, 'Password has been reset, please log in with your new password');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            requestPasswordResetOTP(DB::conn(), $input);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            verifyPasswordResetOTP(DB::conn(), $input);
            break;
        case 'PATCH':
            $input = json_decode(file_get_contents('php://input'), true);
            resetPassword(DB::conn(), $input);
            break;
        default:
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
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
