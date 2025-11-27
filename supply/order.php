<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../notification/notification.php';

function createOrderTransaction($conn, $input, $username){
    if (
        !$input ||
        !isset($input['from_company_id']) ||
        !isset($input['to_company_id']) ||
        !isset($input['items']) ||
        !is_array($input['items']) ||
        count($input['items']) === 0
    ) {
        jsonResponse(400, 'Invalid payload. Require from_company_id, to_company_id and non-empty items array.');
    }

    $from_company_id = $input['from_company_id'];
    $to_company_id   = $input['to_company_id'];
    $notes           = isset($input['notes']) ? $input['notes'] : null;
    $total_amount   = $input['total_amount'];
    $now             = getCurrentDateTimeJakarta();

    $items = $input['items'];

    // Validate and normalize items
    $prepared_items = [];
    foreach ($items as $idx => $it) {
        if (!isset($it['ingredient_id']) || !isset($it['qty']) || !isset($it['unit_price']) || !isset($it['subtotal'])) {
            jsonResponse(400, "Invalid item at index $idx. Require ingredient_id, qty, unit_price, subtotal");
        }

        $ingredient_id = trim($it['ingredient_id']);
        $qty           = $it['qty'];
        $unit_price    = $it['unit_price'];
        $subtotal    = $it['subtotal'];

        if (!is_numeric($qty) || $qty <= 0) {
            jsonResponse(400, "Invalid qty at index $idx. Must be numeric and > 0.");
        }
        if (!is_numeric($unit_price) || $unit_price < 0) {
            jsonResponse(400, "Invalid unit_price at index $idx. Must be numeric and >= 0.");
        }
        if (!is_numeric($subtotal) || $subtotal < 0) {
            jsonResponse(400, "Invalid subtotal at index $idx. Must be numeric and >= 0.");
        }

        $prepared_items[] = [
            'ingredient_id' => $ingredient_id,
            'qty'           => (float)$qty,
            'unit_price'    => (float)$unit_price,
            'subtotal'    => (float)$subtotal,
        ];
    }

    // Simple ID and code generators (no external helper required)
    $generateId = function ($prefix = '') {
        return $prefix . bin2hex(random_bytes(8));
    };
    $generateOrderCode = function () {
        return 'SO' . date('YmdHis') . mt_rand(100, 999);
    };

    $supply_order_id = $generateId('so_');
    $order_code      = $generateOrderCode();

    $from_company_id_esc = mysqli_real_escape_string($conn, $from_company_id);
    $to_company_id_esc   = mysqli_real_escape_string($conn, $to_company_id);
    $order_code_esc      = mysqli_real_escape_string($conn, $order_code);
    $notes_esc           = $notes !== null ? "'" . mysqli_real_escape_string($conn, $notes) . "'" : "NULL";
    $created_by_esc      = mysqli_real_escape_string($conn, $username);
    $now_esc             = mysqli_real_escape_string($conn, $now);

    mysqli_begin_transaction($conn);

    try {
        // Insert into supply_order
        $insertOrderSql = "
            INSERT INTO raki_dev.supply_order (
                supply_order_id,
                order_code,
                from_company_id,
                to_company_id,
                status,
                notes,
                requested_at,
                created_by,
                updated_by,
                created_at,
                updated_at,
                total_amount
            ) VALUES (
                '$supply_order_id',
                '$order_code_esc',
                '$from_company_id_esc',
                '$to_company_id_esc',
                'pending',
                $notes_esc,
                '$now_esc',
                '$created_by_esc',
                '$created_by_esc',
                '$now_esc',
                '$now_esc',
                $total_amount
            )
        ";

        if (!mysqli_query($conn, $insertOrderSql)) {
            throw new Exception('Failed to create supply_order: ' . mysqli_error($conn));
        }

        // Insert details
        foreach ($prepared_items as $item) {
            $detail_id  = $generateId('sod_');
            $ing_esc    = mysqli_real_escape_string($conn, $item['ingredient_id']);
            $qty_val    = (float)$item['qty'];
            $price_val  = (float)$item['unit_price'];
            $subtotal_val  = (float)$item['subtotal'];

            $insertDetailSql = "
                INSERT INTO raki_dev.supply_order_detail (
                    supply_order_detail_id,
                    supply_order_id,
                    ingredient_id,
                    qty,
                    unit_price,
                    subtotal,
                    created_at,
                    updated_at,
                    updated_by
                ) VALUES (
                    '$detail_id',
                    '$supply_order_id',
                    '$ing_esc',
                    $qty_val,
                    $price_val,
                    $subtotal_val,
                    '$now_esc',
                    '$now_esc',
                    '$created_by_esc'
                )
            ";

            if (!mysqli_query($conn, $insertDetailSql)) {
                throw new Exception('Failed to create supply_order_detail: ' . mysqli_error($conn));
            }
        }

        // Compute item summary for notification
        $item_count = count($prepared_items);
        $total_qty  = 0;
        foreach ($prepared_items as $pi) {
            $total_qty += $pi['qty'];
        }

        mysqli_commit($conn);

        // Fetch PIC contact (tujuan supply) dan nama mitra (asal supply)
        $ownerPhone      = '';
        $fromCompanyName = '';

        // PIC contact untuk company tujuan (biasanya Raki pusat)
        $sqlPhone = "SELECT pic_contact FROM movira_core_dev.app_company WHERE company_id = ?";
        $stmtPhone = $conn->prepare($sqlPhone);
        if ($stmtPhone) {
            $stmtPhone->bind_param('s', $to_company_id);
            if ($stmtPhone->execute()) {
                $resultPhone = $stmtPhone->get_result();
                if ($rowPhone = $resultPhone->fetch_assoc()) {
                    // sanitize: ambil hanya angka
                    $ownerPhone = preg_replace('/[^0-9]/', '', (string)$rowPhone['pic_contact']);
                }
            }
            $stmtPhone->close();
        }

        // Nama mitra (asal permintaan supply)
        $sqlFrom = "SELECT company_name FROM movira_core_dev.app_company WHERE company_id = ?";
        $stmtFrom = $conn->prepare($sqlFrom);
        if ($stmtFrom) {
            $stmtFrom->bind_param('s', $from_company_id);
            if ($stmtFrom->execute()) {
                $resFrom = $stmtFrom->get_result();
                if ($rowFrom = $resFrom->fetch_assoc()) {
                    $fromCompanyName = $rowFrom['company_name'];
                }
            }
            $stmtFrom->close();
        }

        if (!empty($ownerPhone)) {
            $chatId = $ownerPhone . '@c.us';

            // Format tanggal sederhana untuk WA (misal: 22-11-2025 14:30)
            $requestedAtDisplay = date('d-m-Y H:i', strtotime($now));

            // WhatsApp-friendly message
            $text = "Halo, tim *Raki* 👋\n\n"
                . "Ada permintaan suplai baru dari mitra *{$fromCompanyName}*.\n\n"
                . "*Kode Order:* {$order_code}\n"
                . "*Tgl Permintaan:* {$requestedAtDisplay}\n"
                . "*Jumlah Item:* {$item_count} jenis\n"
                . "*Total Kuantitas:* {$total_qty} unit\n\n"
                . (!empty($notes)
                    ? "*Catatan dari mitra:*\n\"{$notes}\"\n\n"
                    : ""
                )
                . "Silakan dicek dan diproses melalui *Dashboard Raki* pada menu *Supply Order*.\n\n"
                . "Terima kasih dan semangat selalu! ☕😊";

            $result = sendWhatsAppText($chatId, $text);

            if (!$result['success']) {
                error_log('Gagal kirim WhatsApp: ' . $result['raw']);
            }
        }

        jsonResponse(201, 'Supply order created successfully', [
            'supply_order_id' => $supply_order_id,
            'order_code'      => $order_code,
        ]);
    } catch (Exception $e) {
        mysqli_rollback($conn);
        jsonResponse(500, 'Failed to create supply order', ['error' => $e->getMessage()]);
    }
}

function getSupplyOrderDetail($conn, $order_id){
    $order_id_esc = mysqli_real_escape_string($conn, $order_id);

    $orderSql = "SELECT * FROM raki_dev.supply_order WHERE supply_order_id = '$order_id_esc'";
    $orderRes = mysqli_query($conn, $orderSql);
    if (!$orderRes || mysqli_num_rows($orderRes) === 0) {
        jsonResponse(404, 'Supply order not found');
    }
    $order = mysqli_fetch_assoc($orderRes);

    $detailSql = "SELECT I.ingredient_id, I.ingredient_name, IC.category_name, qty, unit_price, subtotal
FROM raki_dev.supply_order_detail SOD
LEFT JOIN raki_dev.ingredient I ON SOD.ingredient_id = I.ingredient_id
LEFT JOIN raki_dev.ingredient_category IC ON I.ingredient_category = IC.category_id WHERE supply_order_id = '$order_id_esc'";
    $detailRes = mysqli_query($conn, $detailSql);
    $details = [];
    if ($detailRes) {
        while ($row = mysqli_fetch_assoc($detailRes)) {
            $details[] = $row;
        }
    }

    jsonResponse(200, 'Success', [
        'order' => $order,
        'items' => $details,
    ]);
}

function getSupplyOrders($conn){
    $conditions = [];

    if (isset($_GET['from_company_id']) && $_GET['from_company_id'] !== '') {
        $from_esc = mysqli_real_escape_string($conn, $_GET['from_company_id']);
        $conditions[] = "from_company_id = '$from_esc'";
    }
    if (isset($_GET['to_company_id']) && $_GET['to_company_id'] !== '') {
        $to_esc = mysqli_real_escape_string($conn, $_GET['to_company_id']);
        $conditions[] = "to_company_id = '$to_esc'";
    }
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $status_esc = mysqli_real_escape_string($conn, $_GET['status']);
        $conditions[] = "status = '$status_esc'";
    }

    $where = '';
    if (!empty($conditions)) {
        $where = 'WHERE ' . implode(' AND ', $conditions);
    }

    $sql = "
        SELECT *
        FROM raki_dev.supply_order
        $where
        ORDER BY requested_at DESC, created_at DESC
    ";

    $res = mysqli_query($conn, $sql);
    if (!$res) {
        jsonResponse(500, 'Failed to fetch supply orders', ['error' => mysqli_error($conn)]);
    }

    $rows = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $rows[] = $row;
    }

    jsonResponse(200, 'Success', $rows);
}

function updateOrderStatus($conn, $input, $username){
    if (!$input || !isset($input['supply_order_id']) || !isset($input['status'])) {
        jsonResponse(400, 'Missing required fields (supply_order_id, status)');
    }

    $allowedStatuses = [
        'pending',
        'approved',
        'rejected',
        'processing',
        'shipped',
        'completed',
        'cancelled',
    ];

    $status = $input['status'];
    if (!in_array($status, $allowedStatuses, true)) {
        jsonResponse(400, 'Invalid status value');
    }

    $order_id_esc = mysqli_real_escape_string($conn, $input['supply_order_id']);
    $status_esc   = mysqli_real_escape_string($conn, $status);
    $user_esc     = mysqli_real_escape_string($conn, $username);
    $now          = getCurrentDateTimeJakarta();
    $now_esc      = mysqli_real_escape_string($conn, $now);

    $setParts = [
        "status = '$status_esc'",
        "updated_by = '$user_esc'",
        "updated_at = '$now_esc'",
    ];

    // Auto set approved_at / completed_at based on status
    if ($status === 'approved') {
        $setParts[] = "approved_at = '$now_esc'";
    }
    if ($status === 'completed') {
        $setParts[] = "completed_at = '$now_esc'";
    }

    $setClause = implode(', ', $setParts);

    $sql = "UPDATE raki_dev.supply_order SET $setClause WHERE supply_order_id = '$order_id_esc'";

    if (!mysqli_query($conn, $sql)) {
        jsonResponse(500, 'Failed to update supply order status', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_affected_rows($conn) === 0) {
        jsonResponse(404, 'Supply order not found');
    }

    jsonResponse(200, 'Status updated successfully', [
        'supply_order_id' => $input['supply_order_id'],
        'status'          => $status,
    ]);
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
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
            } else {
                $input = $_POST ?? [];
            }
            createOrderTransaction($conn, $input, $token_username);
            break;
        case 'GET':
            $company_id = $_GET['company_id'] ?? null;

            if (isset($_GET['supply_order_id']) && $_GET['supply_order_id'] !== '') {
                getSupplyOrderDetail($conn, $_GET['supply_order_id']);
            } else {
                // jika ada company_id dari FE, jadikan filter to_company_id
                if ($company_id) {
                    $_GET['to_company_id'] = $company_id;
                }
                getSupplyOrders($conn);
            }
            break;
        case 'PUT':
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
            } else {
                $rawBody = file_get_contents('php://input');
                $input = [];
                if (!empty($rawBody)) {
                    parse_str($rawBody, $input);
                }
            }
            updateOrderStatus($conn, $input, $token_username);
            break;
        case 'DELETE':
            jsonResponse(405, 'Method DELETE not implemented');
            break;
    }


} catch (Exception $e){
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}


?>