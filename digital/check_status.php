<?php

require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../log.php';
require_once __DIR__ . '/../connection/grosirvoucher.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$isCli = php_sapi_name() === 'cli';

if (!$isCli && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, 'Method Not Allowed');
}

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 401,
        'endpoint'      => '/digital/check_status.php',
        'method'        => 'GET',
        'error_message' => 'Authorization header not found',
    ]);
    jsonResponse(401, 'Authorization header not found');
}

try {
    $token   = str_replace('Bearer ', '', $headers['Authorization']);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

} catch (Exception $e) {
    jsonResponse(401, 'Unauthorized', ['error' => $e->getMessage()]);
}

$conn         = DB::conn();
$schema       = DB_SCHEMA;
$clientTrxId  = !$isCli ? trim($_GET['client_trx_id'] ?? '') : '';

if ($clientTrxId === '') {
    jsonResponse(400, 'client_trx_id is required');
}

$clientTrxIdEsc = mysqli_real_escape_string($conn, $clientTrxId);
$row = mysqli_fetch_assoc(
    mysqli_query($conn, "SELECT * FROM {$schema}.payment_transaction WHERE client_transaction_id = '$clientTrxIdEsc' LIMIT 1")
);

if (!$row) {
    jsonResponse(404, 'Transaction not found');
}

// Terminal statuses — return DB row directly, no need to re-call GV
$terminalStatuses = ['SUCCESS', 'FAILED', 'ERROR'];
if (in_array($row['status'], $terminalStatuses)) {
    jsonResponse(200, 'Transaction status retrieved', $row);
}

// Status is still PENDING — re-check with GV and update DB
try {
    $gv       = new GrosirVoucher();
    $gvResult = $gv->checkStatus($clientTrxId);

    $gvStatus    = $gvResult['status']        ?? '';
    $serverTrxId = $gvResult['server_trx_id'] ?? ($gvResult['serverTrxId'] ?? null);
    $statusCode  = $gvResult['status_code']   ?? ($gvResult['statusCode']  ?? null);
    $sn          = $gvResult['sn']            ?? null;
    $message     = $gvResult['message']       ?? null;

    if (in_array($gvStatus, ['00', 'SUCCESS'])) {
        $internalStatus = 'SUCCESS';
    } elseif (in_array($gvStatus, ['PENDING', 'PROCESS'])) {
        $internalStatus = 'PENDING';
    } else {
        $internalStatus = 'FAILED';
    }

    $internalStatusEsc = mysqli_real_escape_string($conn, $internalStatus);
    $serverTrxIdSql    = $serverTrxId ? "'" . mysqli_real_escape_string($conn, $serverTrxId) . "'" : 'NULL';
    $statusCodeSql     = $statusCode  ? "'" . mysqli_real_escape_string($conn, $statusCode)  . "'" : 'NULL';
    $snSql             = $sn          ? "'" . mysqli_real_escape_string($conn, $sn)           . "'" : 'NULL';
    $messageSql        = $message     ? "'" . mysqli_real_escape_string($conn, $message)      . "'" : 'NULL';
    $nowUpd            = getCurrentDateTimeJakarta();

    mysqli_query($conn, "UPDATE {$schema}.payment_transaction SET
        status                = '$internalStatusEsc',
        server_transaction_id = $serverTrxIdSql,
        status_code           = $statusCodeSql,
        sn                    = $snSql,
        message               = $messageSql,
        updated_at            = '$nowUpd'
        WHERE client_transaction_id = '$clientTrxIdEsc'");

    $row['status']                = $internalStatus;
    $row['server_transaction_id'] = $serverTrxId;
    $row['status_code']           = $statusCode;
    $row['sn']                    = $sn;
    $row['message']               = $message;
    $row['updated_at']            = $nowUpd;

    jsonResponse(200, 'Transaction status retrieved', $row);

} catch (Exception $e) {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/digital/check_status.php',
        'method'          => 'GET',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    // Return cached DB row on GV call failure
    jsonResponse(200, 'Transaction status retrieved (cached)', $row);
}
