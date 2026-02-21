<?php

require_once '../../connection/db.php';
require_once '../../general.php';
require_once '../../config.php';
require_once '../../log.php';

$conn = DB::conn();
$schema = DB_SCHEMA;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$server_id   = $_GET['SERVERID'] ?? null;
$client_id   = $_GET['CLIENTID'] ?? null;
$status_code = $_GET['STATUSCODE'] ?? null;
$kp          = $_GET['KP'] ?? null;
$msisdn      = $_GET['MSISDN'] ?? null;
$sn          = $_GET['SN'] ?? null;
$msg         = $_GET['MSG'] ?? null;

if (!$server_id || !$client_id || !$status_code) {
    http_response_code(400);
    exit('Missing parameter');
}

$now = getCurrentDateTimeJakarta();

$queryString  = $_SERVER['QUERY_STRING'];
$queryEscaped = mysqli_real_escape_string($conn, $queryString);

mysqli_query($conn, "INSERT INTO {$schema}.payment_callback_log (endpoint, payload, created_at) VALUES ('/dev/callback/payment.php', '$queryEscaped', '$now')");

$status = 'FAILED';

$successCodes = ['00', '1'];
$pendingCodes = ['68', '0027'];

if (in_array($status_code, $successCodes)) {
    $status = 'SUCCESS';
} elseif (in_array($status_code, $pendingCodes)) {
    $status = 'PENDING';
}

$server_id_esc   = mysqli_real_escape_string($conn, $server_id);
$client_id_esc   = mysqli_real_escape_string($conn, $client_id);
$status_esc      = mysqli_real_escape_string($conn, $status);
$status_code_esc = mysqli_real_escape_string($conn, $status_code);
$sn_esc          = mysqli_real_escape_string($conn, $sn);
$msg_esc         = mysqli_real_escape_string($conn, $msg);

$checkQuery = "SELECT status FROM {$schema}.payment_transaction WHERE client_transaction_id = '$client_id_esc' LIMIT 1";

$result = mysqli_query($conn, $checkQuery);

if (mysqli_num_rows($result) == 0) {
    // transaksi tidak ditemukan
    http_response_code(200);
    exit('OK'); // tetap OK supaya tidak retry terus
}

$row = mysqli_fetch_assoc($result);

if ($row['status'] === 'SUCCESS') {
    // Sudah sukses, tidak boleh diubah lagi
    http_response_code(200);
    exit('OK');
}

$updateQuery = "UPDATE {$schema}.payment_transaction SET  server_transaction_id = '$server_id_esc', status = '$status_esc', status_code = '$status_code_esc', sn = '$sn_esc', message = '$msg_esc', updated_at = '$now' WHERE client_transaction_id = '$client_id_esc'";

mysqli_query($conn, $updateQuery);

http_response_code(200);
echo "OK";
exit();