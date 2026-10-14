<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function formatWeekRangeId(string $start, string $end): string {
    $months = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];
    $s   = new DateTime($start);
    $e   = new DateTime($end);
    $sm  = $months[(int)$s->format('n')];
    $em  = $months[(int)$e->format('n')];
    $sy  = $s->format('Y');
    $ey  = $e->format('Y');

    if ($sy !== $ey) {
        return $s->format('j') . ' ' . $sm . ' ' . $sy . ' – ' . $e->format('j') . ' ' . $em . ' ' . $ey;
    }
    if ($sm !== $em) {
        return $s->format('j') . ' ' . $sm . ' – ' . $e->format('j') . ' ' . $em . ' ' . $sy;
    }
    return $s->format('j') . ' – ' . $e->format('j') . ' ' . $em . ' ' . $sy;
}

function getDriverStats(mysqli $conn, string $schema, string $company_id, string $username): void {
    // ── Week boundaries (Mon–Sat, Jakarta time) ────────────────────────────────
    $tz    = new DateTimeZone('Asia/Jakarta');
    $today = new DateTime('now', $tz);
    $dow   = (int)$today->format('N');
    $mon   = (clone $today)->modify('-' . ($dow - 1) . ' days');
    $sat   = (clone $mon)->modify('+5 days');

    $weekStart = $mon->format('Y-m-d');
    $weekEnd   = $sat->format('Y-m-d');
    $todayDate = $today->format('Y-m-d');

    // ── 1. Cup sold this week ──────────────────────────────────────────────────
    $stmtCup = $conn->prepare("SELECT COALESCE(SUM(total_item), 0) AS cup_sold FROM {$schema}.transaction WHERE company_id = ? AND created_by = ? AND DATE(transaction_date) BETWEEN ? AND ?");
    $stmtCup->bind_param('ssss', $company_id, $username, $weekStart, $weekEnd);
    $stmtCup->execute();
    $cup_sold = (int)($stmtCup->get_result()->fetch_assoc()['cup_sold'] ?? 0);
    $stmtCup->close();

    // ── 2. Weekly daily breakdown ──────────────────────────────────────────────
    $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $stmtDaily = $conn->prepare("SELECT DATE(transaction_date) AS trx_day, COALESCE(SUM(total_item), 0) AS cups FROM {$schema}.transaction WHERE company_id = ? AND created_by = ? AND DATE(transaction_date) BETWEEN ? AND ? GROUP BY DATE(transaction_date) ORDER BY trx_day ASC");
    $stmtDaily->bind_param('ssss', $company_id, $username, $weekStart, $weekEnd);
    $stmtDaily->execute();
    $rDaily = $stmtDaily->get_result();
    $stmtDaily->close();

    $dailyMap = [];
    while ($row = $rDaily->fetch_assoc()) {
        $dailyMap[$row['trx_day']] = (int)$row['cups'];
    }

    $weekly_data = [];
    for ($i = 0; $i < 6; $i++) {
        $d    = (clone $mon)->modify("+{$i} days");
        $date = $d->format('Y-m-d');
        $weekly_data[] = [
            'date' => $date,
            'day'  => $dayNames[$i],
            'cups' => $dailyMap[$date] ?? 0,
        ];
    }

    // ── 3. Bonus tier info ─────────────────────────────────────────────────────
    $stmtBonus = $conn->prepare("SELECT schema_id, schema_name, qty, bonus_nominal FROM {$schema}.bonus_schema WHERE company_id = ? AND frequency = 'weekly' AND is_active = 1 ORDER BY qty ASC");
    $stmtBonus->bind_param('s', $company_id);
    $stmtBonus->execute();
    $rSchema = $stmtBonus->get_result();
    $stmtBonus->close();

    $bonus_schemas = [];
    while ($row = $rSchema->fetch_assoc()) {
        $bonus_schemas[] = $row;
    }

    $current_tier = null;
    foreach ($bonus_schemas as $s) {
        if ((int)$s['qty'] <= $cup_sold) {
            $current_tier = [
                'schema_id'     => $s['schema_id'],
                'schema_name'   => $s['schema_name'],
                'target_qty'    => (int)$s['qty'],
                'bonus_nominal' => (int)$s['bonus_nominal'],
            ];
        }
    }

    $next_tier = null;
    foreach ($bonus_schemas as $s) {
        if ((int)$s['qty'] > $cup_sold) {
            $remaining       = (int)$s['qty'] - $cup_sold;
            $next_tier = [
                'schema_id'          => $s['schema_id'],
                'schema_name'        => $s['schema_name'],
                'target_qty'         => (int)$s['qty'],
                'bonus_nominal'      => (int)$s['bonus_nominal'],
                'cups_to_next'       => $remaining,
                'progress_percentage'=> min(100, (int)round(($cup_sold / (int)$s['qty']) * 100)),
            ];
            break;
        }
    }

    $cup_target = !empty($bonus_schemas)
        ? (int)end($bonus_schemas)['qty']
        : 0;

    // ── 4. Top product this week ───────────────────────────────────────────────
    $stmtTop = $conn->prepare("SELECT m.menu_name, SUM(td.quantity) AS total_qty, SUM(td.subtotal) AS total_revenue FROM {$schema}.transaction_detail td JOIN {$schema}.menu m ON m.menu_id = td.menu_id JOIN {$schema}.transaction t ON t.transaction_id = td.transaction_id WHERE t.company_id = ? AND t.created_by = ? AND DATE(t.transaction_date) BETWEEN ? AND ? GROUP BY td.menu_id, m.menu_name ORDER BY total_qty DESC LIMIT 1");
    $stmtTop->bind_param('ssss', $company_id, $username, $weekStart, $weekEnd);
    $stmtTop->execute();
    $rTop = $stmtTop->get_result();
    $stmtTop->close();

    $topRow      = $rTop->fetch_assoc();
    $top_product = $topRow ? [
        'menu_name'     => $topRow['menu_name'],
        'total_qty'     => (int)$topRow['total_qty'],
        'total_revenue' => (int)$topRow['total_revenue'],
    ] : null;

    // ── 5. Revenue today ───────────────────────────────────────────────────────
    $stmtRev = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) AS revenue FROM {$schema}.transaction WHERE company_id = ? AND created_by = ? AND transaction_date = ?");
    $stmtRev->bind_param('sss', $company_id, $username, $todayDate);
    $stmtRev->execute();
    $revenue_today = (int)($stmtRev->get_result()->fetch_assoc()['revenue'] ?? 0);
    $stmtRev->close();

    // ── 6. Cup sold today ──────────────────────────────────────────────────────
    $stmtCupToday = $conn->prepare("SELECT COALESCE(SUM(total_item), 0) AS cup_today FROM {$schema}.transaction WHERE company_id = ? AND created_by = ? AND transaction_date = ?");
    $stmtCupToday->bind_param('sss', $company_id, $username, $todayDate);
    $stmtCupToday->execute();
    $cup_sold_today = (int)($stmtCupToday->get_result()->fetch_assoc()['cup_today'] ?? 0);
    $stmtCupToday->close();

    jsonResponse(200, 'Driver stats found', [
        'cup_sold'       => $cup_sold,
        'cup_target'     => $cup_target,
        'cup_sold_today' => $cup_sold_today,
        'revenue_today'  => $revenue_today,
        'week_range'     => formatWeekRangeId($weekStart, $weekEnd),
        'period'         => [
            'start' => $weekStart,
            'end'   => $weekEnd,
        ],
        'weekly_data'    => $weekly_data,
        'tier_info'      => [
            'current_tier' => $current_tier,
            'next_tier'    => $next_tier,
        ],
        'top_product'    => $top_product,
    ]);
}

$decoded = requireAuth();

$conn   = DB::conn();
$schema = DB_SCHEMA;
$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 405,
        'endpoint'        => '/session/driver-stats.php',
        'method'          => $method,
        'error_message'   => 'Method Not Allowed',
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(405, 'Method Not Allowed');
}

$company_id = $_GET['company_id'] ?? $decoded->company_id ?? null;
$username   = $_GET['username']   ?? $decoded->username   ?? null;

if (!$company_id || !$username) {
    jsonResponse(400, 'company_id and username are required');
}

getDriverStats($conn, $schema, $company_id, $username);
