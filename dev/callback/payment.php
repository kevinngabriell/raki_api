<?php

require_once __DIR__ . '/../../connection/db.php';
require_once __DIR__ . '/../../general.php';
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../log.php';

$isCli  = php_sapi_name() === 'cli';
$method = !$isCli ? $_SERVER['REQUEST_METHOD'] : 'CLI';

$conn   = DB::conn();
$schema = DB_SCHEMA;
$now    = getCurrentDateTimeJakarta();

// ── Existing: legacy payment callback (GET) ───────────────────────────────────
if ($method === 'GET') {

    $server_id   = $_GET['SERVERID']   ?? null;
    $client_id   = $_GET['CLIENTID']   ?? null;
    $status_code = $_GET['STATUSCODE'] ?? null;
    $sn          = $_GET['SN']         ?? null;
    $msg         = $_GET['MSG']        ?? null;

    if (!$server_id || !$client_id || !$status_code) {
        http_response_code(400);
        exit('Missing parameter');
    }

    $queryString  = $_SERVER['QUERY_STRING'];
    $queryEscaped = mysqli_real_escape_string($conn, $queryString);

    mysqli_query($conn, "INSERT INTO {$schema}.payment_callback_log (endpoint, payload, created_at)
        VALUES ('/dev/callback/payment.php', '$queryEscaped', '$now')");

    $status = 'FAILED';
    if (in_array($status_code, ['00', '1'])) {
        $status = 'SUCCESS';
    } elseif (in_array($status_code, ['68', '0027'])) {
        $status = 'PENDING';
    }

    $server_id_esc   = mysqli_real_escape_string($conn, $server_id);
    $client_id_esc   = mysqli_real_escape_string($conn, $client_id);
    $status_esc      = mysqli_real_escape_string($conn, $status);
    $status_code_esc = mysqli_real_escape_string($conn, $status_code);
    $sn_esc          = mysqli_real_escape_string($conn, $sn);
    $msg_esc         = mysqli_real_escape_string($conn, $msg);

    $result = mysqli_query($conn, "SELECT status FROM {$schema}.payment_transaction
        WHERE client_transaction_id = '$client_id_esc' LIMIT 1");

    if (mysqli_num_rows($result) == 0) {
        http_response_code(200);
        exit('OK');
    }

    $row = mysqli_fetch_assoc($result);
    if ($row['status'] === 'SUCCESS') {
        http_response_code(200);
        exit('OK');
    }

    mysqli_query($conn, "UPDATE {$schema}.payment_transaction SET
        server_transaction_id = '$server_id_esc',
        status                = '$status_esc',
        status_code           = '$status_code_esc',
        sn                    = '$sn_esc',
        message               = '$msg_esc',
        updated_at            = '$now'
        WHERE client_transaction_id = '$client_id_esc'");

    http_response_code(200);
    echo 'OK';
    exit;
}

// ── GrosirVoucher callback (POST) ─────────────────────────────────────────────
if ($method === 'POST') {

    $rawBody = file_get_contents('php://input');
    $ip      = !$isCli ? getUserIP() : 'cli';
    $ua      = !$isCli ? ($_SERVER['HTTP_USER_AGENT'] ?? '') : 'cli';

    // Log everything first — before any validation
    $rawEsc = mysqli_real_escape_string($conn, $rawBody);
    $ipEsc  = mysqli_real_escape_string($conn, $ip);
    $uaEsc  = mysqli_real_escape_string($conn, $ua);
    mysqli_query($conn, "INSERT INTO {$schema}.payment_callback_log
        (endpoint, payload, ip_address, user_agent, created_at)
        VALUES ('/dev/callback/payment.php', '$rawEsc', '$ipEsc', '$uaEsc', '$now')");

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        http_response_code(200);
        echo json_encode(['rc' => '00']);
        exit;
    }

    $clientTrxId = $payload['client_trx_id']  ?? ($payload['clientTrxId']  ?? null);
    $gvStatus    = $payload['status']          ?? null;
    $serverTrxId = $payload['server_trx_id']  ?? ($payload['serverTrxId']  ?? null);
    $sn          = $payload['sn']             ?? null;
    $statusCode  = $payload['status_code']    ?? ($payload['statusCode']   ?? null);
    $message     = $payload['message']        ?? null;

    if (!$clientTrxId) {
        http_response_code(200);
        echo json_encode(['rc' => '00']);
        exit;
    }

    $clientTrxIdEsc = mysqli_real_escape_string($conn, $clientTrxId);
    $result = mysqli_query($conn, "SELECT id, status FROM {$schema}.payment_transaction
        WHERE client_transaction_id = '$clientTrxIdEsc' LIMIT 1");

    if (mysqli_num_rows($result) === 0) {
        http_response_code(200);
        echo json_encode(['rc' => '00']);
        exit;
    }

    $row = mysqli_fetch_assoc($result);

    // Map GV status codes to internal statuses
    if (in_array($gvStatus, ['00', 'SUCCESS'])) {
        $internalStatus = 'SUCCESS';
    } elseif (in_array($gvStatus, ['PENDING', 'PROCESS'])) {
        $internalStatus = 'PENDING';
    } else {
        $internalStatus = 'FAILED';
    }

    // Never downgrade a terminal status (unless upgrading to SUCCESS)
    $terminalStatuses = ['SUCCESS', 'FAILED', 'ERROR'];
    if (in_array($row['status'], $terminalStatuses) && $internalStatus !== 'SUCCESS') {
        http_response_code(200);
        echo json_encode(['rc' => '00']);
        exit;
    }

    $internalStatusEsc = mysqli_real_escape_string($conn, $internalStatus);
    $serverTrxIdSql    = $serverTrxId ? "'" . mysqli_real_escape_string($conn, $serverTrxId) . "'" : 'NULL';
    $snSql             = $sn          ? "'" . mysqli_real_escape_string($conn, $sn)           . "'" : 'NULL';
    $statusCodeSql     = $statusCode  ? "'" . mysqli_real_escape_string($conn, $statusCode)   . "'" : 'NULL';
    $messageSql        = $message     ? "'" . mysqli_real_escape_string($conn, $message)       . "'" : 'NULL';
    $nowUpd            = getCurrentDateTimeJakarta();

    mysqli_query($conn, "UPDATE {$schema}.payment_transaction SET
        status                = '$internalStatusEsc',
        server_transaction_id = $serverTrxIdSql,
        sn                    = $snSql,
        status_code           = $statusCodeSql,
        message               = $messageSql,
        updated_at            = '$nowUpd'
        WHERE client_transaction_id = '$clientTrxIdEsc'");

    http_response_code(200);
    echo json_encode(['rc' => '00']);
    exit;
}

http_response_code(405);
exit('Method Not Allowed');
