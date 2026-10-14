<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function calcProfileCompletion(array $user): int {
    $fields = ['first_name', 'phone_number', 'email', 'language'];
    $filled = 0;
    foreach ($fields as $f) {
        if (!empty($user[$f]) && $user[$f] !== '-') {
            $filled++;
        }
    }
    return (int) round(($filled / count($fields)) * 100);
}

function getMemberProfile($conn, $username) {
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $stmt = $conn->prepare("SELECT username, first_name, phone_number, email, language
              FROM {$coreSchema}.app_user
              WHERE username = ?
              LIMIT 1");

    if (!$stmt) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/member/profile.php',
            'method'          => 'GET',
            'error_message'   => 'Failed to prepare statement for member profile',
            'user_identifier' => $username,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Failed to prepare statement');
    }

    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        jsonResponse(404, 'User not found');
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    jsonResponse(200, 'Member profile', [
        'username'               => $user['username'],
        'display_name'           => $user['first_name'] ?? '',
        'phone_number'           => $user['phone_number'] ?? '',
        'email'                  => $user['email'] ?? '',
        'language'               => $user['language'] ?? '',
        'profile_completion_pct' => calcProfileCompletion($user),
        'support_wa_number'      => $_ENV['SUPPORT_WA_NUMBER'] ?? '6285121951466',
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $decoded = requireAuth();

    $conn   = DB::conn();
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            getMemberProfile($conn, $decoded->username);
            break;
        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/member/profile.php',
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
        'endpoint'        => '/member/profile.php',
        'method'          => '',
        'error_message'   => $e->getMessage(),
        'user_identifier' => null,
        'company_id'      => null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
