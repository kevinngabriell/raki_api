<?php

require_once('../connection/db.php');
require_once('../vendor/autoload.php');
require_once('../general.php');
require_once('../config.php');
require_once('../log.php');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function getNotifSettings($conn, $username, $schema) {
    $stmt = $conn->prepare("SELECT general_notif FROM {$schema}.user_notification_settings WHERE username = ? LIMIT 1");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/notification/settings.php',
            'method'          => 'GET',
            'error_message'   => 'Database query error',
            'user_identifier' => $username,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if (!$result || $result->num_rows === 0) {
        jsonResponse(200, 'Notification settings', ['general_notif' => true]);
    }

    $row = $result->fetch_assoc();
    jsonResponse(200, 'Notification settings', [
        'general_notif' => (bool) $row['general_notif'],
    ]);
}

function updateNotifSettings($conn, $username, $schema, array $input) {
    if (!array_key_exists('general_notif', $input)) {
        jsonResponse(400, 'Missing required field: general_notif');
    }

    $generalNotif = (int)(bool) $input['general_notif'];

    $stmt = $conn->prepare("INSERT INTO {$schema}.user_notification_settings (username, general_notif) VALUES (?, ?) ON DUPLICATE KEY UPDATE general_notif = ?");
    if (!$stmt) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/notification/settings.php',
            'method'          => 'POST',
            'error_message'   => 'Database query error',
            'user_identifier' => $username,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Database query error');
    }
    $stmt->bind_param('sii', $username, $generalNotif, $generalNotif);

    if (!$stmt->execute()) {
        $stmt->close();
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/notification/settings.php',
            'method'          => 'POST',
            'error_message'   => 'Failed to update notification settings',
            'user_identifier' => $username,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'Failed to update notification settings');
    }
    $stmt->close();

    jsonResponse(200, 'Notification settings updated', [
        'general_notif' => (bool) $generalNotif,
    ]);
}

$decoded = requireAuth();

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
