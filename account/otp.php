<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../notification/notification.php';
require_once '../log.php';
require_once __DIR__ . '/account_rules.php';

use Firebase\JWT\JWT;

const RAKI_APP_ID = '06660e87-37e7-491b-92c3-c772130eb57c';
const OTP_MAX_ATTEMPTS = 5;

function createOTP($conn, $input)
{
    $conn = DB::conn();
    $appId = RAKI_APP_ID;
    $coreSchema = MOVIRA_CORE_SCHEMA;

    $phone_number = $input['phone_number'] ?? null;

    if (!$phone_number) {
        logApiError($conn, [
            'error_level'   => 'warning',
            'http_status'   => 400,
            'endpoint'      => '/account/otp.php',
            'method'        => 'POST',
            'error_message' => 'Missing phone number',
        ]);
        jsonResponse(400, 'Phone number is required');
    }

    $stmt = $conn->prepare("SELECT username, phone_number, phone_e164, identity_id, account_status
                            FROM {$coreSchema}.app_user
                            WHERE phone_number = ? AND app_id = ?
                            LIMIT 1");
    $stmt->bind_param('ss', $phone_number, $appId);
    $stmt->execute();
    $user_result = $stmt->get_result();
    $stmt->close();

    if (!$user_result) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/otp.php',
            'method'        => 'POST',
            'error_message' => 'DB query failed',
        ]);
        jsonResponse(500, 'Internal Server Error');
    }

    if ($user_result->num_rows !== 1) {
        jsonResponse(404, 'Phone number not registered. Please register first.');
    }

    $user = mysqli_fetch_assoc($user_result);
    $username   = $user['username'];
    $picContact = $user['phone_number'];
    $identifier = $user['phone_e164'] ?: $user['phone_number'];
    $identityId = $user['identity_id'];

    $blockedMessage = accountStatusBlockMessage($user['account_status'] ?? null);
    if ($blockedMessage !== null) {
        jsonResponse(403, $blockedMessage);
    }

    $stmt = $conn->prepare("SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS diff_seconds
                            FROM {$coreSchema}.otp_codes
                            WHERE identifier = ? AND purpose = 'login'
                            ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param('s', $identifier);
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
                            WHERE identifier = ? AND purpose = 'login'
                            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->bind_param('s', $identifier);
    $stmt->execute();
    $lock_check_result = $stmt->get_result();
    $stmt->close();

    if ($lock_check_result) {
        $lockData = $lock_check_result->fetch_assoc();

        if ((int)$lockData['total_requests'] >= 10) {
            jsonResponse(423, 'Your account is temporarily locked due to too many OTP requests');
        }
    }

    $stmt = $conn->prepare("UPDATE {$coreSchema}.otp_codes SET used_at = NOW() WHERE identifier = ? AND purpose = 'login' AND used_at IS NULL");
    $stmt->bind_param('s', $identifier);
    $stmt->execute();
    $stmt->close();

    $otp       = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otp_id    = uniqid('otp_');
    $expire_at = date('Y-m-d H:i:s', time() + 5 * 60);
    $purpose   = 'login';

    $otpHash   = otpHash($otp);
    $channel   = 'whatsapp';
    $ip        = $_SERVER['REMOTE_ADDR'] ?? null;

    $stmt = $conn->prepare("INSERT INTO {$coreSchema}.otp_codes (otp_id, identity_id, identifier, channel, purpose, otp_hash, expire_at, attempt_count, max_attempts, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?, NOW())");
    $maxAttempts = OTP_MAX_ATTEMPTS;
    $stmt->bind_param('sssssssis', $otp_id, $identityId, $identifier, $channel, $purpose, $otpHash, $expire_at, $maxAttempts, $ip);

    if (!$stmt->execute()) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/otp.php',
            'method'        => 'POST',
            'error_message' => 'Failed to create OTP',
            'user_identifier' => $username,
        ]);
        jsonResponse(500, 'Failed to create OTP');
    }
    $stmt->close();

    $text = "Kode otp Login RAKI Anda: $otp\nBerlaku selama 5 menit.";
    $waResult = sendWhatsAppText($picContact, $text);

    jsonResponse(200, 'OTP sent', [
        'phone_number' => $picContact,
        'expire_at'    => $expire_at,
        'wa_status'    => $waResult
    ]);
}

function validateOTP($conn, $input)
{
    $conn = DB::conn();
    $appId = RAKI_APP_ID;
    $coreSchema = MOVIRA_CORE_SCHEMA;

    $phone_number = $input['phone_number'] ?? null;
    $otp          = $input['otp'] ?? null;

    if (!$phone_number || !$otp) {
        jsonResponse(400, 'Phone number and OTP are required');
    }

    $stmt = $conn->prepare("SELECT username, company_id, app_role_id, account_status, phone_number, phone_e164
                            FROM {$coreSchema}.app_user
                            WHERE phone_number = ? AND app_id = ?
                            LIMIT 1");
    $stmt->bind_param('ss', $phone_number, $appId);
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
    $username   = $user['username'];
    $company_id = $user['company_id'];
    $role       = $user['app_role_id'];
    $identifier = $user['phone_e164'] ?: $user['phone_number'];

    $stmt = $conn->prepare("SELECT otp_id, otp_hash, expire_at, attempt_count, max_attempts
                            FROM {$coreSchema}.otp_codes
                            WHERE identifier = ? AND purpose = 'login' AND used_at IS NULL
                            ORDER BY created_at DESC LIMIT 1");
    $stmt->bind_param('s', $identifier);
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

    $blockedMessage = accountStatusBlockMessage($user['account_status'] ?? null);
    if ($blockedMessage !== null) {
        jsonResponse(403, $blockedMessage);
    }

    $issuedAt       = time();
    $expirationTime = $issuedAt + 30 * 24 * 60 * 60;

    $payload = [
        'iat'        => $issuedAt,
        'exp'        => $expirationTime,
        'username'   => $username,
        'company_id' => $company_id,
        'role'       => $role
    ];

    $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');

    RequestLogger::setActor([
        'username'   => $username,
        'company_id' => $company_id,
    ]);

    Response::success([
        'token' => $jwt,
        'expires_in' => $expirationTime,
        'user' => $payload,
    ], null, 200, 'Login successful');
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
            createOTP(DB::conn(), $input);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            validateOTP(DB::conn(), $input);
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
        'endpoint'      => '/account/otp.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
