<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function getNextQueueNumber($conn, $schema, $company_id, $username){
    if (!$company_id || trim($company_id) === '') {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 400,
            'endpoint'      => '/pos/queue-next.php',
            'method'        => 'POST',
            'error_message' => 'company_id is required',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(400, 'company_id is required');
    }

    // Atomic per-day increment: the (company_id, queue_date) primary key means today's
    // row doesn't exist yet the first time a company asks on a new date, so the counter
    // starts at 1 again automatically. CURDATE() is evaluated on the DB server so the
    // reset boundary follows the DB's timezone, not the app server's.
    // LAST_INSERT_ID(1) on the insert branch is required because this table has no
    // AUTO_INCREMENT column - without it, LAST_INSERT_ID() would stay 0 on a fresh
    // row and only get set correctly on the ON DUPLICATE KEY UPDATE branch.
    DB::query(
        "INSERT INTO {$schema}.pos_queue_counter (company_id, queue_date, counter)
         VALUES (?, CURDATE(), LAST_INSERT_ID(1))
         ON DUPLICATE KEY UPDATE counter = LAST_INSERT_ID(counter + 1)",
        [$company_id]
    );

    $result = DB::query("SELECT LAST_INSERT_ID() AS queue_number, CURDATE() AS queue_date");
    $row = mysqli_fetch_assoc($result);

    jsonResponse(200, 'Queue number issued', [
        'queue_number' => (int)$row['queue_number'],
        'queue_date'   => $row['queue_date'],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$decoded = requireAuth();

try {
    $conn = DB::conn();
    $schema = DB_SCHEMA;

    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                jsonResponse(400, 'Invalid JSON body');
            }

            $company_id = $input['company_id'] ?? ($decoded->company_id ?? null);

            getNextQueueNumber($conn, $schema, $company_id, $token_username);
            break;

        default:
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 405,
                'endpoint'      => '/pos/queue-next.php',
                'method'        => $method,
                'error_message' => 'Method Not Allowed',
                'user_identifier' => $token_username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
    }

} catch (Exception $e) {
    logApiError($conn ?? null, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/pos/queue-next.php',
        'method'        => $_SERVER['REQUEST_METHOD'] ?? null,
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
