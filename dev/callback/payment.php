<?php

require_once '../../connection/db.php';
require_once '../../general.php';
require_once '../../config.php';
require_once '../../log.php';

$conn = DB::conn();

/*
|--------------------------------------------------------------------------
| 0️⃣ Method Validation
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    exit('Method Not Allowed');
}

/*
|--------------------------------------------------------------------------
| 1️⃣ Ambil Parameter
|--------------------------------------------------------------------------
*/

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

/*
|--------------------------------------------------------------------------
| 2️⃣ Simpan Log Callback (WAJIB)
|--------------------------------------------------------------------------
*/

$queryString  = $_SERVER['QUERY_STRING'];
$queryEscaped = mysqli_real_escape_string($conn, $queryString);

mysqli_query($conn, "
    INSERT INTO raki_dev.payment_callback_log 
    (endpoint, payload, created_at)
    VALUES ('/dev/callback/payment.php', '$queryEscaped', '$now')
");

/*
|--------------------------------------------------------------------------
| 3️⃣ Mapping Status Berdasarkan Dokumentasi
|--------------------------------------------------------------------------
*/

$status = 'FAILED';

$successCodes = ['00', '1'];
$pendingCodes = ['68', '0027'];

if (in_array($status_code, $successCodes)) {
    $status = 'SUCCESS';
} elseif (in_array($status_code, $pendingCodes)) {
    $status = 'PENDING';
}

/*
|--------------------------------------------------------------------------
| 4️⃣ Escape Data
|--------------------------------------------------------------------------
*/

$server_id_esc   = mysqli_real_escape_string($conn, $server_id);
$client_id_esc   = mysqli_real_escape_string($conn, $client_id);
$status_esc      = mysqli_real_escape_string($conn, $status);
$status_code_esc = mysqli_real_escape_string($conn, $status_code);
$sn_esc          = mysqli_real_escape_string($conn, $sn);
$msg_esc         = mysqli_real_escape_string($conn, $msg);

/*
|--------------------------------------------------------------------------
| 5️⃣ Cek Apakah Transaksi Ada
|--------------------------------------------------------------------------
*/

$checkQuery = "
    SELECT status 
    FROM raki_dev.payment_transaction 
    WHERE client_transaction_id = '$client_id_esc'
    LIMIT 1
";

$result = mysqli_query($conn, $checkQuery);

if (mysqli_num_rows($result) == 0) {
    // transaksi tidak ditemukan
    http_response_code(200);
    exit('OK'); // tetap OK supaya tidak retry terus
}

$row = mysqli_fetch_assoc($result);

/*
|--------------------------------------------------------------------------
| 6️⃣ Anti Double Callback
|--------------------------------------------------------------------------
*/

if ($row['status'] === 'SUCCESS') {
    // Sudah sukses, tidak boleh diubah lagi
    http_response_code(200);
    exit('OK');
}

/*
|--------------------------------------------------------------------------
| 7️⃣ Update Transaksi
|--------------------------------------------------------------------------
*/

$updateQuery = "
    UPDATE raki_dev.payment_transaction
    SET 
        server_transaction_id = '$server_id_esc',
        status = '$status_esc',
        status_code = '$status_code_esc',
        sn = '$sn_esc',
        message = '$msg_esc',
        updated_at = '$now'
    WHERE client_transaction_id = '$client_id_esc'
";

mysqli_query($conn, $updateQuery);

/*
|--------------------------------------------------------------------------
| 8️⃣ Return Response Ke Provider
|--------------------------------------------------------------------------
*/

http_response_code(200);
echo "OK";
exit();