<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../notification/notification.php';
require_once '../log.php';
require_once __DIR__ . '/account_rules.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

const RAKI_APP_ID = '06660e87-37e7-491b-92c3-c772130eb57c';
const RESET_PASSWORD_PURPOSE = 'forgot_password';
const OTP_MAX_ATTEMPTS = ACCOUNT_RESET_OTP_MAX_ATTEMPTS;

// Looks the account up by username or by phone number (62…, matched against older un-normalized rows too).
// Returns the row, or null when there is none. More than one match on a phone number is ambiguous: 'ambiguous'.
function findResetAccount($conn, string $coreSchema, string $appId, string $username, string $phone)
{
    if ($username !== '') {
        $stmt = $conn->prepare("SELECT username, phone_number, phone_e164, identity_id, account_status FROM {$coreSchema}.app_user WHERE username = ? AND app_id = ? LIMIT 1");
        $stmt->bind_param('ss', $username, $appId);
    } else {
        $normalized = accountNormalizePhone($phone);
        if ($normalized === null) {
            return null;
        }
        [$a, $b, $c] = accountPhoneVariants($normalized);
        $stmt = $conn->prepare("SELECT username, phone_number, phone_e164, identity_id, account_status FROM {$coreSchema}.app_user
                                WHERE app_id = ? AND (phone_number IN (?, ?, ?) OR phone_e164 IN (?, ?, ?)) LIMIT 2");
        $stmt->bind_param('sssssss', $appId, $a, $b, $c, $a, $b, $c);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if (!$result) {
        jsonResponse(500, 'Internal Server Error');
    }
    if ($result->num_rows > 1) {
        return 'ambiguous';
    }
    return $result->num_rows === 1 ? $result->fetch_assoc() : null;
}

function requestPasswordResetOTP($conn, $input)
{
    $conn = DB::conn();
    $appId = RAKI_APP_ID;
    $purpose = RESET_PASSWORD_PURPOSE;
    $coreSchema = MOVIRA_CORE_SCHEMA;

    // Either field, never both.
    $username = trim($input['username'] ?? '');
    $phone    = trim($input['phone_number'] ?? '');

    if ($username === '' && $phone === '') {
        jsonResponse(400, 'Username or phone_number is required');
    }
    if ($username !== '' && $phone !== '') {
        jsonResponse(400, 'Send either username or phone_number, not both');
    }

    $user = findResetAccount($conn, $coreSchema, $appId, $username, $phone);

    if ($user === null) {
        jsonResponse(404, 'Account not found', ['reason' => 'not_found']);
    }
    if ($user === 'ambiguous') {
        jsonResponse(409, 'This phone number belongs to more than one account, use the username instead', ['reason' => 'ambiguous_phone']);
    }

    $blockReason = accountResetBlockReason($user['account_status'] ?? null);
    if ($blockReason !== null) {
        jsonResponse(403, $blockReason === 'pending'
            ? 'Akun kamu masih menunggu persetujuan admin.'
            : 'Akun kamu nonaktif. Hubungi admin RAKI.', ['reason' => $blockReason]);
    }

    $phone_number = $user['phone_number'];
    $username = $user['username'];
    $identifier = $user['phone_e164'] ?: $phone_number;
    $identityId = $user['identity_id'];

    if (!$phone_number) {
        jsonResponse(422, 'This account has no phone number registered', ['reason' => 'no_phone']);
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

        if ($diff < ACCOUNT_RESET_RESEND_SECONDS) {
            jsonResponse(429, 'Please wait 2–3 minutes before requesting a new OTP', [
                'reason'   => 'too_soon',
                'retry_in' => ACCOUNT_RESET_RESEND_SECONDS - $diff,
            ]);
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
            jsonResponse(423, 'Too many password reset requests, please try again later', ['reason' => 'locked']);
        }
    }

    $stmt = $conn->prepare("UPDATE {$coreSchema}.otp_codes SET used_at = NOW() WHERE identifier = ? AND purpose = ? AND used_at IS NULL");
    $stmt->bind_param('ss', $identifier, $purpose);
    $stmt->execute();
    $stmt->close();

    $otp       = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otp_id    = uniqid('otp_');
    $expire_at = date('Y-m-d H:i:s', time() + ACCOUNT_RESET_OTP_TTL_SECONDS);

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
    $sent = sendWhatsAppText($phone_number, $text);

    if (empty($sent['success'])) {
        // Nothing reached the user, so don't make them wait out the resend timer for a code they never got.
        $stmt = $conn->prepare("DELETE FROM {$coreSchema}.otp_codes WHERE otp_id = ?");
        $stmt->bind_param('s', $otp_id);
        $stmt->execute();
        $stmt->close();

        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 502,
            'endpoint'      => '/account/forgot_password.php',
            'method'        => 'POST',
            'error_message' => 'WhatsApp send failed: ' . ($sent['error'] ?? ('HTTP ' . ($sent['httpCode'] ?? '?'))),
            'user_identifier' => $username,
        ]);
        jsonResponse(502, 'Failed to send the OTP over WhatsApp', ['reason' => 'wa_failed']);
    }

    jsonResponse(200, 'OTP sent', [
        'username'       => $username,
        'masked_phone'   => accountMaskPhone($phone_number),
        'otp_expires_in' => ACCOUNT_RESET_OTP_TTL_SECONDS,
        'resend_in'      => ACCOUNT_RESET_RESEND_SECONDS,
    ]);
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

    $stmt = $conn->prepare("SELECT username, phone_number, phone_e164, account_status FROM {$coreSchema}.app_user WHERE username = ? AND app_id = ? LIMIT 1");
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

    // The account may have been switched off since the code was sent.
    $blockReason = accountResetBlockReason($user['account_status'] ?? null);
    if ($blockReason !== null) {
        jsonResponse(403, 'Akun ini tidak bisa mengatur ulang password.', ['reason' => $blockReason]);
    }

    $stmt = $conn->prepare("SELECT otp_id, otp_hash, expire_at, attempt_count
                            FROM {$coreSchema}.otp_codes
                            WHERE identifier = ? AND purpose = ? AND used_at IS NULL
                            ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param('ss', $identifier, $purpose);
    $stmt->execute();
    $otp_result = $stmt->get_result();
    $stmt->close();

    if (!$otp_result || $otp_result->num_rows !== 1) {
        jsonResponse(400, 'Invalid OTP', ['attempts_left' => 0]);
    }

    $otp_row = $otp_result->fetch_assoc();
    $maxAttempts = ACCOUNT_RESET_OTP_MAX_ATTEMPTS;

    if ((int)$otp_row['attempt_count'] >= $maxAttempts) {
        burnResetOtp($conn, $coreSchema, $otp_row['otp_id']);
        jsonResponse(429, 'Too many failed OTP attempts. Please request a new OTP.', ['reason' => 'too_many_attempts']);
    }

    if (strtotime($otp_row['expire_at']) < time()) {
        jsonResponse(410, 'OTP expired', ['reason' => 'expired']);
    }

    $attempts = (int)$otp_row['attempt_count'] + 1;
    $stmt = $conn->prepare("UPDATE {$coreSchema}.otp_codes SET attempt_count = ? WHERE otp_id = ?");
    $stmt->bind_param('is', $attempts, $otp_row['otp_id']);
    $stmt->execute();
    $stmt->close();

    if (!hash_equals($otp_row['otp_hash'], otpHash((string)$otp))) {
        if ($attempts >= $maxAttempts) {
            // Third wrong code: burn it, a new one must be requested.
            burnResetOtp($conn, $coreSchema, $otp_row['otp_id']);
            jsonResponse(429, 'Too many failed OTP attempts. Please request a new OTP.', ['reason' => 'too_many_attempts']);
        }
        jsonResponse(400, 'Invalid OTP', ['attempts_left' => $maxAttempts - $attempts]);
    }

    burnResetOtp($conn, $coreSchema, $otp_row['otp_id']);

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

function burnResetOtp($conn, string $coreSchema, string $otpId): void
{
    $stmt = $conn->prepare("UPDATE {$coreSchema}.otp_codes SET used_at = NOW() WHERE otp_id = ? LIMIT 1");
    $stmt->bind_param('s', $otpId);
    $stmt->execute();
    $stmt->close();
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

    if (strlen($newPassword) < ACCOUNT_RESET_MIN_PASSWORD_LENGTH) {
        jsonResponse(400, 'Password must be at least ' . ACCOUNT_RESET_MIN_PASSWORD_LENGTH . ' characters', ['reason' => 'too_short']);
    }

    if (accountIsBlockedPassword($newPassword)) {
        jsonResponse(400, 'Password ini tidak boleh dipakai. Buat password lain.', ['reason' => 'blocked_password']);
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

    $stmt = $conn->prepare("SELECT password, account_status FROM {$coreSchema}.app_user WHERE username = ? AND app_id = ? LIMIT 1");
    $stmt->bind_param('ss', $username, $appId);
    $stmt->execute();
    $current_result = $stmt->get_result();
    $stmt->close();

    if (!$current_result || $current_result->num_rows !== 1) {
        jsonResponse(401, 'Invalid reset token');
    }
    $current = $current_result->fetch_assoc();

    $blockReason = accountResetBlockReason($current['account_status'] ?? null);
    if ($blockReason !== null) {
        jsonResponse(403, 'Akun ini tidak bisa mengatur ulang password.', ['reason' => $blockReason]);
    }

    $escapedPassword = mysqli_real_escape_string($conn, $newPassword);

    if (password_verify($escapedPassword, (string)$current['password'])) {
        jsonResponse(409, 'Password baru tidak boleh sama dengan password lama.', ['reason' => 'same_as_old']);
    }

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
