<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function getDriverNextBonus($conn, $schemaDB, $username, $company){

    // tentukan minggu ini
    $start = date('Y-m-d', strtotime('monday this week'));
    $end   = date('Y-m-d', strtotime('sunday this week'));

    // 1️⃣ total item minggu ini
    $trxQuery = "SELECT COALESCE(SUM(total_item),0) as total_item
        FROM {$schemaDB}.transaction
        WHERE created_by = '$username'
        AND transaction_date BETWEEN '$start' AND '$end'
    ";

    $trxResult = mysqli_query($conn, $trxQuery);
    $trxData = mysqli_fetch_assoc($trxResult);

    $current_total = (int)$trxData['total_item'];

    // 2️⃣ cari current bonus (tier yang sudah tercapai)
    $currentSchemaQuery = "SELECT *
        FROM {$schemaDB}.bonus_schema
        WHERE frequency = 'weekly'
        AND is_active = 1
        AND qty <= $current_total
        ORDER BY qty DESC
        LIMIT 1
    ";

    $currentSchemaResult = mysqli_query($conn, $currentSchemaQuery);
    $currentSchema = mysqli_num_rows($currentSchemaResult) > 0 ? mysqli_fetch_assoc($currentSchemaResult) : null;

    $current_bonus = null;
    if ($currentSchema) {
        $current_bonus = [
            'schema_id'     => $currentSchema['schema_id'],
            'schema_name'   => $currentSchema['schema_name'],
            'achieved_qty'  => $currentSchema['qty'],
            'bonus_nominal' => $currentSchema['bonus_nominal'],
        ];
    }

    // 3️⃣ cari next schema
    $schemaQuery = "SELECT *
        FROM {$schemaDB}.bonus_schema
        WHERE frequency = 'weekly'
        AND is_active = 1
        AND qty > $current_total
        ORDER BY qty ASC
        LIMIT 1
    ";

    $schemaResult = mysqli_query($conn, $schemaQuery);

    if (mysqli_num_rows($schemaResult) === 0) {
        jsonResponse(200, 'All bonus tiers completed 🎉', [
            'current_total_item' => $current_total,
            'current_bonus'      => $current_bonus,
            'next_target'        => null,
            'period' => [
                'start' => $start,
                'end'   => $end
            ]
        ]);
    }

    $nextSchema = mysqli_fetch_assoc($schemaResult);

    $remaining = $nextSchema['qty'] - $current_total;
    $percentage = round(($current_total / $nextSchema['qty']) * 100);

    jsonResponse(200, 'Driver next bonus target', [
        'current_total_item' => $current_total,
        'current_bonus'      => $current_bonus,
        'next_target' => [
            'schema_id'         => $nextSchema['schema_id'],
            'schema_name'       => $nextSchema['schema_name'],
            'target_qty'        => $nextSchema['qty'],
            'bonus_nominal'     => $nextSchema['bonus_nominal'],
            'remaining_item'    => $remaining,
            'progress_percentage' => min(100, $percentage)
        ],
        'period' => [
            'start' => $start,
            'end'   => $end
        ]
    ]);
}

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 401,
        'endpoint'      => '/account/company.php',
        'method'        => '',
        'error_message' => 'Authorization header not found',
        'user_identifier' => $username ?? null,
        'company_id'      => $company_id ?? null,
    ]);
    jsonResponse(401, 'Authorization header not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");
    http_response_code(200);
    exit();
}

try {
    $token = str_replace('Bearer ', '', $headers['Authorization']);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn = DB::conn();
    $schema = DB_SCHEMA;
    
    $token_username = $decoded->username;
    $token_company = $decoded->company_id;

    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'GET':
            getDriverNextBonus($conn, $schema, $token_username, $token_company);
            break;
        default: 
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 405,
                'endpoint'      => '/account/account.php',
                'method'        => $method,
                'error_message' => 'Method Not Allowed',
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/account/company.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}


?>