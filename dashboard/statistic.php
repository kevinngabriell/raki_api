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

    // Per-outlet figures need access to the outlet; the all-outlets mode (no company_id) is Owner only.
    $actor = $GLOBALS['decoded'] ?? null;
    if ($actor) {
        if (!empty($company_id)) {
            requireCompanyAccess($conn, $actor, $company_id);
        } else {
            requireOwner($actor);
        }
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
    // Sums transaction_payment.amount, so a split payment (cash + QRIS) is not counted twice.
    $sqlPayment = "SELECT TP.payment_method, COALESCE(SUM(TP.amount), 0) AS total_trx, ROUND(100 * COALESCE(SUM(TP.amount), 0) / NULLIF((SELECT COALESCE(SUM(TP2.amount), 0) FROM {$schema}.`transaction` T2 JOIN {$schema}.transaction_payment TP2 ON TP2.transaction_id = T2.transaction_id WHERE T2.company_id {$trxOp} ? AND T2.transaction_date BETWEEN ? AND ?), 0), 2) AS percentage FROM {$schema}.`transaction` T JOIN {$schema}.transaction_payment TP ON T.transaction_id = TP.transaction_id {$whereTrxFrag} GROUP BY TP.payment_method";
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
    $sqlMenuRevenue = "SELECT M.menu_name, COALESCE(SUM(TD.subtotal), 0) AS total_trx, COALESCE(SUM(TD.quantity), 0) AS total_qty FROM {$schema}.`transaction` T LEFT JOIN {$schema}.transaction_detail TD ON T.transaction_id = TD.transaction_id AND TD.package_id IS NULL LEFT JOIN {$schema}.menu M ON TD.menu_id = M.menu_id {$whereTrxFrag} GROUP BY M.menu_id, M.menu_name ORDER BY total_trx DESC";
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

    // Item terjual = cups: add-ons are left out, a package counts each menu inside it.
    $stmtItems = $conn->prepare("SELECT COALESCE(SUM(TD.quantity), 0) FROM {$schema}.`transaction` T JOIN {$schema}.transaction_detail TD ON TD.transaction_id = T.transaction_id
        LEFT JOIN {$schema}.menu M ON M.menu_id = TD.menu_id LEFT JOIN {$schema}.category_menu CM ON CM.category_id = M.category_id
        {$whereTrxFrag} AND COALESCE(CM.category_name, '') NOT REGEXP '^(topping|add-?on)$'");
    $stmtItems->bind_param('sss', $companyParam, $startDate, $endDate);
    $stmtItems->execute();
    $summary['total_item'] = (int)$stmtItems->get_result()->fetch_row()[0];
    $stmtItems->close();

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
    // Per seller: a bundle counts for the abang it was recorded for. Items = cups (add-ons left out).
    $sqlCashierPerformance = "SELECT COALESCE(T.created_for, T.created_by) AS created_by, COUNT(*) AS trx_count, COALESCE(SUM(T.total_amount), 0) AS total_trx, COUNT(DISTINCT T.transaction_date) AS days_worked, COALESCE(SUM((SELECT SUM(TD.quantity) FROM {$schema}.transaction_detail TD LEFT JOIN {$schema}.menu M ON M.menu_id = TD.menu_id LEFT JOIN {$schema}.category_menu CM ON CM.category_id = M.category_id WHERE TD.transaction_id = T.transaction_id AND COALESCE(CM.category_name, '') NOT REGEXP '^(topping|add-?on)$')), 0) AS items FROM {$schema}.`transaction` T {$whereTrxFrag} GROUP BY COALESCE(T.created_for, T.created_by) ORDER BY trx_count DESC";
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
            'items'      => (int) ($row['items'] ?? 0),
            'days_worked' => (int) ($row['days_worked'] ?? 0),
        ];
    }
    $stmtCashier->close();

    // Display names of the sellers.
    if ($cashierStats) {
        $names = array_column($cashierStats, 'created_by');
        [$nin, $ntypes] = accessInList($names);
        $stmtNames = $conn->prepare("SELECT username, first_name, last_name FROM {$coreSchema}.app_user WHERE username IN ($nin)");
        $stmtNames->bind_param($ntypes, ...$names);
        $stmtNames->execute();
        $full = [];
        foreach ($stmtNames->get_result() as $u) {
            $full[$u['username']] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: $u['username'];
        }
        $stmtNames->close();
        foreach ($cashierStats as &$cs) {
            $cs['full_name'] = $full[$cs['created_by']] ?? $cs['created_by'];
        }
        unset($cs);
    }

    // Per seller per day (the Peringkat week strip): trx, omzet and cups.
    $stmtDay = $conn->prepare("SELECT COALESCE(T.created_for, T.created_by) AS created_by, T.transaction_date AS d, COUNT(*) AS trx_count, COALESCE(SUM(T.total_amount), 0) AS total_trx,
            COALESCE(SUM((SELECT SUM(TD.quantity) FROM {$schema}.transaction_detail TD LEFT JOIN {$schema}.menu M ON M.menu_id = TD.menu_id LEFT JOIN {$schema}.category_menu CM ON CM.category_id = M.category_id
                WHERE TD.transaction_id = T.transaction_id AND COALESCE(CM.category_name, '') NOT REGEXP '^(topping|add-?on)$')), 0) AS items
        FROM {$schema}.`transaction` T {$whereTrxFrag} GROUP BY COALESCE(T.created_for, T.created_by), T.transaction_date ORDER BY T.transaction_date, created_by");
    $stmtDay->bind_param('sss', $companyParam, $startDate, $endDate);
    $stmtDay->execute();
    $cashierByDate = [];
    foreach ($stmtDay->get_result() as $row) {
        $cashierByDate[] = ['date' => $row['d'], 'created_by' => $row['created_by'], 'trx_count' => (int)$row['trx_count'], 'total_trx' => (float)$row['total_trx'], 'items' => (int)$row['items']];
    }
    $stmtDay->close();

    // Paket terlaris: a package counts as the package (its menus are left out of menu_revenue).
    $stmtPkg = $conn->prepare("SELECT P.package_name, SUM(X.q) AS total_qty, COUNT(DISTINCT X.transaction_id) AS total_trx, SUM(X.amount) AS total_amount FROM (
            SELECT TD.transaction_id, TD.package_id, TD.line_no, MAX(TD.quantity) AS q, SUM(TD.subtotal) AS amount
            FROM {$schema}.`transaction` T JOIN {$schema}.transaction_detail TD ON TD.transaction_id = T.transaction_id AND TD.package_id IS NOT NULL
            {$whereTrxFrag} GROUP BY TD.transaction_id, TD.package_id, TD.line_no) X
        JOIN {$schema}.package P ON P.package_id = X.package_id GROUP BY P.package_id, P.package_name ORDER BY total_qty DESC");
    $stmtPkg->bind_param('sss', $companyParam, $startDate, $endDate);
    $stmtPkg->execute();
    $packageRevenue = [];
    foreach ($stmtPkg->get_result() as $row) {
        $packageRevenue[] = ['package_name' => $row['package_name'], 'total_qty' => (int)$row['total_qty'], 'total_trx' => (float)$row['total_amount'], 'total_orders' => (int)$row['total_trx']];
    }
    $stmtPkg->close();

    // Jam & hari ramai: average transactions per day of that weekday in the range (0 = Minggu).
    $stmtHour = $conn->prepare("SELECT DAYOFWEEK(T.transaction_date) - 1 AS weekday, HOUR(T.created_at) AS hour, COUNT(*) AS n FROM {$schema}.`transaction` T {$whereTrxFrag} GROUP BY weekday, hour");
    $stmtHour->bind_param('sss', $companyParam, $startDate, $endDate);
    $stmtHour->execute();
    $daysOfWeekday = array_fill(0, 7, 0);
    for ($d = new DateTime($startDate), $last = new DateTime($endDate); $d <= $last; $d->modify('+1 day')) {
        $daysOfWeekday[(int)$d->format('w')]++;
    }
    $trxByHour = [];
    foreach ($stmtHour->get_result() as $row) {
        $wd = (int)$row['weekday'];
        $trxByHour[] = ['weekday' => $wd, 'hour' => (int)$row['hour'], 'trx_count' => round($row['n'] / max(1, $daysOfWeekday[$wd]), 2)];
    }
    $stmtHour->close();

    // Promo & voucher: uses, discount given and omzet of those sales.
    $promoUsage = [];
    $stmtPromoUse = $conn->prepare("SELECT TPR.promo_name AS name, 'Promo' AS kind, COUNT(*) AS uses, COALESCE(SUM(TPR.discount_amount), 0) AS discount, COALESCE(SUM(T.total_amount), 0) AS omzet
        FROM {$schema}.transaction_promo TPR JOIN {$schema}.`transaction` T ON T.transaction_id = TPR.transaction_id {$whereTrxFrag} GROUP BY TPR.promo_id, TPR.promo_name
        UNION ALL
        SELECT V.voucher_name, 'Voucher', COUNT(*), COALESCE(SUM(VU.discount_amount), 0), COALESCE(SUM(T.total_amount), 0)
        FROM {$schema}.voucher_usage VU JOIN {$schema}.voucher V ON V.voucher_id = VU.voucher_id JOIN {$schema}.`transaction` T ON T.transaction_id = VU.transaction_id {$whereTrxFrag} GROUP BY V.voucher_id, V.voucher_name");
    $stmtPromoUse->bind_param('ssssss', $companyParam, $startDate, $endDate, $companyParam, $startDate, $endDate);
    $stmtPromoUse->execute();
    foreach ($stmtPromoUse->get_result() as $row) {
        $promoUsage[] = ['name' => $row['name'], 'kind' => $row['kind'], 'uses' => (int)$row['uses'], 'discount' => (int)$row['discount'], 'omzet' => (int)$row['omzet']];
    }
    $stmtPromoUse->close();
    usort($promoUsage, fn($a, $b) => $b['uses'] <=> $a['uses']);

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
        'cashier_by_date'       => $cashierByDate,
        'package_revenue'       => $packageRevenue,
        'trx_by_hour'           => $trxByHour,
        'promo_usage'           => $promoUsage,
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
            $GLOBALS['decoded'] = $decoded;
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
