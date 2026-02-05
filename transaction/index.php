<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../notification/notification.php';

function createTransaction($conn, $input, $username){
    // Basic validation
    if (!$input || !isset($input['company_id']) || !isset($input['items']) || !is_array($input['items']) || count($input['items']) === 0) {
        jsonResponse(400, 'Invalid payload. Require company_id and non-empty items array.');
    }

    $company_id = $input['company_id'];
    $total_items = $input['total_items'];
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

        // --- Payment breakdown (cash / qris) ---
    $payments = $input['payments'] ?? null;

    if (!$payments || !is_array($payments) || count($payments) === 0) {
        jsonResponse(400, 'Invalid payload. Require payments array with at least one item.');
    }

    $allowed_methods = ['cash', 'qris']; // kalau nanti ada transfer, qris_midtrans tinggal tambahin
    $prepared_payments = [];
    $total_paid = 0;

    foreach ($payments as $idx => $p) {
        if (!isset($p['payment_method']) || !isset($p['amount'])) {
            jsonResponse(400, "Invalid payment at index $idx. Require payment_method and amount.");
        }

        $method = $p['payment_method'];
        $amount = (int)$p['amount'];

        if (!in_array($method, $allowed_methods, true)) {
            jsonResponse(400, "Invalid payment_method at index $idx.");
        }
        if ($amount <= 0) {
            jsonResponse(400, "Invalid payment amount at index $idx.");
        }

        $total_paid += $amount;
        $prepared_payments[] = [
            'payment_method' => $method,
            'amount' => $amount,
        ];
    }

    // pastikan total payment = total transaksi
    if ($total_paid !== (int)$total_amount) {
        jsonResponse(400, "Total payment (cash + qris) must equal total_amount. total_paid=$total_paid, total_amount=$total_amount");
    }

    // Start transaction
    $conn->begin_transaction();

    try {
        // Generate IDs
        $transaction_id = 'trx' . uniqid();

        // Insert into `transaction` (header)
        $sqlHeader = "INSERT INTO raki_dev.transaction (transaction_id, company_id, transaction_date, total_amount, created_at, created_by, updated_at, updated_by, total_item)
                       VALUES (?, ?, ?, ?, NOW(), ?, NOW(), ?, ?)";
        $stmtHeader = $conn->prepare($sqlHeader);
        if (!$stmtHeader) {
            throw new Exception('Prepare header failed: ' . $conn->error);
        }
        $stmtHeader->bind_param('sssissi', $transaction_id, $company_id, $transaction_date, $total_amount, $username, $username, $total_items);
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

        // Insert payment breakdown ke transaction_payment_daily
        $sqlPayment = "INSERT INTO raki_dev.transaction_payment
                       (payment_id, transaction_id, payment_method, amount, company_id, created_at)
                       VALUES (?, ?, ?, ?, ?, NOW())";
        $stmtPayment = $conn->prepare($sqlPayment);
        if (!$stmtPayment) {
            throw new Exception('Prepare payment failed: ' . $conn->error);
        }

        foreach ($prepared_payments as $pay) {
            $payment_id = 'pay' . uniqid();
            $method = $pay['payment_method'];
            $amount = $pay['amount'];

            $stmtPayment->bind_param('sssii', $payment_id, $transaction_id, $method, $amount, $company_id);
            if (!$stmtPayment->execute()) {
                throw new Exception('Execute payment failed: ' . $stmtPayment->error);
            }
        }

        // compute total cups (exclude certain menu IDs, e.g. non-cup items)
        $total_cups = 0;
        $excluded_menu_ids = ['menu696797b22ff84', 'menu696797bda43f6']; // tidak dihitung sebagai cup

        foreach ($prepared_items as $pi) {
            if (!in_array($pi['menu_id'], $excluded_menu_ids, true)) {
                $total_cups += $pi['quantity'];
            }
        }

        // Commit
        $conn->commit();

        // Fetch PIC contact from app_company
        $sqlPhone = "SELECT pic_contact FROM movira_core_dev.app_company WHERE company_id = ?";
        $stmtPhone = $conn->prepare($sqlPhone);
        if ($stmtPhone) {
            $stmtPhone->bind_param('s', $company_id);
            if ($stmtPhone->execute()) {
                $resultPhone = $stmtPhone->get_result();
                if ($rowPhone = $resultPhone->fetch_assoc()) {
                    $ownerPhone = preg_replace('/[^0-9]/', '', $rowPhone['pic_contact']); // sanitize
                }
            }
        }

        if (!empty($ownerPhone)) {
            $chatId = $ownerPhone . '@c.us';

            // WhatsApp-friendly message
            $text = "Halo! 👋\n\n"
                . "Berikut adalah rekap penjualan *Raki Coffee* hari ini oleh *{$username}*:\n\n"
                . "*Total Penjualan:* Rp " . number_format($total_amount, 0, ',', '.') . "\n"
                . "*Jumlah Cup:* " . $total_cups . " cup\n\n"
                . "Detail lengkap dapat dilihat melalui *Dashboard Raki*.\n"
                . "Terima kasih dan semangat selalu! ☕😊";

            $result = sendWhatsAppText($chatId, $text);

            if (!$result['success']) {
                echo('Gagal kirim WhatsApp: ' . $result['raw']);
            }
        }

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
                td.subtotal,
                t.total_item,
                tp.payment_method,
                tp.amount
            FROM raki_dev.transaction t
            JOIN raki_dev.transaction_detail td ON td.transaction_id = t.transaction_id
            LEFT JOIN raki_dev.menu m ON m.menu_id = td.menu_id
            LEFT JOIN raki_dev.transaction_payment tp ON tp.transaction_id = t.transaction_id
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
    $payments = [];

    // To avoid duplicated rows due to JOIN with transaction_payment,
    // track which detail_ids and payments we've already added.
    $seenDetailIds = [];
    $seenPayments = [];

    while ($row = $result->fetch_assoc()) {
        if ($header === null) {
            $header = [
                'transaction_id' => $row['transaction_id'],
                'company_id' => $row['company_id'],
                'transaction_date' => $row['transaction_date'],
                'total_item' => $row['total_item'],
                'total_amount' => (float)$row['total_amount'],
                'created_at' => $row['created_at'],
                'created_by' => $row['created_by'],
                'updated_at' => $row['updated_at'],
                'updated_by' => $row['updated_by'],
            ];
        }

        // Add each item only once per detail_id
        if (!isset($seenDetailIds[$row['detail_id']])) {
            $items[] = [
                'detail_id' => $row['detail_id'],
                'menu_id' => $row['menu_id'],
                'menu_name' => $row['menu_name'],
                'quantity' => (int)$row['quantity'],
                'subtotal' => (float)$row['subtotal'],
            ];
            $seenDetailIds[$row['detail_id']] = true;
        }

        // Add each payment only once per (payment_method, amount) combination
        $paymentKey = $row['payment_method'] . '|' . $row['amount'];
        if (!isset($seenPayments[$paymentKey])) {
            $payments[] = [
                'payment_method' => $row['payment_method'],
                'amount' => $row['amount'],
            ];
            $seenPayments[$paymentKey] = true;
        }
    }

    jsonResponse(200, 'Transaction detail fetched', [
        'transaction' => $header,
        'items' => $items,
        'payments' => $payments
    ]);
}

function getAllTransaction($conn, $company_id = null, $username = null, $page = 1, $limit = 10){
    $page = (int)$page;
    $limit = (int)$limit;
    if ($page < 1) { $page = 1; }
    if ($limit < 1) { $limit = 10; }

    $offset = ($page - 1) * $limit;

    // company yang ingin di-exclude ketika tidak ada filter company_id
    $excludedCompanyId = 'company691b31b41ea7b';

    // Apakah ada filter company_id?
    $hasCompanyFilter = !empty($company_id);
    $hasUsernameFilter = !empty($username);

    // --- Hitung total data untuk pagination ---
    if ($hasCompanyFilter && $hasUsernameFilter) {
        $countSql = "SELECT COUNT(*) as total
                     FROM raki_dev.transaction t
                     WHERE t.company_id = ?
                     AND t.created_by = ?";
        $stmtCount = $conn->prepare($countSql);
        if (!$stmtCount) {
            jsonResponse(500, 'Failed to prepare count statement', ['error' => $conn->error]);
        }
        $stmtCount->bind_param('ss', $company_id, $username);

    } else if ($hasCompanyFilter) {

        $countSql = "SELECT COUNT(*) as total
                     FROM raki_dev.transaction t
                     WHERE t.company_id = ?";
        $stmtCount = $conn->prepare($countSql);
        if (!$stmtCount) {
            jsonResponse(500, 'Failed to prepare count statement', ['error' => $conn->error]);
        }
        $stmtCount->bind_param('s', $company_id);

    } else if ($hasUsernameFilter) {

        $countSql = "SELECT COUNT(*) as total
                     FROM raki_dev.transaction t
                     WHERE t.created_by = ?";
        $stmtCount = $conn->prepare($countSql);
        if (!$stmtCount) {
            jsonResponse(500, 'Failed to prepare count statement', ['error' => $conn->error]);
        }
        $stmtCount->bind_param('s', $username);

    } else {

        $countSql = "SELECT COUNT(*) as total
                     FROM raki_dev.transaction t
                     WHERE t.company_id <> ?";
        $stmtCount = $conn->prepare($countSql);
        if (!$stmtCount) {
            jsonResponse(500, 'Failed to prepare count statement', ['error' => $conn->error]);
        }
        $stmtCount->bind_param('s', $excludedCompanyId);
    }

    if (!$stmtCount->execute()) {
        jsonResponse(500, 'Failed to execute count statement', ['error' => $stmtCount->error]);
    }
    $countResult = $stmtCount->get_result();
    $totalRow = $countResult->fetch_assoc();
    $total = (int)($totalRow['total'] ?? 0);

    // --- Query data transaksi + nama company ---
    $baseSelect = "SELECT 
                        t.transaction_id,
                        t.company_id,
                        t.transaction_date,
                        t.total_amount,
                        t.created_at,
                        t.created_by,
                        t.updated_at,
                        t.updated_by,
                        t.total_item,
                        ac.company_name
                   FROM raki_dev.transaction t
                   LEFT JOIN movira_core_dev.app_company ac 
                        ON ac.company_id = t.company_id";

    if ($hasCompanyFilter && $hasUsernameFilter) {

        $sql = $baseSelect . "
                WHERE t.company_id = ?
                AND t.created_by = ?
                ORDER BY t.transaction_date DESC
                LIMIT ?, ?";

    } else if ($hasCompanyFilter) {

        $sql = $baseSelect . "
                WHERE t.company_id = ?
                ORDER BY t.transaction_date DESC
                LIMIT ?, ?";

    } else if ($hasUsernameFilter) {

        $sql = $baseSelect . "
                WHERE t.created_by = ?
                ORDER BY t.transaction_date DESC
                LIMIT ?, ?";

    } else {

        $sql = $baseSelect . "
                WHERE t.company_id <> ?
                ORDER BY t.transaction_date DESC
                LIMIT ?, ?";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        jsonResponse(500, 'Failed to prepare statement', ['error' => $conn->error]);
    }

    if ($hasCompanyFilter && $hasUsernameFilter) {
        $stmt->bind_param('ssii', $company_id, $username, $offset, $limit);

    } else if ($hasCompanyFilter) {
        $stmt->bind_param('sii', $company_id, $offset, $limit);

    } else if ($hasUsernameFilter) {
        $stmt->bind_param('sii', $username, $offset, $limit);

    } else {
        $stmt->bind_param('sii', $excludedCompanyId, $offset, $limit);
    }

    if (!$stmt->execute()) {
        jsonResponse(500, 'Failed to execute statement', ['error' => $stmt->error]);
    }

    $result = $stmt->get_result();
    $transactions = [];
    while ($row = $result->fetch_assoc()) {
        $transactions[] = $row;
    }

    $response = [
        'transactions' => $transactions,
        'pagination' => [
            'total' => (int)$total,
            'page' => (int)$page,
            'limit' => (int)$limit,
            'total_pages' => $limit > 0 ? ceil($total / $limit) : 0,
        ]
    ];

    jsonResponse(200, 'Success', $response);
}

function deleteTransaction ($conn, $transaction_id){
    if($transaction_id === null || $transaction_id === ''){
        jsonResponse(400, 'Missing required fields (transaction_id)');
    }

    $query = "SELECT * FROM raki_dev.transaction WHERE transaction_id = '$transaction_id'";
    $result = mysqli_query($conn, $query);

    if(mysqli_num_rows($result) > 0) {
        $query_one =  "DELETE FROM raki_dev.transaction_detail WHERE transaction_id = '$transaction_id'";
        $query = "DELETE FROM raki_dev.transaction WHERE transaction_id = '$transaction_id'";

        if (mysqli_query($conn, $query_one)) {
            if (mysqli_query($conn, $query)) {
                jsonResponse(200, 'Transaction deleted successfully');
            } else {
                jsonResponse(500, 'Failed to delete transaction');
            }
        } else {
            jsonResponse(500, 'Failed to delete transaction detail');
        }

        

    } else {
        jsonResponse(404, 'Transaction is not registered in systems');
    }
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
                getAllTransaction($conn, $company_id, $token_username, $page, $limit);
            }
            break;
        case 'PUT':
            break;
        case 'DELETE':
            $transaction_id = $_GET['transaction_id'] ?? null;
            deleteTransaction($conn, $transaction_id);
            break;
    }

} catch (Exception $e){
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>