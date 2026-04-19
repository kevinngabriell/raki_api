<?php
// Shared data functions for weekly report — included by weekly_report.php and weekly_report_email.php

function buildPeriodLabel(string $start, string $end): string {
    $months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $s = new DateTime($start);
    $e = new DateTime($end);
    $startLabel = (int)$s->format('d') . ' ' . $months[(int)$s->format('m') - 1];
    $endLabel   = (int)$e->format('d') . ' ' . $months[(int)$e->format('m') - 1] . ' ' . $e->format('Y');
    return $startLabel . ' – ' . $endLabel;
}

function calcGrowth($current, $previous): ?float {
    if ($previous == 0) return null;
    return round(($current - $previous) / $previous * 100, 1);
}

function fetchCompanySummary($conn, $schema, string $start, string $end, $companyParam, bool $isExclude): array {
    $op  = $isExclude ? '!=' : '=';
    $sql = "SELECT
                ac.company_id,
                ac.company_name,
                COALESCE(SUM(t.total_amount), 0)  AS total_revenue,
                COUNT(t.transaction_id)            AS total_trx,
                COALESCE(AVG(t.total_amount), 0)  AS avg_per_trx
            FROM {$schema}.`transaction` t
            JOIN movira_core_dev.app_company ac ON ac.company_id = t.company_id
            WHERE t.transaction_date BETWEEN ? AND ?
              AND t.company_id {$op} ?
            GROUP BY t.company_id, ac.company_name
            ORDER BY total_revenue DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $start, $end, $companyParam);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[$row['company_id']] = [
            'company_id'    => $row['company_id'],
            'company_name'  => $row['company_name'],
            'total_revenue' => (float)$row['total_revenue'],
            'total_trx'     => (int)$row['total_trx'],
            'avg_per_trx'   => (float)$row['avg_per_trx'],
        ];
    }
    $stmt->close();
    return $rows;
}

function fetchPaymentBreakdown($conn, $schema, string $start, string $end, $companyParam, bool $isExclude): array {
    $op  = $isExclude ? '!=' : '=';
    $sql = "SELECT
                t.company_id,
                tp.payment_method,
                COALESCE(SUM(tp.amount), 0)          AS total_amount,
                COUNT(DISTINCT t.transaction_id)      AS trx_count
            FROM {$schema}.`transaction` t
            JOIN {$schema}.transaction_payment tp ON tp.transaction_id = t.transaction_id
            WHERE t.transaction_date BETWEEN ? AND ?
              AND t.company_id {$op} ?
            GROUP BY t.company_id, tp.payment_method";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $start, $end, $companyParam);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $cid    = $row['company_id'];
        $method = $row['payment_method'];
        if (!isset($rows[$cid])) $rows[$cid] = [];
        $rows[$cid][$method] = [
            'amount'    => (float)$row['total_amount'],
            'trx_count' => (int)$row['trx_count'],
        ];
    }
    $stmt->close();
    return $rows;
}

function fetchCashierPerformance($conn, $schema, string $start, string $end, $companyParam, bool $isExclude): array {
    $op  = $isExclude ? '!=' : '=';
    $sql = "SELECT
                t.created_by,
                t.company_id,
                ac.company_name,
                COUNT(t.transaction_id)            AS trx_count,
                COALESCE(SUM(t.total_amount), 0)   AS total_revenue
            FROM {$schema}.`transaction` t
            JOIN movira_core_dev.app_company ac ON ac.company_id = t.company_id
            WHERE t.transaction_date BETWEEN ? AND ?
              AND t.company_id {$op} ?
            GROUP BY t.created_by, t.company_id, ac.company_name
            ORDER BY total_revenue DESC";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sss', $start, $end, $companyParam);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'username'      => $row['created_by'] ?? '-',
            'company_id'    => $row['company_id'],
            'company_name'  => $row['company_name'],
            'trx_count'     => (int)$row['trx_count'],
            'total_revenue' => (float)$row['total_revenue'],
        ];
    }
    $stmt->close();
    return $rows;
}

function fetchTopProducts($conn, $schema, string $start, string $end, $companyParam, bool $isExclude, int $limit = 10): array {
    $op  = $isExclude ? '!=' : '=';
    $sql = "SELECT
                m.menu_id,
                m.menu_name,
                COALESCE(SUM(td.quantity), 0)  AS qty_sold,
                COALESCE(SUM(td.subtotal), 0)  AS total_revenue
            FROM {$schema}.`transaction` t
            JOIN {$schema}.transaction_detail td ON td.transaction_id = t.transaction_id
            JOIN {$schema}.menu m ON m.menu_id = td.menu_id
            WHERE t.transaction_date BETWEEN ? AND ?
              AND t.company_id {$op} ?
            GROUP BY td.menu_id, m.menu_name
            ORDER BY qty_sold DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssi', $start, $end, $companyParam, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'menu_id'       => $row['menu_id'],
            'menu_name'     => $row['menu_name'],
            'qty_sold'      => (int)$row['qty_sold'],
            'total_revenue' => (float)$row['total_revenue'],
        ];
    }
    $stmt->close();
    return $rows;
}

function buildWeeklyReportData($conn, $schema, $company_id, $start_date, $end_date): array {
    $excludedCompanyId = 'company691b31b41ea7b';

    if (empty($start_date) || empty($end_date)) {
        $today      = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        $dayOfWeek  = (int)$today->format('N');
        $lastSunday = clone $today;
        $lastSunday->modify("-{$dayOfWeek} days");
        $lastMonday = clone $lastSunday;
        $lastMonday->modify('-6 days');
        $start_date = $lastMonday->format('Y-m-d');
        $end_date   = $lastSunday->format('Y-m-d');
    }

    $prevStart    = date('Y-m-d', strtotime($start_date . ' -7 days'));
    $prevEnd      = date('Y-m-d', strtotime($end_date   . ' -7 days'));
    $isExclude    = empty($company_id);
    $companyParam = $isExclude ? $excludedCompanyId : $company_id;

    $currentSummary   = fetchCompanySummary($conn, $schema, $start_date, $end_date, $companyParam, $isExclude);
    $prevSummary      = fetchCompanySummary($conn, $schema, $prevStart,  $prevEnd,  $companyParam, $isExclude);
    $paymentBreakdown = fetchPaymentBreakdown($conn, $schema, $start_date, $end_date, $companyParam, $isExclude);
    $cashierPerf      = fetchCashierPerformance($conn, $schema, $start_date, $end_date, $companyParam, $isExclude);
    $topProducts      = fetchTopProducts($conn, $schema, $start_date, $end_date, $companyParam, $isExclude, 10);

    $companies = [];
    foreach ($currentSummary as $cid => $cur) {
        $prev     = $prevSummary[$cid] ?? ['total_revenue' => 0, 'total_trx' => 0, 'avg_per_trx' => 0];
        $payments = $paymentBreakdown[$cid] ?? [];

        $companies[] = [
            'company_id'    => $cid,
            'company_name'  => $cur['company_name'],
            'current_week'  => [
                'total_revenue' => $cur['total_revenue'],
                'total_trx'     => $cur['total_trx'],
                'avg_per_trx'   => round($cur['avg_per_trx']),
            ],
            'previous_week' => [
                'total_revenue' => $prev['total_revenue'],
                'total_trx'     => $prev['total_trx'],
                'avg_per_trx'   => round($prev['avg_per_trx']),
            ],
            'growth' => [
                'revenue_pct' => calcGrowth($cur['total_revenue'], $prev['total_revenue']),
                'trx_pct'     => calcGrowth($cur['total_trx'],     $prev['total_trx']),
                'avg_pct'     => calcGrowth($cur['avg_per_trx'],   $prev['avg_per_trx']),
            ],
            'payment_breakdown' => array_filter($payments, fn($p) => $p['amount'] > 0 || $p['trx_count'] > 0),
        ];
    }

    return [
        'period' => [
            'start_date' => $start_date,
            'end_date'   => $end_date,
            'label'      => buildPeriodLabel($start_date, $end_date),
            'prev_start' => $prevStart,
            'prev_end'   => $prevEnd,
        ],
        'companies'           => $companies,
        'cashier_performance' => $cashierPerf,
        'top_products'        => $topProducts,
    ];
}
