<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function getDriverNextBonus($conn, $schemaDB, $username){

    $start = date('Y-m-d', strtotime('monday this week'));
    $end   = date('Y-m-d', strtotime('saturday this week'));

    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_item),0) as total_item
        FROM {$schemaDB}.transaction
        WHERE created_by = ?
        AND DATE(transaction_date) BETWEEN ? AND ?");
    $stmt->bind_param('sss', $username, $start, $end);
    $stmt->execute();
    $trxResult = $stmt->get_result();
    $stmt->close();

    $trxData = $trxResult->fetch_assoc();
    $current_total = (int)$trxData['total_item'];

    $stmt = $conn->prepare("SELECT schema_id, schema_name, qty, bonus_nominal
        FROM {$schemaDB}.bonus_schema
        WHERE frequency = 'weekly'
        AND is_active = 1
        AND qty <= ?
        ORDER BY qty DESC
        LIMIT 1");
    $stmt->bind_param('i', $current_total);
    $stmt->execute();
    $currentSchemaResult = $stmt->get_result();
    $stmt->close();

    $currentSchema = $currentSchemaResult->num_rows > 0 ? $currentSchemaResult->fetch_assoc() : null;

    $current_bonus = null;
    if ($currentSchema) {
        $current_bonus = [
            'schema_id'     => $currentSchema['schema_id'],
            'schema_name'   => $currentSchema['schema_name'],
            'achieved_qty'  => $currentSchema['qty'],
            'bonus_nominal' => $currentSchema['bonus_nominal'],
        ];
    }

    $stmt = $conn->prepare("SELECT schema_id, schema_name, qty, bonus_nominal
        FROM {$schemaDB}.bonus_schema
        WHERE frequency = 'weekly'
        AND is_active = 1
        AND qty > ?
        ORDER BY qty ASC
        LIMIT 1");
    $stmt->bind_param('i', $current_total);
    $stmt->execute();
    $schemaResult = $stmt->get_result();
    $stmt->close();

    if ($schemaResult->num_rows === 0) {
        jsonResponse(200, 'All bonus tiers completed', [
            'current_total_item' => $current_total,
            'current_bonus'      => $current_bonus,
            'next_target'        => null,
            'period' => [
                'start' => $start,
                'end'   => $end
            ]
        ]);
    }

    $nextSchema = $schemaResult->fetch_assoc();

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

try {
    $decoded = requireAuth();
    $conn = DB::conn();
    $schema = DB_SCHEMA;
    $token_username = $decoded->username;

    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'GET':
            getDriverNextBonus($conn, $schema, $token_username);
            break;
        default:
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/account/account.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
