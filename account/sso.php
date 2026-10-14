<?php

// POST /account/sso.php   Body: { "assertion": "<from Movira Core POST /auth/sso/assertion>" }
//
// Single sign-on entry. Replaces typing a password: Movira Core already authenticated the person.
// Issues the SAME token as account/login.php (same claims, same 8 h lifetime) and applies the
// same account-status rules, so nothing else in this API changes.
// See movira_api/docs/SSO_Implementation_Notes.md.

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once __DIR__ . '/account_rules.php';
require_once '../sso/MoviraSso.php';

use Firebase\JWT\JWT;

function sso_login($conn, $input){
    $conn = DB::conn();

    $assertion = trim((string)($input['assertion'] ?? ''));
    if ($assertion === '') {
        jsonResponse(422, 'assertion is required');
    }

    // RAKI's app_id — same literal as account/login.php.
    $coreSchema = MOVIRA_CORE_SCHEMA;

    try {
        $claims = movira_sso_verify($assertion, ACCOUNT_RAKI_APP_ID);
        if (!empty($claims['sub']) && is_string($claims['sub'])) {
            RequestLogger::setActor(['username' => $claims['sub'], 'company_id' => null]);
        }
        movira_sso_consume_jti($conn, $coreSchema, $claims);
        $row    = movira_sso_load_user($conn, $coreSchema, $claims);
    } catch (MoviraSsoException $e) {
        // A failing logger must never change the verdict (401 must not become 500).
        try {
            logApiError($conn, [
                'error_level'   => $e->httpStatus() >= 500 ? 'error' : 'warning',
                'http_status'   => $e->httpStatus(),
                'endpoint'      => '/account/sso.php',
                'method'        => 'POST',
                'error_message' => 'SSO rejected: ' . $e->getMessage(),
                'user_identifier' => null,
                'company_id'      => null,
            ]);
        } catch (Throwable $logError) {
            error_log('[sso] could not write api error log: ' . $logError->getMessage());
        }
        jsonResponse($e->httpStatus(), $e->getMessage());
    }

    // Same gate as login.php (movira_sso_load_user already requires account_status = 'verified').
    $blockedMessage = accountStatusBlockMessage($row['account_status'] ?? null);
    if ($blockedMessage !== null) {
        jsonResponse(403, $blockedMessage);
    }

    $issuedAt       = time();
    $expirationTime = $issuedAt + 8 * 60 * 60; // 8 jam — same as login.php

    $payload = [
        'iat' => $issuedAt,
        'exp' => $expirationTime,
        'username' => $row['username'],
        'company_id' => $row['company_id'],
        'role' => $row['app_role_id']
    ];

    $jwt = JWT::encode($payload, $_ENV['JWT_SECRET'], 'HS256');

    RequestLogger::setActor([
        'username'   => $row['username'],
        'company_id' => $row['company_id'],
    ]);

    Response::success([
        'token' => $jwt,
        'expires_in' => $expirationTime,
        'user' => $payload,
    ], null, 200, 'Login successful');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $conn = DB::conn();

    switch($method){
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            sso_login($conn, $input);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    error_log('[sso] unexpected error: ' . $e->getMessage());
    jsonResponse(500, 'Internal Server Error');
}

?>
