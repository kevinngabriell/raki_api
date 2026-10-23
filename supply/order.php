<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once '../notification/notification.php';
require_once '../notification/feed.php';

// Supply order helpers -----------------------------------------------------------------------------

const SUPPLY_NEXT_STATUS = [
    'pending'    => ['approved', 'rejected'],
    'approved'   => ['processing', 'cancelled'],
    'processing' => ['shipped', 'cancelled'],
    'shipped'    => ['completed'],
];

/** Pure: may `$actor` move an order from `$from` to `$to`? $actor is 'pusat' or 'outlet'. Returns an error message or null. */
function supplyTransitionError(string $from, string $to, string $actor): ?string {
    if (!in_array($to, SUPPLY_NEXT_STATUS[$from] ?? [], true)) {
        return "Status order tidak bisa berubah dari $from ke $to";
    }
    if ($to !== 'completed' && $actor !== 'pusat') {
        return 'Hanya pusat yang bisa mengubah status ini';
    }
    return null;
}

/** payments + bill of orders: order_id => ['payments' => [...], 'paid' => int]. */
function supplyPaymentsFor(mysqli $conn, string $schema, array $orderIds): array {
    if (!$orderIds) return [];
    [$in, $types] = accessInList($orderIds);
    $stmt = $conn->prepare("SELECT supply_order_id, amount, paid_on, method, recorded_by, proof_url FROM {$schema}.supply_payment WHERE supply_order_id IN ($in) ORDER BY paid_on, created_at");
    $stmt->bind_param($types, ...$orderIds);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result() as $r) {
        $out[$r['supply_order_id']]['payments'][] = ['amount' => (int)$r['amount'], 'paid_on' => $r['paid_on'], 'method' => $r['method'], 'recorded_by' => $r['recorded_by'], 'proof_url' => $r['proof_url']];
        $out[$r['supply_order_id']]['paid'] = ($out[$r['supply_order_id']]['paid'] ?? 0) + (int)$r['amount'];
    }
    $stmt->close();
    return $out;
}

/** Companies of the caller that may see an order (as the ordering outlet or as pusat). */
function supplyOrderVisible(mysqli $conn, object $decoded, array $order): bool {
    $mine = accessCompanyIds($conn, $decoded);
    return in_array($order['from_company_id'], $mine, true) || in_array($order['to_company_id'], $mine, true);
}

function createOrderTransaction($conn, $schema, $input, $username){
    $coreSchema = MOVIRA_CORE_SCHEMA;
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
    if (!in_array($from_company_id, accessCompanyIds($conn, $GLOBALS['decoded']), true)) {
        jsonResponse(403, 'Kamu tidak bisa memesan atas nama outlet ini');
    }
    $notes           = isset($input['notes']) ? $input['notes'] : null;
    $now             = getCurrentDateTimeJakarta();

    if (!isset($input['total_amount']) || !is_numeric($input['total_amount'])) {
        jsonResponse(400, 'total_amount is required and must be numeric');
    }
    $total_amount = (float)$input['total_amount'];

    $items = $input['items'];

    $prepared_items = [];
    foreach ($items as $idx => $it) {
        if (!isset($it['ingredient_id']) || !isset($it['qty']) || !isset($it['unit_price'])) {
            jsonResponse(400, "Invalid item at index $idx. Require ingredient_id, qty, unit_price");
        }

        $ingredient_id = trim($it['ingredient_id']);
        $qty           = $it['qty'];
        $unit_price    = $it['unit_price'];

        if (!is_numeric($qty) || $qty <= 0) {
            jsonResponse(400, "Invalid qty at index $idx. Must be numeric and > 0.");
        }
        if (!is_numeric($unit_price) || $unit_price < 0) {
            jsonResponse(400, "Invalid unit_price at index $idx. Must be numeric and >= 0.");
        }

        // The price is fixed at order time from the catalog (H2); a bahan that is nonaktif cannot be ordered.
        $stmtIng = $conn->prepare("SELECT price, is_active FROM {$schema}.ingredient WHERE ingredient_id = ?");
        $stmtIng->bind_param('s', $ingredient_id);
        $stmtIng->execute();
        $ing = $stmtIng->get_result()->fetch_assoc();
        $stmtIng->close();
        if (!$ing) {
            jsonResponse(404, "Bahan tidak ditemukan (index $idx)");
        }
        if ((int)$ing['is_active'] !== 1) {
            jsonResponse(400, "Bahan nonaktif tidak bisa dipesan (index $idx)");
        }

        $prepared_items[] = [
            'ingredient_id' => $ingredient_id,
            'qty'           => (float)$qty,
            'unit_price'    => $ing['price'] !== null ? (float)$ing['price'] : (float)$unit_price,
        ];
    }
    $total_amount = 0.0;
    foreach ($prepared_items as $pi) {
        $total_amount += $pi['qty'] * $pi['unit_price'];
    }

    $generateId = function ($prefix = '') {
        return $prefix . bin2hex(random_bytes(8));
    };
    $generateOrderCode = function () {
        return 'SO' . date('YmdHis') . mt_rand(100, 999);
    };

    $supply_order_id = $generateId('so_');
    $order_code      = $generateOrderCode();
    $status          = 'pending';

    mysqli_begin_transaction($conn);

    try {
        $stmtOrder = $conn->prepare("INSERT INTO {$schema}.supply_order (
            supply_order_id, order_code, from_company_id, to_company_id,
            status, notes, requested_at, created_by, updated_by,
            created_at, updated_at, total_amount
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if (!$stmtOrder) {
            throw new Exception('Failed to prepare supply_order insert');
        }

        $stmtOrder->bind_param(
            'sssssssssssd',
            $supply_order_id,
            $order_code,
            $from_company_id,
            $to_company_id,
            $status,
            $notes,
            $now,
            $username,
            $username,
            $now,
            $now,
            $total_amount
        );

        if (!$stmtOrder->execute()) {
            throw new Exception('Failed to create supply_order');
        }
        $stmtOrder->close();

        foreach ($prepared_items as $item) {
            $detail_id = $generateId('sod_');

            $stmtDetail = $conn->prepare("INSERT INTO {$schema}.supply_order_detail (
                supply_order_detail_id, supply_order_id, ingredient_id,
                qty, unit_price, created_at, updated_at, updated_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

            if (!$stmtDetail) {
                throw new Exception('Failed to prepare supply_order_detail insert');
            }

            $stmtDetail->bind_param(
                'sssddsss',
                $detail_id,
                $supply_order_id,
                $item['ingredient_id'],
                $item['qty'],
                $item['unit_price'],
                $now,
                $now,
                $username
            );

            if (!$stmtDetail->execute()) {
                throw new Exception('Failed to create supply_order_detail');
            }
            $stmtDetail->close();
        }

        $item_count = count($prepared_items);
        $total_qty  = 0;
        foreach ($prepared_items as $pi) {
            $total_qty += $pi['qty'];
        }

        mysqli_commit($conn);

        notificationCreate($conn, $schema, $to_company_id, 'supply_order', 'Order supply baru', "Order {$order_code} masuk dari outlet.", 'supply_order', $supply_order_id, $username);

        $ownerPhone      = '';
        $fromCompanyName = '';

        $sqlPhone = "SELECT pic_contact FROM {$coreSchema}.app_company WHERE company_id = ?";
        $stmtPhone = $conn->prepare($sqlPhone);
        if ($stmtPhone) {
            $stmtPhone->bind_param('s', $to_company_id);
            if ($stmtPhone->execute()) {
                $resultPhone = $stmtPhone->get_result();
                if ($rowPhone = $resultPhone->fetch_assoc()) {
                    $ownerPhone = preg_replace('/[^0-9]/', '', (string)$rowPhone['pic_contact']);
                }
            }
            $stmtPhone->close();
        }

        $sqlFrom = "SELECT company_name FROM {$coreSchema}.app_company WHERE company_id = ?";
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
            $requestedAtDisplay = date('d-m-Y H:i', strtotime($now));

            $text = "Halo, tim *Raki*\n\n"
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
                . "Terima kasih dan semangat selalu!";

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
        error_log('createOrderTransaction error: ' . $e->getMessage());
        jsonResponse(500, 'Failed to create supply order');
    }
}

function getSupplyOrderDetail($conn, $schema, $order_id){
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $stmt = $conn->prepare("SELECT supply_order_id, order_code, from_company_id, AC.company_name, AC.company_name AS from_company_name, to_company_id, SO.status, notes, requested_at, approved_at, processing_at, shipped_at, completed_at, rejected_at, cancelled_at, reason, shipping_cost, shipping_awb, shipping, created_by, updated_by, SO.created_at, SO.updated_at, total_amount
        FROM {$schema}.supply_order SO
        LEFT JOIN {$coreSchema}.app_company AC ON SO.from_company_id = AC.company_id
        WHERE supply_order_id = ?");
    $stmt->bind_param('s', $order_id);
    $stmt->execute();
    $orderRes = $stmt->get_result();

    if (!$orderRes || $orderRes->num_rows === 0) {
        $stmt->close();
        jsonResponse(404, 'Supply order not found');
    }
    $order = $orderRes->fetch_assoc();
    $stmt->close();
    if (!supplyOrderVisible($conn, $GLOBALS['decoded'], $order)) {
        jsonResponse(403, 'Kamu tidak punya akses ke order ini');
    }
    $order['is_mitra'] = accessCompanyIsMitra($conn, $order['from_company_id']);
    $pay = supplyPaymentsFor($conn, $schema, [$order_id])[$order_id] ?? ['payments' => [], 'paid' => 0];
    $order['payments'] = $pay['payments'];
    $order['paid_amount'] = $pay['paid'];
    $order['bill_amount'] = (int)$order['total_amount'] + (int)($order['shipping_cost'] ?? 0);

    $stmtDetail = $conn->prepare("SELECT I.ingredient_id, I.ingredient_name, IC.category_name, UOM.uom_name, qty, unit_price, subtotal
        FROM {$schema}.supply_order_detail SOD
        LEFT JOIN {$schema}.ingredient I ON SOD.ingredient_id = I.ingredient_id
        LEFT JOIN {$schema}.ingredient_category IC ON I.ingredient_category = IC.category_id
        LEFT JOIN {$schema}.unit_of_measurement UOM ON I.uom = UOM.uom_id
        WHERE supply_order_id = ?");
    $stmtDetail->bind_param('s', $order_id);
    $stmtDetail->execute();
    $detailRes = $stmtDetail->get_result();

    $details = [];
    if ($detailRes) {
        while ($row = $detailRes->fetch_assoc()) {
            $details[] = $row;
        }
    }
    $stmtDetail->close();

    jsonResponse(200, 'Success', [
        'order' => $order,
        'items' => $details,
    ]);
}

function getSupplyOrders($conn, $schema, $page = 1, $limit = 10){
    $limit = pageLimit($limit);
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $excludedCompanyId = 'company691b31b41ea7b';

    $offset = ($page - 1) * $limit;

    $conditions = [];
    $types = '';
    $params = [];

    if (isset($_GET['from_company_id']) && $_GET['from_company_id'] !== '') {
        $conditions[] = "SO.from_company_id = ?";
        $types .= 's';
        $params[] = $_GET['from_company_id'];
    }
    if (isset($_GET['to_company_id']) && $_GET['to_company_id'] !== '') {
        $conditions[] = "SO.to_company_id = ?";
        $types .= 's';
        $params[] = $_GET['to_company_id'];
    } else {
        $conditions[] = "SO.to_company_id <> ?";
        $types .= 's';
        $params[] = $excludedCompanyId;
    }
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $conditions[] = "SO.status = ?";
        $types .= 's';
        $params[] = $_GET['status'];
    }

    // Only orders the caller is a party to (as the ordering outlet or as pusat).
    $mine = accessCompanyIds($conn, $GLOBALS['decoded']) ?: ['-'];
    [$mineIn, $mineTypes] = accessInList($mine);
    $conditions[] = "(SO.from_company_id IN ($mineIn) OR SO.to_company_id IN ($mineIn))";
    $types .= $mineTypes . $mineTypes;
    $params = array_merge($params, $mine, $mine);

    $where = '';
    if (!empty($conditions)) {
        $where = 'WHERE ' . implode(' AND ', $conditions);
    }

    $countSql = "SELECT COUNT(*) as total
        FROM {$schema}.supply_order SO
        LEFT JOIN {$coreSchema}.app_company AC ON SO.from_company_id = AC.company_id
        $where";
    $countStmt = $conn->prepare($countSql);
    if (!empty($params)) {
        $countStmt->bind_param($types, ...$params);
    }
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    $totalRow = $countResult->fetch_assoc();
    $total = $totalRow['total'];
    $countStmt->close();

    $dataSql = "SELECT supply_order_id, order_code, from_company_id, AC.company_name, FC.company_name AS from_company_name, to_company_id, SO.status, notes, requested_at, approved_at, processing_at, shipped_at, completed_at, rejected_at, cancelled_at, reason, shipping_cost, shipping_awb, shipping, created_by, updated_by, SO.created_at, SO.updated_at, total_amount,
            (SELECT COUNT(*) FROM {$schema}.supply_order_detail D WHERE D.supply_order_id = SO.supply_order_id) AS item_count,
            EXISTS (SELECT 1 FROM {$coreSchema}.app_user MU WHERE MU.company_id = SO.from_company_id AND MU.app_role_id = '" . ACCESS_ROLE_MITRA . "') AS is_mitra
        FROM {$schema}.supply_order SO
        LEFT JOIN {$coreSchema}.app_company AC ON SO.to_company_id = AC.company_id
        LEFT JOIN {$coreSchema}.app_company FC ON SO.from_company_id = FC.company_id
        $where
        ORDER BY requested_at DESC, created_at DESC LIMIT ? OFFSET ?";

    $dataTypes = $types . 'ii';
    $dataParams = array_merge($params, [$limit, $offset]);

    $dataStmt = $conn->prepare($dataSql);
    $dataStmt->bind_param($dataTypes, ...$dataParams);
    $dataStmt->execute();
    $res = $dataStmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $dataStmt->close();

    $pay = supplyPaymentsFor($conn, $schema, array_column($rows, 'supply_order_id'));
    foreach ($rows as &$row) {
        $row['is_mitra'] = (bool)$row['is_mitra'];
        $row['item_count'] = (int)$row['item_count'];
        $row['payments'] = $pay[$row['supply_order_id']]['payments'] ?? [];
        $row['paid_amount'] = $pay[$row['supply_order_id']]['paid'] ?? 0;
        $row['bill_amount'] = (int)$row['total_amount'] + (int)($row['shipping_cost'] ?? 0);
    }
    unset($row);

    $response = [
        'data' => $rows,
        'pagination' => [
            'total' => (int)$total,
            'page' => (int)$page,
            'limit' => (int)$limit,
            'total_pages' => ceil($total / $limit),
        ],
    ];

    jsonResponse(200, 'Success', $response);
}

function updateOrderStatus($conn, $schema, $input, $username, $company_id){
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
    $order_id = $input['supply_order_id'];

    $shipping_cost_val = null;
    $has_shipping_cost_input = array_key_exists('shipping_cost', $input);

    if ($status === 'shipped') {
        if (!$has_shipping_cost_input || $input['shipping_cost'] === '') {
            jsonResponse(400, 'Missing shipping_cost for shipped status');
        }
    }

    if ($has_shipping_cost_input && $input['shipping_cost'] !== '') {
        if (!is_numeric($input['shipping_cost']) || (float)$input['shipping_cost'] < 0) {
            jsonResponse(400, 'Invalid shipping_cost. Must be numeric and >= 0.');
        }
        $shipping_cost_val = (int)$input['shipping_cost'];
    }

    $shipping_awb_val = null;
    $shipping_company_val = null;

    if (isset($input['shipping_awb']) && $input['shipping_awb'] !== '') {
        $shipping_awb_val = $input['shipping_awb'];
    }

    if (isset($input['shipping']) && $input['shipping'] !== '') {
        $shipping_company_val = $input['shipping'];
    }

    if (!in_array($status, $allowedStatuses, true)) {
        jsonResponse(400, 'Invalid status value');
    }

    $now = getCurrentDateTimeJakarta();

    $check_stmt = $conn->prepare("SELECT supply_order_id, from_company_id, to_company_id, status FROM {$schema}.supply_order WHERE supply_order_id = ?");
    $check_stmt->bind_param('s', $order_id);
    $check_stmt->execute();
    $current = $check_stmt->get_result()->fetch_assoc();
    $check_stmt->close();

    $actor = $GLOBALS['decoded'];
    if (!$current || !supplyOrderVisible($conn, $actor, $current)) {
        jsonResponse(404, 'Supply order not found or you do not have permission to update it');
    }
    // Pusat = the receiving company's Owner; the outlet is the ordering side.
    $isPusat = accessIsOwner($actor) && in_array($current['to_company_id'], accessCompanyIds($conn, $actor), true);
    $transitionError = supplyTransitionError($current['status'], $status, $isPusat ? 'pusat' : 'outlet');
    if ($transitionError !== null) {
        jsonResponse(409, $transitionError, ['reason' => 'invalid_transition', 'from' => $current['status']]);
    }
    $reason = isset($input['reason']) ? mb_substr(trim((string)$input['reason']), 0, 500) : '';
    if (in_array($status, ['rejected', 'cancelled'], true) && $reason === '') {
        jsonResponse(400, 'Alasan wajib diisi untuk menolak atau membatalkan order');
    }

    $setParts = [];
    $setTypes = '';
    $setValues = [];

    $setParts[] = "status = ?";
    $setTypes .= 's';
    $setValues[] = $status;

    $setParts[] = "updated_by = ?";
    $setTypes .= 's';
    $setValues[] = $username;

    $setParts[] = "updated_at = ?";
    $setTypes .= 's';
    $setValues[] = $now;

    if (!is_null($shipping_cost_val)) {
        $setParts[] = "shipping_cost = ?";
        $setTypes .= 'i';
        $setValues[] = $shipping_cost_val;
    }
    if (!is_null($shipping_awb_val)) {
        $setParts[] = "shipping_awb = ?";
        $setTypes .= 's';
        $setValues[] = $shipping_awb_val;
    }
    if (!is_null($shipping_company_val)) {
        $setParts[] = "shipping = ?";
        $setTypes .= 's';
        $setValues[] = $shipping_company_val;
    }

    foreach (['processing' => 'processing_at', 'shipped' => 'shipped_at', 'rejected' => 'rejected_at', 'cancelled' => 'cancelled_at'] as $st => $col) {
        if ($status === $st) {
            $setParts[] = "$col = ?";
            $setTypes .= 's';
            $setValues[] = $now;
        }
    }
    if ($reason !== '') {
        $setParts[] = "reason = ?";
        $setTypes .= 's';
        $setValues[] = $reason;
    }
    if ($status === 'approved') {
        $setParts[] = "approved_at = ?";
        $setTypes .= 's';
        $setValues[] = $now;
    }
    if ($status === 'completed') {
        $setParts[] = "completed_at = ?";
        $setTypes .= 's';
        $setValues[] = $now;
    }

    $setClause = implode(', ', $setParts);
    $allTypes = $setTypes . 's';
    $allValues = array_merge($setValues, [$order_id]);

    $update_stmt = $conn->prepare("UPDATE {$schema}.supply_order SET {$setClause} WHERE supply_order_id = ?");
    $update_stmt->bind_param($allTypes, ...$allValues);

    if (!$update_stmt->execute()) {
        $update_stmt->close();
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/supply/order.php',
            'method'        => 'PUT',
            'error_message' => 'Failed to update supply order status',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to update supply order status');
    }

    $affected = $update_stmt->affected_rows;
    $update_stmt->close();

    if ($affected === 0) {
        jsonResponse(404, 'Supply order not found');
    }

    $statusText = ['approved' => 'disetujui', 'processing' => 'diproses', 'shipped' => 'dikirim', 'completed' => 'selesai', 'rejected' => 'ditolak', 'cancelled' => 'dibatalkan'][$status] ?? $status;
    $notifyCompany = $isPusat ? $current['from_company_id'] : $current['to_company_id'];
    notificationCreate($conn, $schema, $notifyCompany, 'supply_order', "Order supply {$statusText}", ($reason !== '' ? $reason : "Status order berubah menjadi {$statusText}."), 'supply_order', $order_id, $username);

    jsonResponse(200, 'Status updated successfully', [
        'supply_order_id' => $order_id,
        'status'          => $status,
        'shipping_cost'   => $shipping_cost_val,
        'shipping_awb'    => $shipping_awb_val,
        'shipping'        => $shipping_company_val,
        'reason'          => $reason !== '' ? $reason : null,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$decoded = requireAuth();
$conn = DB::conn();
$schema = DB_SCHEMA;

$token_username = $decoded->username;
$token_company = $decoded->company_id;

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch($method){
        case 'POST':
            $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
            if (stripos($contentType, 'application/json') !== false) {
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
            } else {
                $input = $_POST ?? [];
            }
            createOrderTransaction($conn, $schema, $input, $token_username);
            break;
        case 'GET':
            $company_id = $_GET['company_id'] ?? null;

            if (isset($_GET['supply_order_id']) && $_GET['supply_order_id'] !== '') {
                getSupplyOrderDetail($conn, $schema, $_GET['supply_order_id']);
            } else {
                if ($company_id) {
                    $_GET['to_company_id'] = $company_id;
                }
                $page = (int)($_GET['page'] ?? 1);
                $limit = (int)($_GET['limit'] ?? 10);
                getSupplyOrders($conn, $schema, $page, $limit);
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
            updateOrderStatus($conn, $schema, $input, $token_username, $token_company);
            break;
        case 'DELETE':
            jsonResponse(405, 'Method DELETE not implemented');
            break;
        default:
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 405,
                'endpoint'      => '/supply/order.php',
                'method'        => $method,
                'error_message' => 'Method Not Allowed',
                'user_identifier' => $decoded->username ?? null,
                'company_id'      => $decoded->company_id ?? null,
            ]);
            jsonResponse(405, 'Method Not Allowed');
            break;
    }

} catch (Exception $e){
    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/supply/order.php',
        'method'        => $method ?? '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
