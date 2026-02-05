<?php

require_once 'connection/db.php';
require_once 'vendor/autoload.php';
require_once 'general.php';
require_once 'config.php';
require_once 'notification/notification.php';

function logApiError(
    mysqli $conn,
    array $params
) {
    /*
    Required keys:
    - http_status (int)
    - endpoint (string)
    - error_message (string)

    Optional:
    - error_level (critical|error|warning)
    - method
    - file
    - line
    - user_identifier
    - company_id
    - request_id
    */

    $error_id = 'ERR' . uniqid();
    $error_level = $params['error_level'] ?? 'error';
    $http_status = (int)$params['http_status'];
    $endpoint = $params['endpoint'];
    $method = $params['method'] ?? $_SERVER['REQUEST_METHOD'] ?? null;
    $error_message = $params['error_message'];

    $file = $params['file'] ?? null;
    $line = $params['line'] ?? null;
    $user_identifier = $params['user_identifier'] ?? null;
    $company_id = $params['company_id'] ?? null;
    $request_id = $params['request_id'] ?? null;

    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

    $sql = "INSERT INTO raki_dev.api_error_log (error_id, error_level, http_status, endpoint, method, error_message, file, line, user_identifier, company_id, request_id, ip_address, user_agent, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        // jangan bikin infinite loop error logging
        return false;
    }

    $stmt->bind_param(
        'ssissssisisss',
        $error_id,
        $error_level,
        $http_status,
        $endpoint,
        $method,
        $error_message,
        $file,
        $line,
        $user_identifier,
        $company_id, $request_id, $ip_address, $user_agent
    );

    $stmt->execute();
    $stmt->close();

    return $error_id;
}

?>