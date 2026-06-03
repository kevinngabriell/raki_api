<?php

/*
 * Run once to create the required table:
 *
 * CREATE TABLE IF NOT EXISTS raki_dev.user_notification_settings (
 *     id               INT AUTO_INCREMENT PRIMARY KEY,
 *     username         VARCHAR(255) NOT NULL UNIQUE,
 *     general_notif    TINYINT(1)  NOT NULL DEFAULT 1,
 *     updated_at       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP
 *                                          ON UPDATE CURRENT_TIMESTAMP
 * );
 */

require_once('../connection/db.php');
require_once('../vendor/autoload.php');
require_once('../general.php');
require_once('../config.php');
require_once('../log.php');

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function getNotifSettings($conn, $username, $schema) {
    $username = mysqli_real_escape_string($conn, $username);

    $result = mysqli_query(
        $conn,
        "SELECT general_notif FROM {$schema}.user_notification_settings
         WHERE username = '$username' LIMIT 1"
    );

    if (!$result) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/notification/settings.php',
            'method'          => 'GET',
            'error_message'   => mysqli_error($conn),
            'user_identifier' => $username,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($result) === 0) {
        // default: all notifications on
        jsonResponse(200, 'Notification settings', ['general_notif' => true]);
    }

    $row = mysqli_fetch_assoc($result);
    jsonResponse(200, 'Notification settings', [
        'general_notif' => (bool) $row['general_notif'],
    ]);
}

function updateNotifSettings($conn, $username, $schema, array $input) {
    if (!array_key_exists('general_notif', $input)) {
        jsonResponse(400, 'Missing required field: general_notif');
    }

    $generalNotif = (int)(bool) $input['general_notif'];
    $username     = mysqli_real_escape_string($conn, $username);

    $sql = "INSERT INTO {$schema}.user_notification_settings (username, general_notif)
            VALUES ('$username', $generalNotif)
            ON DUPLICATE KEY UPDATE general_notif = $generalNotif";

    if (!mysqli_query($conn, $sql)) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/notification/settings.php',
            'method'          => 'POST',
            'error_message'   => mysqli_error($conn),
            'user_identifier' => $username,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'DB error', ['error' => mysqli_error($conn)]);
    }

    jsonResponse(200, 'Notification settings updated', [
        'general_notif' => (bool) $generalNotif,
    ]);
}

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 401,
        'endpoint'        => '/notification/settings.php',
        'method'          => '',
        'error_message'   => 'Authorization header not found',
        'user_identifier' => null,
        'company_id'      => null,
    ]);
    jsonResponse(401, 'Authorization header not found');
}

try {
    $token   = str_replace('Bearer ', '', $headers['Authorization']);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn   = DB::conn();
    $schema = DB_SCHEMA;
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            getNotifSettings($conn, $decoded->username, $schema);
            break;
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true) ?? [];
            updateNotifSettings($conn, $decoded->username, $schema, $input);
            break;
        default:
            logApiError($conn, [
                'error_level'     => 'error',
                'http_status'     => 405,
                'endpoint'        => '/notification/settings.php',
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
        'endpoint'        => '/notification/settings.php',
        'method'          => '',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>
