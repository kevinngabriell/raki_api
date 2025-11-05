<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

function createTransaction($conn, $input, $username){
    // Basic validation
    if (!$input || !isset($input['company_id']) || !isset($input['items']) || !is_array($input['items']) || count($input['items']) === 0) {
        jsonResponse(400, 'Invalid payload. Require company_id and non-empty items array.');
    }

    $company_id = $input['company_id'];
    $transaction_date = isset($input['transaction_date']) && !empty($input['transaction_date'])
        ? $input['transaction_date']
        : date('Y-m-d H:i:s');

    // Items structure: [{ menu_id, quantity, unit_price }]
    $items = $input['items'];

    // Compute totals and validate each item
    $total_amount = 0;
    $prepared_items = [];

    foreach ($items as $idx => $it) {
        if (!isset($it['menu_id']) || !isset($it['quantity']) || !isset($it['unit_price'])) {
            jsonResponse(400, "Invalid item at index $idx. Require menu_id, quantity, unit_price.");
        }
        $menu_id = $it['menu_id'];
        $quantity = (int)$it['quantity'];
        $unit_price = (float)$it['unit_price'];
        if ($quantity <= 0 || $unit_price < 0) {
            jsonResponse(400, "Invalid item values at index $idx.");
        }
        $subtotal = $quantity * $unit_price;
        $total_amount += $subtotal;
        $prepared_items[] = [
            'menu_id' => $menu_id,
            'quantity' => $quantity,
            'subtotal' => $subtotal,
        ];
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Generate IDs
        $transaction_id = 'trx' . uniqid();

        // Insert into `transaction` (header)
        $sqlHeader = "INSERT INTO raki_dev.transaction (transaction_id, company_id, transaction_date, total_amount, created_at, created_by, updated_at, updated_by)
                       VALUES (?, ?, ?, ?, NOW(), ?, NOW(), ?)";
        $stmtHeader = $conn->prepare($sqlHeader);
        if (!$stmtHeader) {
            throw new Exception('Prepare header failed: ' . $conn->error);
        }
        $stmtHeader->bind_param('sssiss', $transaction_id, $company_id, $transaction_date, $total_amount, $username, $username);
        if (!$stmtHeader->execute()) {
            throw new Exception('Execute header failed: ' . $stmtHeader->error);
        }

        // Insert details
        $sqlDetail = "INSERT INTO raki_dev.transaction_detail (detail_id, transaction_id, menu_id, quantity, subtotal, created_at)
                      VALUES (?, ?, ?, ?, ?, NOW())";
        $stmtDetail = $conn->prepare($sqlDetail);
        if (!$stmtDetail) {
            throw new Exception('Prepare detail failed: ' . $conn->error);
        }

        $response_items = [];
        foreach ($prepared_items as $pi) {
            $detail_id = 'trd' . uniqid();
            $menu_id = $pi['menu_id'];
            $qty = $pi['quantity'];
            $subtotal = $pi['subtotal'];
            $stmtDetail->bind_param('sssii', $detail_id, $transaction_id, $menu_id, $qty, $subtotal);
            if (!$stmtDetail->execute()) {
                throw new Exception('Execute detail failed: ' . $stmtDetail->error);
            }
            $response_items[] = [
                'detail_id' => $detail_id,
                'menu_id' => $menu_id,
                'quantity' => $qty,
                'subtotal' => $subtotal,
            ];
        }

        // Commit
        $conn->commit();

        jsonResponse(201, 'Transaction created', [
            'transaction_id' => $transaction_id,
            'company_id' => $company_id,
            'transaction_date' => $transaction_date,
            'total_amount' => $total_amount,
            'items' => $response_items,
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        jsonResponse(500, 'Failed to create transaction', ['error' => $e->getMessage()]);
    }
}

function getDetailTransaction($conn, $trx_id){
    if (!$trx_id) {
        jsonResponse(400, 'trx_id is required');
    }

    $sql = "SELECT 
                t.transaction_id,
                t.company_id,
                t.transaction_date,
                t.total_amount,
                t.created_at,
                t.created_by,
                t.updated_at,
                t.updated_by,
                td.detail_id,
                td.menu_id,
                m.menu_name,
                td.quantity,
                td.subtotal
            FROM raki_dev.transaction t
            JOIN raki_dev.transaction_detail td ON td.transaction_id = t.transaction_id
            LEFT JOIN raki_dev.menu m ON m.menu_id = td.menu_id
            WHERE t.transaction_id = ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        jsonResponse(500, 'Failed to prepare statement', ['error' => $conn->error]);
    }

    $stmt->bind_param('s', $trx_id);
    if (!$stmt->execute()) {
        jsonResponse(500, 'Failed to execute statement', ['error' => $stmt->error]);
    }

    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        jsonResponse(404, 'Transaction not found');
    }

    $header = null;
    $items = [];
    while ($row = $result->fetch_assoc()) {
        if ($header === null) {
            $header = [
                'transaction_id' => $row['transaction_id'],
                'company_id' => $row['company_id'],
                'transaction_date' => $row['transaction_date'],
                'total_amount' => (float)$row['total_amount'],
                'created_at' => $row['created_at'],
                'created_by' => $row['created_by'],
                'updated_at' => $row['updated_at'],
                'updated_by' => $row['updated_by'],
            ];
        }
        $items[] = [
            'detail_id' => $row['detail_id'],
            'menu_id' => $row['menu_id'],
            'menu_name' => $row['menu_name'],
            'quantity' => (int)$row['quantity'],
            'subtotal' => (float)$row['subtotal'],
        ];
    }

    jsonResponse(200, 'Transaction detail fetched', [
        'transaction' => $header,
        'items' => $items,
    ]);
}

function getAllTransaction($conn, $company_id, $page = 1, $limit = 10){
    // company_id is now mandatory
    if (!$company_id) {
        jsonResponse(400, 'company_id is required');
    }

    $offset = ($page - 1) * $limit;

    $sql = "SELECT t.transaction_id, t.company_id, t.transaction_date, t.total_amount, t.created_at, t.created_by, t.updated_at, t.updated_by
            FROM raki_dev.transaction t
            WHERE t.company_id = ?
            ORDER BY t.transaction_date DESC
            LIMIT ?, ?";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        jsonResponse(500, 'Failed to prepare statement');
    }
    $stmt->bind_param('sii', $company_id, $offset, $limit);
    if (!$stmt->execute()) {
        jsonResponse(500, 'Failed to execute statement');
    }
    $result = $stmt->get_result();
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }
    jsonResponse(200, 'Success', ['transactions' => $transactions]);
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
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

    $token_username = $decoded->username;
    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'POST':
            if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
                // Not fatal, but helps clients send correct header
                // continue anyway as we read raw body
            }
            $input = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                jsonResponse(400, 'Invalid JSON body');
            }
            createTransaction($conn, $input, $token_username);
            break;
        case 'GET':
            $company_id = $_GET['company_id'] ?? null;
            $page = $_GET['page'] ?? 1;
            $limit = $_GET['limit'] ?? 10;
            $trx_id = $_GET['trx_id'] ?? null;
            if($trx_id != null){
                getDetailTransaction($conn, $trx_id);
            } else {
                getAllTransaction($conn, $company_id, $page, $limit);
            }
            break;
        case 'PUT':
            break;
        case 'DELETE':
            break;
    }

} catch (Exception $e){
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>