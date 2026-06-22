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

if (!$isCli && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, 'Method Not Allowed');
}

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 401,
        'endpoint'      => '/digital/purchase.php',
        'method'        => 'POST',
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

$conn   = DB::conn();
$schema = DB_SCHEMA;

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonResponse(400, 'Invalid JSON body');
}

// Validate required fields
foreach (['product_code', 'msisdn', 'company_id'] as $field) {
    if (empty($input[$field])) {
        jsonResponse(400, "$field is required");
    }
}

$productCode = cleanInput($conn, $input['product_code']);
$msisdn      = cleanInput($conn, $input['msisdn']);
$companyId   = cleanInput($conn, $input['company_id']);

$clientTrxId = 'MVR-' . strtoupper(bin2hex(random_bytes(8))) . '-' . time();
$now         = getCurrentDateTimeJakarta();

// Insert PENDING record before calling GV — never lose a transaction
$insertQuery = "INSERT INTO {$schema}.payment_transaction
    (client_transaction_id, product_code, msisdn, status, created_at, updated_at)
    VALUES ('$clientTrxId', '$productCode', '$msisdn', 'PENDING', '$now', '$now')";

if (!mysqli_query($conn, $insertQuery)) {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/digital/purchase.php',
        'method'          => 'POST',
        'error_message'   => 'Failed to insert transaction: ' . mysqli_error($conn),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $companyId,
    ]);
    jsonResponse(500, 'Failed to initiate transaction');
}

try {
    $gv       = new GrosirVoucher();
    $gvResult = $gv->purchase($productCode, $msisdn, $clientTrxId);

    $gvStatus     = $gvResult['status']          ?? '';
    $serverTrxId  = $gvResult['server_trx_id']   ?? ($gvResult['serverTrxId'] ?? null);
    $statusCode   = $gvResult['status_code']      ?? ($gvResult['statusCode']  ?? null);
    $message      = $gvResult['message']          ?? null;

    // Map GV status to internal status
    if (in_array($gvStatus, ['00', 'SUCCESS'])) {
        $internalStatus = 'SUCCESS';
    } elseif (in_array($gvStatus, ['PENDING', 'PROCESS'])) {
        $internalStatus = 'PENDING';
    } else {
        $internalStatus = 'FAILED';
    }

    $serverTrxIdEsc    = $serverTrxId  ? mysqli_real_escape_string($conn, $serverTrxId)  : 'NULL';
    $statusCodeEsc     = $statusCode   ? "'" . mysqli_real_escape_string($conn, $statusCode) . "'"  : 'NULL';
    $messageEsc        = $message      ? "'" . mysqli_real_escape_string($conn, $message)    . "'"  : 'NULL';
    $internalStatusEsc = mysqli_real_escape_string($conn, $internalStatus);
    $serverTrxIdSql    = $serverTrxId  ? "'" . mysqli_real_escape_string($conn, $serverTrxId) . "'" : 'NULL';
    $nowUpd            = getCurrentDateTimeJakarta();

    mysqli_query($conn, "UPDATE {$schema}.payment_transaction SET
        server_transaction_id = $serverTrxIdSql,
        status                = '$internalStatusEsc',
        status_code           = $statusCodeEsc,
        message               = $messageEsc,
        updated_at            = '$nowUpd'
        WHERE client_transaction_id = '$clientTrxId'");

    jsonResponse(200, 'Purchase initiated', [
        'client_trx_id' => $clientTrxId,
        'status'        => $internalStatus,
        'gv_response'   => $gvResult,
    ]);

} catch (Exception $e) {
    $nowUpd = getCurrentDateTimeJakarta();
    $errMsg = mysqli_real_escape_string($conn, $e->getMessage());

    mysqli_query($conn, "UPDATE {$schema}.payment_transaction SET
        status     = 'ERROR',
        message    = '$errMsg',
        updated_at = '$nowUpd'
        WHERE client_transaction_id = '$clientTrxId'");

    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/digital/purchase.php',
        'method'          => 'POST',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $companyId,
    ]);
    jsonResponse(500, 'Purchase failed', [
        'client_trx_id' => $clientTrxId,
        'error'         => $e->getMessage(),
    ]);
}
