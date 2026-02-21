<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function getDashboardStatistic($conn, $schema, $company_id = null, $username){
    //excluded for development company
    $excludedCompanyId = 'company691b31b41ea7b';
    $excludedEsc = mysqli_real_escape_string($conn, $excludedCompanyId);

    // Build filter untuk tabel transaction (alias T) dan supply_order (alias SO)
    if (!empty($company_id)) {
        $companyEsc = mysqli_real_escape_string($conn, $company_id);
        $whereTrx = "WHERE T.company_id = '$companyEsc'";
        $whereTrxNoAlias = "WHERE company_id = '$companyEsc'";
        $whereSo = "WHERE SO.from_company_id = '$companyEsc'";
    } else {
        $whereTrx = "WHERE T.company_id <> '$excludedEsc'";
        $whereTrxNoAlias = "WHERE company_id <> '$excludedEsc'";
        $whereSo = "WHERE SO.from_company_id <> '$excludedEsc'";
    }

    // Date range filter (optional). If not provided, default to last 30 days.
    $startDate = $_GET['start_date'] ?? null;
    $endDate   = $_GET['end_date'] ?? null;

    if (empty($startDate) || empty($endDate)) {
        // Default: last 30 days until today
        $endDate   = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-30 days'));
    }

    $startEsc = mysqli_real_escape_string($conn, $startDate);
    $endEsc   = mysqli_real_escape_string($conn, $endDate);

    // Apply date filter to transaction queries (transaction_date is DATE)
    $whereTrx       .= " AND T.transaction_date BETWEEN '$startEsc' AND '$endEsc'";
    $whereTrxNoAlias .= " AND transaction_date BETWEEN '$startEsc' AND '$endEsc'";

    // Apply date filter to supply_order queries (requested_at is DATETIME)
    $whereSo        .= " AND SO.requested_at BETWEEN '$startEsc 00:00:00' AND '$endEsc 23:59:59'";

    // Special where for ingredient stats: also exclude dev by to_company_id
    $whereSoIngredient = $whereSo . " AND SO.to_company_id <> '$excludedEsc'";

    // 1) Payment method breakdown (amount + percentage)
    $sqlPayment = "SELECT TP.payment_method, COALESCE(SUM(T.total_amount), 0) AS total_trx, ROUND( 100 * COALESCE(SUM(T.total_amount), 0) / NULLIF( ( SELECT COALESCE(SUM(total_amount), 0) FROM {$schema}.`transaction` $whereTrxNoAlias ), 0 ), 2 ) AS percentage FROM {$schema}.`transaction` T LEFT JOIN {$schema}.transaction_payment TP ON T.transaction_id = TP.transaction_id $whereTrx GROUP BY TP.payment_method";
    $paymentRes = mysqli_query($conn, $sqlPayment);

    if (!$paymentRes) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistics.php',
            'method'        => 'GET',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch payment stats', ['error' => mysqli_error($conn)]);
    }

    $paymentStats = [];
    while ($row = mysqli_fetch_assoc($paymentRes)) {
        $paymentStats[] = [
            'payment_method' => $row['payment_method'] ?? '-',
            'total_trx'      => (float) ($row['total_trx'] ?? 0),
            'percentage'     => (float) ($row['percentage'] ?? 0),
        ];
    }

    // 2) Revenue per menu (best seller by revenue)
    $sqlMenuRevenue = "SELECT COALESCE(SUM(T.total_amount), 0) AS total_trx, M.menu_name FROM {$schema}.`transaction` T LEFT JOIN {$schema}.transaction_detail TD ON T.transaction_id = TD.transaction_id LEFT JOIN {$schema}.menu M ON TD.menu_id = M.menu_id $whereTrx GROUP BY M.menu_id ORDER BY total_trx DESC";
    $menuRes = mysqli_query($conn, $sqlMenuRevenue);

    if (!$menuRes) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistics.php',
            'method'        => 'GET',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch menu revenue stats', ['error' => mysqli_error($conn)]);
    }

    $menuStats = [];
    while ($row = mysqli_fetch_assoc($menuRes)) {
        $menuStats[] = [
            'menu_name' => $row['menu_name'] ?? '-',
            'total_trx' => (float) ($row['total_trx'] ?? 0),
        ];
    }

    // 3) Revenue per creator (created_by)
    $sqlCreatedByRevenue = " SELECT COALESCE(SUM(T.total_amount), 0) AS total_trx, T.created_by FROM {$schema}.`transaction` T $whereTrx GROUP BY T.created_by ORDER BY total_trx DESC";
    $createdRes = mysqli_query($conn, $sqlCreatedByRevenue);

    if (!$createdRes) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistics.php',
            'method'        => 'GET',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch revenue by creator', ['error' => mysqli_error($conn)]);
    }

    $creatorStats = [];
    while ($row = mysqli_fetch_assoc($createdRes)) {
        $creatorStats[] = [
            'created_by' => $row['created_by'] ?? '-',
            'total_trx'  => (float) ($row['total_trx'] ?? 0),
        ];
    }

    // 4) Total supply order amount per ingredient + company (from_company)
    $sqlIngredientPurchase = "SELECT COALESCE(SUM(SO.total_amount), 0) AS total_trx, I.ingredient_name, AC.company_name FROM {$schema}.supply_order SO LEFT JOIN {$schema}.supply_order_detail SOD ON SO.supply_order_id = SOD.supply_order_id LEFT JOIN {$schema}.ingredient I ON SOD.ingredient_id = I.ingredient_id LEFT JOIN movira_core_dev.app_company AC ON SO.from_company_id = AC.company_id $whereSoIngredient GROUP BY I.ingredient_name, AC.company_name ORDER BY total_trx DESC";
    $ingRes = mysqli_query($conn, $sqlIngredientPurchase);

    if (!$ingRes) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistics.php',
            'method'        => 'GET',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch ingredient purchase stats', ['error' => mysqli_error($conn)]);
    }

    $ingredientStats = [];
    while ($row = mysqli_fetch_assoc($ingRes)) {
        $ingredientStats[] = [
            'ingredient_name' => $row['ingredient_name'] ?? '-',
            'company_name'    => $row['company_name'] ?? '-',
            'total_trx'       => (float) ($row['total_trx'] ?? 0),
        ];
    }

    // 5) Revenue by date
    $sqlRevenueByDate = "SELECT DATE(T.transaction_date) AS trx_date, COALESCE(SUM(T.total_amount), 0) AS total_trx FROM {$schema}.`transaction` T $whereTrx GROUP BY DATE(T.transaction_date) ORDER BY trx_date ASC";
    $revDateRes = mysqli_query($conn, $sqlRevenueByDate);

    if (!$revDateRes) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistics.php',
            'method'        => 'GET',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch revenue by date', ['error' => mysqli_error($conn)]);
    }

    $revenueByDate = [];
    while ($row = mysqli_fetch_assoc($revDateRes)) {
        $revenueByDate[] = [
            'trx_date'  => $row['trx_date'],
            'total_trx' => (float) ($row['total_trx'] ?? 0),
        ];
    }

    // 6) Summary: avg_order_value, total_trx, total_revenue
    $sqlSummary = "SELECT COALESCE(AVG(T.total_amount), 0) AS avg_order_value, COUNT(*) AS total_trx, COALESCE(SUM(T.total_amount), 0) AS total_revenue FROM {$schema}.`transaction` T $whereTrx";
    $sumRes = mysqli_query($conn, $sqlSummary);

    if (!$sumRes) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistics.php',
            'method'        => 'GET',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch summary stats', ['error' => mysqli_error($conn)]);
    }

    $sumRow = mysqli_fetch_assoc($sumRes) ?: [];
    $summary = [
        'avg_order_value' => (float) ($sumRow['avg_order_value'] ?? 0),
        'total_trx'       => (int) ($sumRow['total_trx'] ?? 0),
        'total_revenue'   => (float) ($sumRow['total_revenue'] ?? 0),
    ];

    // 7) Transaction count by date
    $sqlTrxCountByDate = "SELECT DATE(T.transaction_date) AS trx_date, COUNT(*) AS trx_count FROM {$schema}.`transaction` T $whereTrx GROUP BY DATE(T.transaction_date) ORDER BY trx_date DESC";
    $trxCountRes = mysqli_query($conn, $sqlTrxCountByDate);

    if (!$trxCountRes) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistics.php',
            'method'        => 'GET',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch transaction count by date', ['error' => mysqli_error($conn)]);
    }

    $trxCountByDate = [];
    while ($row = mysqli_fetch_assoc($trxCountRes)) {
        $trxCountByDate[] = [
            'trx_date'  => $row['trx_date'],
            'trx_count' => (int) ($row['trx_count'] ?? 0),
        ];
    }

    // 8) Cashier performance: trx_count + total_trx per created_by
    $sqlCashierPerformance = "SELECT T.created_by, COUNT(*) AS trx_count, COALESCE(SUM(T.total_amount), 0) AS total_trx FROM {$schema}.`transaction` T $whereTrx GROUP BY T.created_by ORDER BY trx_count DESC";
    $cashierRes = mysqli_query($conn, $sqlCashierPerformance);

    if (!$cashierRes) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistics.php',
            'method'        => 'GET',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch cashier performance', ['error' => mysqli_error($conn)]);
    }

    $cashierStats = [];
    while ($row = mysqli_fetch_assoc($cashierRes)) {
        $cashierStats[] = [
            'created_by' => $row['created_by'] ?? '-',
            'trx_count'  => (int) ($row['trx_count'] ?? 0),
            'total_trx'  => (float) ($row['total_trx'] ?? 0),
        ];
    }

    // 9) Purchase per date (supply_order)
    $sqlPurchaseByDate = "SELECT DATE(SO.requested_at) AS order_date, COALESCE(SUM(SO.total_amount), 0) AS total_purchase FROM {$schema}.supply_order SO $whereSo GROUP BY DATE(SO.requested_at) ORDER BY order_date DESC";
    $purchaseRes = mysqli_query($conn, $sqlPurchaseByDate);

    if (!$purchaseRes) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistics.php',
            'method'        => 'GET',
            'error_message' => mysqli_error($conn),
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch purchase by date', ['error' => mysqli_error($conn)]);
    }

    $purchaseByDate = [];
    while ($row = mysqli_fetch_assoc($purchaseRes)) {
        $purchaseByDate[] = [
            'order_date'     => $row['order_date'],
            'total_purchase' => (float) ($row['total_purchase'] ?? 0),
        ];
    }

    jsonResponse(200, 'Statistic fetched', [
        'filter' => [
            'company_id'        => $company_id,
            'excluded_company'  => $excludedCompanyId,
            'mode'              => !empty($company_id) ? 'single_company' : 'all_except_dev',
            'start_date'        => $startDate,
            'end_date'          => $endDate,
        ],
        'payment_method'        => $paymentStats,
        'menu_revenue'          => $menuStats,
        'revenue_by_creator'    => $creatorStats,
        'ingredient_purchase'   => $ingredientStats,
        'revenue_by_date'       => $revenueByDate,
        'summary'               => $summary,
        'trx_count_by_date'     => $trxCountByDate,
        'cashier_performance'   => $cashierStats,
        'purchase_by_date'      => $purchaseByDate,
    ]);
}

$headers = getallheaders();

if (!isset($headers['Authorization'])) {
    $conn = DB::conn();

    logApiError($conn, [
        'error_level'   => 'error',
        'http_status'   => 401,
        'endpoint'      => '/dashboard/statistics.php',
        'method'        => 'GET',
        'error_message' => 'Authorization header not found',
        'user_identifier' => $username ?? null,
        'company_id'      => $company_id ?? null,
    ]);
    jsonResponse(401, 'Authorization header not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
    http_response_code(200);
    exit();
}

try {
    $token = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $conn = DB::conn();
    $schema = DB_SCHEMA;

    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'GET':
            $company_id = $_GET['company_id'] ?? null;
            getDashboardStatistic($conn, $schema, $company_id, $token_username);
            break;
        default:
            logApiError($conn, [
                'error_level'   => 'error',
                'http_status'   => 405,
                'endpoint'      => '/dashboard/statistic.php',
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
        'endpoint'      => '/dashboard/statistics.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}

?>