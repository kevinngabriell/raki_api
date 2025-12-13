<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../notification/notification.php';

use Firebase\JWT\JWT;

/**
 * CREATE OTP (Input: phone_number)
 * POST /account/otp.php
 * Body:
 * {
 *   "phone_number": "62812xxxxxxx"
 * }
 */
function createOTP($conn, $input)
{
    $conn = DB::conn();

    $phone_number = $input['phone_number'] ?? null;
    if (!$phone_number) {
        jsonResponse(400, 'phone_number is required');
    }

    $phone_number = mysqli_real_escape_string($conn, $phone_number);

    // Ambil user berdasarkan nomor telepon
    $user_query = "SELECT username, phone_number 
        FROM movira_core_dev.app_user 
        WHERE phone_number = '$phone_number'
        LIMIT 1
    ";
    $user_result = mysqli_query($conn, $user_query);

    if (!$user_result) {
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($user_result) !== 1) {
        jsonResponse(404, 'User not found');
    }

    $user = mysqli_fetch_assoc($user_result);
    $username   = $user['username'];
    $picContact = $user['phone_number'];

    // === RATE LIMIT: WAIT 3 MINUTES BETWEEN OTP REQUESTS ===
    $last_otp_query = "SELECT created_at 
        FROM otp_codes 
        WHERE identifier = '$username' AND purpose = 'login'
        ORDER BY created_at DESC 
        LIMIT 1
    ";
    $last_otp_result = mysqli_query($conn, $last_otp_query);

    if ($last_otp_result && mysqli_num_rows($last_otp_result) === 1) {
        $lastOtp = mysqli_fetch_assoc($last_otp_result);
        $lastTime = strtotime($lastOtp['created_at']);
        $nowTime  = time();

        // 3 minutes = 180 seconds
        if (($nowTime - $lastTime) < 180) {
            jsonResponse(429, 'Please wait 2–3 minutes before requesting a new OTP');
        }
    }

    // === LOCK ACCOUNT AFTER 10 OTP REQUESTS IN 24 HOURS ===
    $lock_check_query = "
        SELECT COUNT(*) AS total_requests
        FROM otp_codes
        WHERE identifier = '$username'
          AND purpose = 'login'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ";
    $lock_check_result = mysqli_query($conn, $lock_check_query);

    if ($lock_check_result) {
        $lockData = mysqli_fetch_assoc($lock_check_result);

        if ((int)$lockData['total_requests'] >= 10) {
            jsonResponse(423, 'Your account is temporarily locked due to too many OTP requests');
        }
    }

    // Matikan SEMUA OTP login sebelumnya untuk user ini (paling aman)
    $kill_old = "
        UPDATE otp_codes 
        SET is_used = 1 
        WHERE identifier = '$username' 
          AND purpose = 'login'
    ";
    mysqli_query($conn, $kill_old);

    // Generate OTP
    $otp       = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $otp_id    = uniqid('otp_');
    $expire_at = date('Y-m-d H:i:s', time() + 5 * 60); // 5 menit

    // Simpan OTP baru
    $insert_otp = "
        INSERT INTO otp_codes (
            otp_id, identifier, otp_code, purpose, expire_at, is_used, attempt_count, created_at
        ) VALUES (
            '$otp_id', '$username', '$otp', 'login', '$expire_at', 0, 0, NOW()
        )
    ";

    if (!mysqli_query($conn, $insert_otp)) {
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

/**
 * VALIDATE OTP (Input: phone_number + otp)
 * PUT /account/otp.php
 * Body:
 * {
 *   "phone_number": "62812xxxxxxx",
 *   "otp": "123456"
 * }
 */
function validateOTP($conn, $input)
{
    $conn = DB::conn();

    $phone_number = $input['phone_number'] ?? null;
    $otp          = $input['otp'] ?? null;

    if (!$phone_number || !$otp) {
        jsonResponse(400, 'phone_number and otp are required');
    }

    $phone_number = mysqli_real_escape_string($conn, $phone_number);
    $otp          = mysqli_real_escape_string($conn, $otp);

    // Ambil username dari phone_number
    $user_query = "SELECT username, company_id, app_role_id 
        FROM movira_core_dev.app_user 
        WHERE phone_number = '$phone_number'
        LIMIT 1
    ";
    $user_result = mysqli_query($conn, $user_query);

    if (!$user_result) {
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($user_result) !== 1) {
        jsonResponse(404, 'User not found');
    }

    $user = mysqli_fetch_assoc($user_result);
    $username   = $user['username'];
    $company_id = $user['company_id'];
    $role       = $user['app_role_id'];

    // Tambah attempt_count untuk OTP aktif user ini
    $inc_attempt = "UPDATE otp_codes
        SET attempt_count = attempt_count + 1
        WHERE identifier = '$username'
          AND purpose = 'login'
          AND is_used = 0
    ";
    mysqli_query($conn, $inc_attempt);

    // Ambil OTP terbaru yang masih aktif
    $otp_query = "SELECT * FROM otp_codes
        WHERE identifier = '$username'
          AND otp_code = '$otp'
          AND purpose = 'login'
          AND is_used = 0
        ORDER BY created_at DESC
        LIMIT 1
    ";
    $otp_result = mysqli_query($conn, $otp_query);

    if (!$otp_result) {
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($otp_result) !== 1) {
        jsonResponse(400, 'Invalid OTP');
    }

    $otp_row = mysqli_fetch_assoc($otp_result);

    // Cek expired
    if (strtotime($otp_row['expire_at']) < time()) {
        jsonResponse(400, 'OTP expired');
    }

    // Tandai OTP sebagai sudah dipakai
    $mark_used = "UPDATE otp_codes
        SET is_used = 1,
            used_at = NOW()
        WHERE otp_id = '{$otp_row['otp_id']}'
        LIMIT 1
    ";
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

        case 'GET':
            jsonResponse(500, 'Under development', ['message' => 'GET is not implemented for OTP']);
            break;

        case 'DELETE':
            jsonResponse(500, 'Under development', ['message' => 'DELETE is not implemented for OTP']);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
            break;
    }
} catch (Exception $e) {
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>