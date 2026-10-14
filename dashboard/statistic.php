<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

function getDashboardStatistic($conn, $schema, $username, $company_id = null){
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $excludedCompanyId = 'company691b31b41ea7b';

    if (!empty($company_id)) {
        $companyParam = $company_id;
        $isExclude = false;
    } else {
        $companyParam = $excludedCompanyId;
        $isExclude = true;
    }

    $startDate = $_GET['start_date'] ?? null;
    $endDate   = $_GET['end_date'] ?? null;

    if (empty($startDate) || empty($endDate)) {
        $endDate   = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-30 days'));
    }

    $startDateTime = $startDate . ' 00:00:00';
    $endDateTime   = $endDate . ' 23:59:59';

    $trxOp = $isExclude ? '<>' : '=';

    $whereTrxFrag        = "WHERE T.company_id {$trxOp} ? AND T.transaction_date BETWEEN ? AND ?";
    $whereTrxNoAliasFrag = "WHERE company_id {$trxOp} ? AND transaction_date BETWEEN ? AND ?";
    $whereSoFrag         = "WHERE SO.from_company_id {$trxOp} ? AND SO.requested_at BETWEEN ? AND ?";

    // 1) Payment method breakdown (amount + percentage)
    $sqlPayment = "SELECT TP.payment_method, COALESCE(SUM(T.total_amount), 0) AS total_trx, ROUND( 100 * COALESCE(SUM(T.total_amount), 0) / NULLIF( ( SELECT COALESCE(SUM(total_amount), 0) FROM {$schema}.`transaction` {$whereTrxNoAliasFrag} ), 0 ), 2 ) AS percentage FROM {$schema}.`transaction` T LEFT JOIN {$schema}.transaction_payment TP ON T.transaction_id = TP.transaction_id {$whereTrxFrag} GROUP BY TP.payment_method";
    $stmtPayment = $conn->prepare($sqlPayment);
    if (!$stmtPayment) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistic.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for payment stats',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch payment stats');
    }
    $stmtPayment->bind_param('ssssss', $companyParam, $startDate, $endDate, $companyParam, $startDate, $endDate);
    $stmtPayment->execute();
    $paymentRes = $stmtPayment->get_result();

    $paymentStats = [];
    while ($row = $paymentRes->fetch_assoc()) {
        $paymentStats[] = [
            'payment_method' => $row['payment_method'] ?? '-',
            'total_trx'      => (float) ($row['total_trx'] ?? 0),
            'percentage'     => (float) ($row['percentage'] ?? 0),
        ];
    }
    $stmtPayment->close();

    // 2) Revenue per menu (best seller by revenue)
    $sqlMenuRevenue = "SELECT M.menu_name, COALESCE(SUM(TD.subtotal), 0) AS total_trx, COALESCE(SUM(TD.quantity), 0) AS total_qty FROM {$schema}.`transaction` T LEFT JOIN {$schema}.transaction_detail TD ON T.transaction_id = TD.transaction_id LEFT JOIN {$schema}.menu M ON TD.menu_id = M.menu_id {$whereTrxFrag} GROUP BY M.menu_id, M.menu_name ORDER BY total_trx DESC";
    $stmtMenu = $conn->prepare($sqlMenuRevenue);
    if (!$stmtMenu) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistic.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for menu revenue',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch menu revenue stats');
    }
    $stmtMenu->bind_param('sss', $companyParam, $startDate, $endDate);
    $stmtMenu->execute();
    $menuRes = $stmtMenu->get_result();

    $menuStats = [];
    while ($row = $menuRes->fetch_assoc()) {
        $menuStats[] = [
            'menu_name' => $row['menu_name'] ?? '-',
            'total_trx' => (float) ($row['total_trx'] ?? 0),
            'total_qty' => (int) ($row['total_qty'] ?? 0),
        ];
    }
    $stmtMenu->close();

    // 3) Revenue per creator (created_by)
    $sqlCreatedByRevenue = "SELECT COALESCE(SUM(T.total_amount), 0) AS total_trx, T.created_by FROM {$schema}.`transaction` T {$whereTrxFrag} GROUP BY T.created_by ORDER BY total_trx DESC";
    $stmtCreated = $conn->prepare($sqlCreatedByRevenue);
    if (!$stmtCreated) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistic.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for revenue by creator',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch revenue by creator');
    }
    $stmtCreated->bind_param('sss', $companyParam, $startDate, $endDate);
    $stmtCreated->execute();
    $createdRes = $stmtCreated->get_result();

    $creatorStats = [];
    while ($row = $createdRes->fetch_assoc()) {
        $creatorStats[] = [
            'created_by' => $row['created_by'] ?? '-',
            'total_trx'  => (float) ($row['total_trx'] ?? 0),
        ];
    }
    $stmtCreated->close();

    // 4) Total supply order amount per ingredient + company (from_company)
    $sqlIngredientPurchase = "SELECT COALESCE(SUM(SO.total_amount), 0) AS total_trx, I.ingredient_name, AC.company_name FROM {$schema}.supply_order SO LEFT JOIN {$schema}.supply_order_detail SOD ON SO.supply_order_id = SOD.supply_order_id LEFT JOIN {$schema}.ingredient I ON SOD.ingredient_id = I.ingredient_id LEFT JOIN {$coreSchema}.app_company AC ON SO.from_company_id = AC.company_id {$whereSoFrag} AND SO.to_company_id <> ? GROUP BY I.ingredient_name, AC.company_name ORDER BY total_trx DESC";
    $stmtIng = $conn->prepare($sqlIngredientPurchase);
    if (!$stmtIng) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistic.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for ingredient purchase',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch ingredient purchase stats');
    }
    $stmtIng->bind_param('ssss', $companyParam, $startDateTime, $endDateTime, $excludedCompanyId);
    $stmtIng->execute();
    $ingRes = $stmtIng->get_result();

    $ingredientStats = [];
    while ($row = $ingRes->fetch_assoc()) {
        $ingredientStats[] = [
            'ingredient_name' => $row['ingredient_name'] ?? '-',
            'company_name'    => $row['company_name'] ?? '-',
            'total_trx'       => (float) ($row['total_trx'] ?? 0),
        ];
    }
    $stmtIng->close();

    // 5) Revenue by date
    $sqlRevenueByDate = "SELECT DATE(T.transaction_date) AS trx_date, COALESCE(SUM(T.total_amount), 0) AS total_trx FROM {$schema}.`transaction` T {$whereTrxFrag} GROUP BY DATE(T.transaction_date) ORDER BY trx_date ASC";
    $stmtRevDate = $conn->prepare($sqlRevenueByDate);
    if (!$stmtRevDate) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistic.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for revenue by date',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch revenue by date');
    }
    $stmtRevDate->bind_param('sss', $companyParam, $startDate, $endDate);
    $stmtRevDate->execute();
    $revDateRes = $stmtRevDate->get_result();

    $revenueByDate = [];
    while ($row = $revDateRes->fetch_assoc()) {
        $revenueByDate[] = [
            'trx_date'  => $row['trx_date'],
            'total_trx' => (float) ($row['total_trx'] ?? 0),
        ];
    }
    $stmtRevDate->close();

    // 6) Summary: avg_order_value, total_trx, total_revenue
    $sqlSummary = "SELECT COALESCE(AVG(T.total_amount), 0) AS avg_order_value, COUNT(*) AS total_trx, COALESCE(SUM(T.total_amount), 0) AS total_revenue FROM {$schema}.`transaction` T {$whereTrxFrag}";
    $stmtSummary = $conn->prepare($sqlSummary);
    if (!$stmtSummary) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistic.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for summary',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch summary stats');
    }
    $stmtSummary->bind_param('sss', $companyParam, $startDate, $endDate);
    $stmtSummary->execute();
    $sumRes = $stmtSummary->get_result();

    $sumRow = $sumRes->fetch_assoc() ?: [];
    $summary = [
        'avg_order_value' => (float) ($sumRow['avg_order_value'] ?? 0),
        'total_trx'       => (int) ($sumRow['total_trx'] ?? 0),
        'total_revenue'   => (float) ($sumRow['total_revenue'] ?? 0),
    ];
    $stmtSummary->close();

    // 7) Transaction count by date
    $sqlTrxCountByDate = "SELECT DATE(T.transaction_date) AS trx_date, COUNT(*) AS trx_count FROM {$schema}.`transaction` T {$whereTrxFrag} GROUP BY DATE(T.transaction_date) ORDER BY trx_date DESC";
    $stmtTrxCount = $conn->prepare($sqlTrxCountByDate);
    if (!$stmtTrxCount) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistic.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for trx count by date',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch transaction count by date');
    }
    $stmtTrxCount->bind_param('sss', $companyParam, $startDate, $endDate);
    $stmtTrxCount->execute();
    $trxCountRes = $stmtTrxCount->get_result();

    $trxCountByDate = [];
    while ($row = $trxCountRes->fetch_assoc()) {
        $trxCountByDate[] = [
            'trx_date'  => $row['trx_date'],
            'trx_count' => (int) ($row['trx_count'] ?? 0),
        ];
    }
    $stmtTrxCount->close();

    // 8) Cashier performance: trx_count + total_trx per created_by
    $sqlCashierPerformance = "SELECT T.created_by, COUNT(*) AS trx_count, COALESCE(SUM(T.total_amount), 0) AS total_trx FROM {$schema}.`transaction` T {$whereTrxFrag} GROUP BY T.created_by ORDER BY trx_count DESC";
    $stmtCashier = $conn->prepare($sqlCashierPerformance);
    if (!$stmtCashier) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistic.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for cashier performance',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch cashier performance');
    }
    $stmtCashier->bind_param('sss', $companyParam, $startDate, $endDate);
    $stmtCashier->execute();
    $cashierRes = $stmtCashier->get_result();

    $cashierStats = [];
    while ($row = $cashierRes->fetch_assoc()) {
        $cashierStats[] = [
            'created_by' => $row['created_by'] ?? '-',
            'trx_count'  => (int) ($row['trx_count'] ?? 0),
            'total_trx'  => (float) ($row['total_trx'] ?? 0),
        ];
    }
    $stmtCashier->close();

    // 9) Purchase per date (supply_order)
    $sqlPurchaseByDate = "SELECT DATE(SO.requested_at) AS order_date, COALESCE(SUM(SO.total_amount), 0) AS total_purchase FROM {$schema}.supply_order SO {$whereSoFrag} GROUP BY DATE(SO.requested_at) ORDER BY order_date DESC";
    $stmtPurchase = $conn->prepare($sqlPurchaseByDate);
    if (!$stmtPurchase) {
        logApiError($conn, [
            'error_level'   => 'error',
            'http_status'   => 500,
            'endpoint'      => '/dashboard/statistic.php',
            'method'        => 'GET',
            'error_message' => 'Failed to prepare statement for purchase by date',
            'user_identifier' => $username ?? null,
            'company_id'      => $company_id ?? null,
        ]);
        jsonResponse(500, 'Failed to fetch purchase by date');
    }
    $stmtPurchase->bind_param('sss', $companyParam, $startDateTime, $endDateTime);
    $stmtPurchase->execute();
    $purchaseRes = $stmtPurchase->get_result();

    $purchaseByDate = [];
    while ($row = $purchaseRes->fetch_assoc()) {
        $purchaseByDate[] = [
            'order_date'     => $row['order_date'],
            'total_purchase' => (float) ($row['total_purchase'] ?? 0),
        ];
    }
    $stmtPurchase->close();

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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $decoded = requireAuth();

    $conn = DB::conn();
    $schema = DB_SCHEMA;

    $method = $_SERVER['REQUEST_METHOD'];

    switch($method){
        case 'GET':
            $company_id = $_GET['company_id'] ?? null;
            getDashboardStatistic($conn, $schema, $decoded->username, $company_id);
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
        'endpoint'      => '/dashboard/statistic.php',
        'method'        => '',
        'error_message' => $e->getMessage(),
        'user_identifier' => null,
        'company_id'      => null,
    ]);

    jsonResponse(500, 'Internal Server Error');
}

?>
