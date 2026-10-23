<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once __DIR__ . '/account_rules.php';

use Firebase\JWT\JWT;

function getUserProfile($conn, $username) {
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $stmt = $conn->prepare("SELECT user_id, username, account_status, app_id, app_role_id, company_id,
                     phone_number, first_name, language, email, created_at, updated_at
                     FROM {$coreSchema}.app_user
                     WHERE username = ?
                     LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if (!$result) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/account/profile.php',
            'method'          => 'GET',
            'error_message'   => 'DB query failed',
            'user_identifier' => $username,
        ]);
        jsonResponse(500, 'Internal Server Error');
    }

    if ($result->num_rows === 0) {
        jsonResponse(404, 'User not found');
    }

    $user = $result->fetch_assoc();

    jsonResponse(200, 'User profile', $user);
}

// PATCH: first_name, language change freely; username, email and phone_number need
// current_password. Only the fields sent are touched. A username change returns a fresh
// token, because the JWT (and created_by on sessions/transactions) carries the username.
function updateUserProfile($conn, $decoded, $input) {
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $tokenUsername = $decoded->username;

    $stmt = $conn->prepare("SELECT user_id, username, password, app_id, first_name, phone_number, email, language
                            FROM {$coreSchema}.app_user
                            WHERE username = ?
                            LIMIT 1");
    $stmt->bind_param('s', $tokenUsername);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$current) {
        jsonResponse(404, 'User not found');
    }

    $validated = accountValidateProfileUpdate($input, $current);
    if (isset($validated['error'])) {
        jsonResponse(400, $validated['error']);
    }
    $changes = $validated['changes'];

    if ($validated['needs_password']) {
        $password = is_array($input) ? ($input['current_password'] ?? '') : '';
        if (!is_string($password) || $password === '') {
            jsonResponse(400, 'Password saat ini wajib diisi untuk mengubah username, email, atau nomor HP.');
        }
        // login.php escapes the password before password_verify(), so it is checked the same way.
        if (!password_verify(mysqli_real_escape_string($conn, $password), $current['password'])) {
            logApiError($conn, [
                'error_level'     => 'warning',
                'http_status'     => 403,
                'endpoint'        => '/account/profile.php',
                'method'          => 'PATCH',
                'error_message'   => 'Wrong current_password on profile update',
                'user_identifier' => $tokenUsername,
            ]);
            // 403, not 401: the token is fine, and 401 would sign the app out.
            jsonResponse(403, 'Password saat ini salah');
        }
    }

    if (isset($changes['username'])) {
        // Usernames stay unique across every app (see register.php).
        $newUsername = $changes['username'];
        $stmt = $conn->prepare("SELECT user_id FROM {$coreSchema}.app_user WHERE username = ? AND user_id <> ? LIMIT 1");
        $stmt->bind_param('ss', $newUsername, $current['user_id']);
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($taken) {
            jsonResponse(409, 'Username sudah dipakai');
        }
    }

    if (isset($changes['phone_number'])) {
        // Phone numbers stay unique within RAKI (see register.php).
        $variants = accountPhoneVariants($changes['phone_number']);
        $stmt = $conn->prepare("SELECT user_id FROM {$coreSchema}.app_user WHERE app_id = ? AND user_id <> ? AND phone_number IN (?, ?, ?) LIMIT 1");
        $stmt->bind_param('sssss', $current['app_id'], $current['user_id'], $variants[0], $variants[1], $variants[2]);
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if ($taken) {
            jsonResponse(409, 'Nomor HP sudah terdaftar');
        }
    }

    // Column names come from accountValidateProfileUpdate's fixed set, never from the client.
    $assignments = implode(', ', array_map(fn($col) => "$col = ?", array_keys($changes)));
    $stmt = $conn->prepare("UPDATE {$coreSchema}.app_user SET $assignments, updated_at = NOW() WHERE user_id = ?");
    $values = array_values($changes);
    $values[] = $current['user_id'];
    $stmt->bind_param(str_repeat('s', count($values)), ...$values);

    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        // Lost a race with a concurrent change to the same username.
        if ($e->getCode() === 1062) {
            jsonResponse(409, 'Username sudah dipakai');
        }
        throw $e;
    }
    $stmt->close();

    $response = ['changed' => array_keys($changes)];

    if (isset($changes['username'])) {
        // Same claims and the same expiry as the token being replaced.
        $payload = [
            'iat'        => time(),
            'exp'        => $decoded->exp,
            'username'   => $changes['username'],
            'company_id' => $decoded->company_id ?? null,
            'role'       => $decoded->role ?? null,
        ];
        $response['token']      = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');
        $response['expires_in'] = $decoded->exp;
    }

    $stmt = $conn->prepare("SELECT user_id, username, account_status, app_id, app_role_id, company_id,
                     phone_number, first_name, language, email, created_at, updated_at
                     FROM {$coreSchema}.app_user
                     WHERE user_id = ?
                     LIMIT 1");
    $stmt->bind_param('s', $current['user_id']);
    $stmt->execute();
    $response['user'] = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    jsonResponse(200, 'Profil berhasil diperbarui', $response);
}

try {
    $decoded = requireAuth();
    $conn = DB::conn();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            getUserProfile($conn, $decoded->username);
            break;
        case 'PATCH':
            updateUserProfile($conn, $decoded, json_decode(file_get_contents('php://input'), true));
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
        'endpoint'        => '/account/profile.php',
        'method'          => '',
        'error_message'   => $e->getMessage(),
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
