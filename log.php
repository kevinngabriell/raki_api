<?php

require_once __DIR__ . '/connection/db.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/general.php';
require_once __DIR__ . '/config.php';

function logApiError($conn, array $params): ?string
{
    $httpStatus = (int)($params['http_status'] ?? 500);
    $endpoint   = $params['endpoint'] ?? '';
    $method     = $params['method'] ?? '';
    $errorMsg   = $params['error_message'] ?? '';
    $level      = $params['error_level'] ?? 'error';
    $username   = $params['user_identifier'] ?? null;
    $companyId  = $params['company_id'] ?? null;

    try {
        $errorId = 'ERR' . uniqid();
        $requestId = RequestLogger::requestId();
        $now = getCurrentDateTimeJakarta();

        $logDb = MOVIRA_CORE_SCHEMA;
        $conn = DB::conn($logDb);

        $stmt = $conn->prepare(
            "INSERT INTO log_request
                (request_id, timestamp, endpoint, http_method, status_code, outcome, level,
                 error_message, username, company_id, ip_address, user_agent, db_connected)
             VALUES
                (?, ?, ?, ?, ?, 'error', ?, ?, ?, ?, ?, ?, 1)"
        );

        $ip = getUserIP();
        $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

        $stmt->bind_param(
            'ssssissssss',
            $requestId,
            $now,
            $endpoint,
            $method,
            $httpStatus,
            $level,
            $errorMsg,
            $username,
            $companyId,
            $ip,
            $ua,
        );

        $stmt->execute();
        $stmt->close();

        return $errorId;
    } catch (Throwable $e) {
        error_log('[logApiError] Failed to write log: ' . $e->getMessage());
        return null;
    }
}
