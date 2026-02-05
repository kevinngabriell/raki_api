<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../notification/notification.php';
require_once '../log.php';

use Firebase\JWT\JWT;

function createOTP($conn, $input)
{
    $conn = DB::conn();

    $phone_number = $input['phone_number'] ?? null;
    if (!$phone_number) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/account/otp.php',
            'method'        => 'POST',
            'error_message' => 'Missing phone number',
            'user_identifier' => $phone_number ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(400, 'Phone number is required !!');
    }

    $phone_number = mysqli_real_escape_string($conn, $phone_number);

    // Ambil user berdasarkan nomor telepon
    $user_query = "SELECT username, phone_number FROM movira_core_dev.app_user WHERE phone_number = '$phone_number' LIMIT 1";
    $user_result = mysqli_query($conn, $user_query);

    if (!$user_result) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/otp.php',
            'method'        => 'POST',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $phone_number ?? null,
            'company_id'      => $decoded->company_id ?? null,
        ]);
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($user_result) !== 1) {
        //Default role as a guest and default app_id as RAKI apps
        $app_id = '06660e87-37e7-491b-92c3-c772130eb57c';
        $app_role_id = 'app_role690a9cda69b46';

        //Create a new user ID
        $user_id = 'user' . uniqid();

        // username uses phone number
        $new_username = $phone_number;

        // random password (hashed)
        $plain_password = bin2hex(random_bytes(16));
        $hashed_password = password_hash($plain_password, PASSWORD_DEFAULT);

        $user_id_esc = mysqli_real_escape_string($conn, $user_id);
        $username_esc = mysqli_real_escape_string($conn, $new_username);
        $pass_esc = mysqli_real_escape_string($conn, $hashed_password);
        $app_id_esc = mysqli_real_escape_string($conn, $app_id);
        $app_role_id_esc = mysqli_real_escape_string($conn, $app_role_id);

        $insertUserQuery = "INSERT INTO movira_core_dev.app_user (user_id, username, password, app_id, app_role_id, phone_number, created_at) VALUES ('$user_id_esc', '$username_esc', '$pass_esc', '$app_id_esc', '$app_role_id_esc', '$phone_number', NOW())";

        if (!mysqli_query($conn, $insertUserQuery)) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/account/otp.php',
                'method'        => 'POST',
                'error_message' => mysqli_error($conn),
                'user_identifier' => $username_esc,
                'company_id'      => null,
            ]);
            jsonResponse(500, 'Failed to auto-register user', ['error' => mysqli_error($conn)]);
        }

        // Re-fetch user after insert
        $user_result = mysqli_query($conn, $user_query);

        if (!$user_result || mysqli_num_rows($user_result) !== 1) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 500,
                'endpoint'      => '/account/otp.php',
                'method'        => 'POST',
                'error_message' => 'Failed to load user after auto-register',
                'user_identifier' => $username_esc,
                'company_id'      => null,
            ]);
            jsonResponse(500, 'Failed to load user after auto-register');
        }
    }

    $user = mysqli_fetch_assoc($user_result);
    $username   = $user['username'];
    $picContact = $user['phone_number'];

    // === RATE LIMIT: WAIT 3 MINUTES BETWEEN OTP REQUESTS ===
    $rate_limit_query = "SELECT TIMESTAMPDIFF(SECOND, created_at, NOW()) AS diff_seconds FROM otp_codes WHERE identifier = '$username' AND purpose = 'login' ORDER BY created_at DESC LIMIT 1";
    $rate_limit_result = mysqli_query($conn, $rate_limit_query);

    if ($rate_limit_result && mysqli_num_rows($rate_limit_result) === 1) {
        $row = mysqli_fetch_assoc($rate_limit_result);
        $diff = (int)$row['diff_seconds'];

        // 3 minutes = 180 seconds
        if ($diff < 180) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 429,
                'endpoint'      => '/account/otp.php',
                'method'        => 'POST',
                'error_message' => 'Please wait 2–3 minutes before requesting a new OTP',
                'user_identifier' => $username,
                'company_id'      => null,
            ]);
            jsonResponse(429, 'Please wait 2–3 minutes before requesting a new OTP');
        }
    }

    // === LOCK ACCOUNT AFTER 10 OTP REQUESTS IN 24 HOURS ===
    $lock_check_query = "SELECT COUNT(*) AS total_requests FROM otp_codes WHERE identifier = '$username' AND purpose = 'login'  AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)";
    $lock_check_result = mysqli_query($conn, $lock_check_query);

    if ($lock_check_result) {
        $lockData = mysqli_fetch_assoc($lock_check_result);

        if ((int)$lockData['total_requests'] >= 10) {
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 423,
                'endpoint'      => '/account/otp.php',
                'method'        => 'POST',
                'error_message' => 'Your account is temporarily locked due to too many OTP requests',
                'user_identifier' => $username,
                'company_id'      => null,
            ]);
            jsonResponse(423, 'Your account is temporarily locked due to too many OTP requests');
        }
    }

    // Matikan SEMUA OTP login sebelumnya untuk user ini (paling aman)
    $kill_old = "UPDATE otp_codes SET is_used = 1 WHERE identifier = '$username' AND purpose = 'login'";
    mysqli_query($conn, $kill_old);

    // Generate OTP
    $otp       = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otp_id    = uniqid('otp_');
    $expire_at = date('Y-m-d H:i:s', time() + 5 * 60); // 5 menit

    // Simpan OTP baru
    $insert_otp = "INSERT INTO otp_codes (otp_id, identifier, otp_code, purpose, expire_at, is_used, attempt_count, created_at) VALUES ('$otp_id', '$username', '$otp', 'login', '$expire_at', 0, 0, NOW())";

    if (!mysqli_query($conn, $insert_otp)) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/otp.php',
            'method'        => 'POST',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Failed to create OTP', ['error' => mysqli_error($conn)]);
    }

    // Kirim OTP via WhatsApp
    $text = "Kode OTP Login RAKI Anda: $otp\nBerlaku selama 5 menit.";
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

    $phone_number = $input['phone_number'] ?? null;
    $otp          = $input['otp'] ?? null;

    if (!$phone_number || !$otp) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/account/otp.php',
            'method'        => 'PUT',
            'error_message' => 'phone_number and otp are required',
            'user_identifier' => $phone_number,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'Phone number and OTP are required');
    }

    $phone_number = mysqli_real_escape_string($conn, $phone_number);
    $otp          = mysqli_real_escape_string($conn, $otp);

    // Ambil username dari phone_number
    $user_query = "SELECT username, company_id, app_role_id FROM movira_core_dev.app_user WHERE phone_number = '$phone_number' LIMIT 1";
    $user_result = mysqli_query($conn, $user_query);

    if (!$user_result) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/otp.php',
            'method'        => 'PUT',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $phone_number,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($user_result) !== 1) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 404,
            'endpoint'      => '/account/otp.php',
            'method'        => 'PUT',
            'error_message' => 'User not found',
            'user_identifier' => $phone_number,
            'company_id'      => null,
        ]);
        jsonResponse(404, 'User not found');
    }

    $user = mysqli_fetch_assoc($user_result);
    $username   = $user['username'];
    $company_id = $user['company_id'];
    $role       = $user['app_role_id'];

    // Tambah attempt_count untuk OTP aktif user ini
    $inc_attempt = "UPDATE otp_codes SET attempt_count = attempt_count + 1 WHERE identifier = '$username' AND purpose = 'login' AND is_used = 0";
    mysqli_query($conn, $inc_attempt);

    // Ambil OTP terbaru yang masih aktif
    $otp_query = "SELECT * FROM otp_codes WHERE identifier = '$username' AND otp_code = '$otp' AND purpose = 'login' AND is_used = 0 ORDER BY created_at DESC LIMIT 1";
    $otp_result = mysqli_query($conn, $otp_query);

    if (!$otp_result) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/otp.php',
            'method'        => 'PUT',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $phone_number,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($otp_result) !== 1) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/account/otp.php',
            'method'        => 'PUT',
            'error_message' => 'Invalid OTP',
            'user_identifier' => $phone_number,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'Invalid OTP');
    }

    $otp_row = mysqli_fetch_assoc($otp_result);

    // Cek expired
    if (strtotime($otp_row['expire_at']) < time()) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/account/otp.php',
            'method'        => 'PUT',
            'error_message' => 'OTP expired',
            'user_identifier' => $phone_number,
            'company_id'      => null,
        ]);
        jsonResponse(400, 'OTP expired');
    }

    // Tandai OTP sebagai sudah dipakai
    $mark_used = "UPDATE otp_codes SET is_used = 1, used_at = NOW() WHERE otp_id = '{$otp_row['otp_id']}' LIMIT 1";
    mysqli_query($conn, $mark_used);

    // Generate JWT (sama seperti login)
    $issuedAt       = time();
    $expirationTime = $issuedAt + 3 * 60 * 60; // 3 jam

    $payload = [
        'iat'        => $issuedAt,
        'exp'        => $expirationTime,
        'username'   => $username,
        'company_id' => $company_id,
        'role'       => $role
    ];

    $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');

    http_response_code(200);
    echo json_encode([
        'status_code'    => 200,
        'status_message' => 'OTP verified',
        'token'          => $jwt,
        'expires_in'     => $expirationTime,
        'data'           => $payload
    ]);
    exit;
}

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");
    http_response_code(200);
    exit();
}

try {
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            createOTP($conn, $input);
            break;
        case 'PUT':
            $input = json_decode(file_get_contents('php://input'), true);
            validateOTP($conn, $input);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
            break;
    }
} catch (Exception $e) {
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/account/otp.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>