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
        'endpoint'      => '/digital/check_balance.php',
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

if (($decoded->role ?? '') !== 'admin') {
    jsonResponse(403, 'Forbidden: admin access required');
}

try {
    $gv       = new GrosirVoucher();
    $response = $gv->checkBalance();

    jsonResponse(200, 'Balance retrieved', $response);

} catch (Exception $e) {
    $conn = DB::conn();
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/digital/check_balance.php',
        'method'          => 'GET',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Failed to fetch balance', ['error' => $e->getMessage()]);
}
