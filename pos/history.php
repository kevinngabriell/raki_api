<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';
require_once __DIR__ . '/../account/account_rules.php';
require_once __DIR__ . '/history_lines.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// GET /pos/history.php — the POS "Riwayat Transaksi" modal.
// Cashiers only see their own sales; Owner/Mitra may pass `username` to look at a cashier.
function getPosHistory($conn, $schema, $params, $decoded){
    $company_id = trim((string)($params['company_id'] ?? ''));
    if ($company_id === '') {
        jsonResponse(400, 'company_id is required');
    }

    $bounds = posHistoryDateBounds($params['start_date'] ?? null, $params['end_date'] ?? null);
    if (isset($bounds['error'])) {
        jsonResponse(400, $bounds['error']);
    }

    $page  = max(1, (int)($params['page'] ?? 1));
    $limit = min(100, max(1, (int)($params['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    $where = ['t.company_id = ?'];
    $args  = [$company_id];

    $roleName = accountRoleName($conn, $decoded->role ?? null);
    if (accountIsManagerRole($roleName)) {
        $cashier = trim((string)($params['username'] ?? ''));
        if ($cashier !== '') {
            $where[] = 't.created_by = ?';
            $args[]  = $cashier;
        }
    } else {
        if ($company_id !== (string)($decoded->company_id ?? '')) {
            jsonResponse(403, 'Tidak bisa melihat transaksi perusahaan lain.');
        }
        $where[] = 't.created_by = ?';
        $args[]  = (string)$decoded->username;
    }

    if ($bounds['from'] !== null) {
        $where[] = 't.transaction_date >= ?';
        $args[]  = $bounds['from'];
    }
    if ($bounds['until'] !== null) {
        $where[] = 't.transaction_date < ?';
        $args[]  = $bounds['until'];
    }

    // A transaction matches when any of its lines is a menu or package whose name contains the term.
    $search = trim((string)($params['search'] ?? ''));
    if ($search !== '') {
        $pattern = posHistoryLikePattern(mb_strtolower($search));
        $where[] = "EXISTS (SELECT 1 FROM {$schema}.transaction_detail sd
                            LEFT JOIN {$schema}.menu sm ON sm.menu_id = sd.menu_id
                            LEFT JOIN {$schema}.package sp ON sp.package_id = sd.package_id
                            WHERE sd.transaction_id = t.transaction_id
                              AND (LOWER(sm.menu_name) LIKE ? OR LOWER(sp.package_name) LIKE ?))";
        $args[] = $pattern;
        $args[] = $pattern;
    }

    $whereSql = implode(' AND ', $where);

    // Summary covers every matching transaction, not just this page.
    $summaryRow = DB::query(
        "SELECT COUNT(*) AS transaction_count, COALESCE(SUM(t.total_item), 0) AS items_sold, COALESCE(SUM(t.total_amount), 0) AS total_revenue
         FROM {$schema}.transaction t WHERE $whereSql",
        $args
    )->fetch_assoc();
    $total = (int)$summaryRow['transaction_count'];

    $pageRows = DB::query(
        "SELECT t.transaction_id, t.transaction_date, t.created_at, t.queue_number, t.total_item, t.total_amount, COALESCE(t.discount_amount, 0) AS discount_amount
         FROM {$schema}.transaction t WHERE $whereSql
         ORDER BY t.transaction_date DESC, t.created_at DESC
         LIMIT ?, ?",
        array_merge($args, [$offset, $limit])
    )->fetch_all(MYSQLI_ASSOC);

    $transactions = [];
    if ($pageRows) {
        $ids = array_column($pageRows, 'transaction_id');
        $in  = implode(', ', array_fill(0, count($ids), '?'));

        $detailByTrx = [];
        $detailRows = DB::query(
            "SELECT d.transaction_id, d.menu_id, m.menu_name, d.package_id, p.package_name, d.line_no, d.quantity, d.subtotal, d.discount_amount, d.sugar_level, d.ice_level
             FROM {$schema}.transaction_detail d
             LEFT JOIN {$schema}.menu m ON m.menu_id = d.menu_id
             LEFT JOIN {$schema}.package p ON p.package_id = d.package_id
             WHERE d.transaction_id IN ($in)
             ORDER BY d.transaction_id, d.detail_id",
            $ids
        );
        foreach ($detailRows as $row) {
            $detailByTrx[$row['transaction_id']][] = $row;
        }

        $paymentsByTrx = [];
        $paymentRows = DB::query(
            "SELECT transaction_id, payment_method, amount, reference_no
             FROM {$schema}.transaction_payment
             WHERE transaction_id IN ($in)
             ORDER BY transaction_id, created_at, payment_id",
            $ids
        );
        foreach ($paymentRows as $row) {
            $paymentsByTrx[$row['transaction_id']][] = [
                'payment_method' => $row['payment_method'],
                'amount'         => (int)$row['amount'],
                'reference_no'   => $row['reference_no'],
            ];
        }

        $promoByTrx = [];
        $promoRows = DB::query(
            "SELECT transaction_id, promo_name FROM {$schema}.transaction_promo WHERE transaction_id IN ($in)",
            $ids
        );
        foreach ($promoRows as $row) {
            $promoByTrx[$row['transaction_id']] = $row['promo_name'];
        }

        foreach ($pageRows as $row) {
            $id = $row['transaction_id'];
            $totalAmount = (int)round((float)$row['total_amount']);
            $discount = (int)$row['discount_amount'];
            $transactions[] = [
                'transaction_id'   => $id,
                'transaction_date' => $row['transaction_date'],
                'created_at'       => $row['created_at'],
                'queue_number'     => $row['queue_number'] !== null ? (int)$row['queue_number'] : null,
                'total_item'       => (int)$row['total_item'],
                'gross_amount'     => $totalAmount + $discount,
                'discount_amount'  => $discount,
                'total_amount'     => $totalAmount,
                'promo_name'       => $promoByTrx[$id] ?? null,
                'lines'            => posHistoryBuildLines($detailByTrx[$id] ?? []),
                'payments'         => $paymentsByTrx[$id] ?? [],
            ];
        }
    }

    jsonResponse(200, 'Success', [
        'summary' => [
            'transaction_count' => $total,
            'items_sold'        => (int)$summaryRow['items_sold'],
            'total_revenue'     => (int)round((float)$summaryRow['total_revenue']),
        ],
        'transactions' => $transactions,
        'pagination' => [
            'total'       => $total,
            'page'        => $page,
            'limit'       => $limit,
            'total_pages' => (int)ceil($total / $limit),
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
    http_response_code(200);
    exit();
}

$headers = function_exists('getallheaders') ? getallheaders() : [];

// Case-insensitive Authorization lookup (some servers return 'authorization')
$authHeader = null;
foreach ($headers as $k => $v) {
    if (strtolower($k) === 'authorization') {
        $authHeader = $v;
        break;
    }
}
if (!$authHeader) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
}
if (!$authHeader) {
    jsonResponse(401, 'Authorization header not found');
}

try {
    $token = preg_replace('/^Bearer\s+/i', '', trim($authHeader));
    try {
        $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
    } catch (Exception $e) {
        jsonResponse(401, 'Invalid or expired token', ['error' => $e->getMessage()]);
    }

    $conn = DB::conn();
    $schema = DB_SCHEMA;

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(405, 'Method Not Allowed');
    }

    getPosHistory($conn, $schema, $_GET, $decoded);

} catch (Exception $e) {
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/pos/history.php',
        'method'        => $_SERVER['REQUEST_METHOD'] ?? null,
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);

    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
