<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Authorization, Content-Type");
    header("Access-Control-Allow-Credentials: true");
    http_response_code(204);
    exit();
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ─── Indonesian month names ───────────────────────────────────────────────────
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
    $company_id_esc = mysqli_real_escape_string($conn, $company_id);
    $username_esc   = mysqli_real_escape_string($conn, $username);

    // ── Week boundaries (Mon–Sat, Jakarta time, bonus paid every Saturday) ────
    $tz    = new DateTimeZone('Asia/Jakarta');
    $today = new DateTime('now', $tz);
    $dow   = (int)$today->format('N'); // 1=Mon … 7=Sun
    $mon   = (clone $today)->modify('-' . ($dow - 1) . ' days');
    $sat   = (clone $mon)->modify('+5 days');

    $weekStart = $mon->format('Y-m-d');
    $weekEnd   = $sat->format('Y-m-d');
    $todayDate = $today->format('Y-m-d');

    // ── 1. Cup sold this week ─────────────────────────────────────────────────
    $rCup = mysqli_query($conn,
        "SELECT COALESCE(SUM(total_item), 0) AS cup_sold
         FROM {$schema}.transaction
         WHERE company_id  = '$company_id_esc'
           AND created_by  = '$username_esc'
           AND DATE(transaction_date) BETWEEN '$weekStart' AND '$weekEnd'"
    );
    $cup_sold = (int)(mysqli_fetch_assoc($rCup)['cup_sold'] ?? 0);

    // ── 2. Weekly daily breakdown ─────────────────────────────────────────────
    $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $rDaily = mysqli_query($conn,
        "SELECT DATE(transaction_date) AS trx_day, COALESCE(SUM(total_item), 0) AS cups
         FROM {$schema}.transaction
         WHERE company_id  = '$company_id_esc'
           AND created_by  = '$username_esc'
           AND DATE(transaction_date) BETWEEN '$weekStart' AND '$weekEnd'
         GROUP BY DATE(transaction_date)
         ORDER BY trx_day ASC"
    );
    $dailyMap = [];
    while ($row = mysqli_fetch_assoc($rDaily)) {
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

    // ── 3. Bonus tier info ────────────────────────────────────────────────────
    $rSchema = mysqli_query($conn,
        "SELECT schema_id, schema_name, qty, bonus_nominal
         FROM {$schema}.bonus_schema
         WHERE company_id = '$company_id_esc'
           AND frequency  = 'weekly'
           AND is_active  = 1
         ORDER BY qty ASC"
    );
    $bonus_schemas = $rSchema ? mysqli_fetch_all($rSchema, MYSQLI_ASSOC) : [];

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

    // cup_target = highest bonus tier qty (overall weekly goal)
    $cup_target = !empty($bonus_schemas)
        ? (int)end($bonus_schemas)['qty']
        : 0;

    // ── 4. Top product this week ──────────────────────────────────────────────
    $rTop = mysqli_query($conn,
        "SELECT m.menu_name,
                SUM(td.quantity) AS total_qty,
                SUM(td.subtotal) AS total_revenue
         FROM {$schema}.transaction_detail td
         JOIN {$schema}.menu m         ON m.menu_id = td.menu_id
         JOIN {$schema}.transaction t  ON t.transaction_id = td.transaction_id
         WHERE t.company_id  = '$company_id_esc'
           AND t.created_by  = '$username_esc'
           AND DATE(t.transaction_date) BETWEEN '$weekStart' AND '$weekEnd'
         GROUP BY td.menu_id, m.menu_name
         ORDER BY total_qty DESC
         LIMIT 1"
    );
    $topRow      = mysqli_fetch_assoc($rTop);
    $top_product = $topRow ? [
        'menu_name'     => $topRow['menu_name'],
        'total_qty'     => (int)$topRow['total_qty'],
        'total_revenue' => (int)$topRow['total_revenue'],
    ] : null;

    // ── 5. Revenue today ──────────────────────────────────────────────────────
    $rRev = mysqli_query($conn,
        "SELECT COALESCE(SUM(total_amount), 0) AS revenue
         FROM {$schema}.transaction
         WHERE company_id      = '$company_id_esc'
           AND created_by      = '$username_esc'
           AND transaction_date = '$todayDate'"
    );
    $revenue_today = (int)(mysqli_fetch_assoc($rRev)['revenue'] ?? 0);

    // ── 6. Cup sold today ─────────────────────────────────────────────────────
    $rCupToday = mysqli_query($conn,
        "SELECT COALESCE(SUM(total_item), 0) AS cup_today
         FROM {$schema}.transaction
         WHERE company_id      = '$company_id_esc'
           AND created_by      = '$username_esc'
           AND transaction_date = '$todayDate'"
    );
    $cup_sold_today = (int)(mysqli_fetch_assoc($rCupToday)['cup_today'] ?? 0);

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

// ─── Auth + routing ───────────────────────────────────────────────────────────
$method  = $_SERVER['REQUEST_METHOD'];
$conn    = DB::conn();
$headers = function_exists('getallheaders') ? getallheaders() : [];

$authHeader = null;
foreach ($headers as $k => $v) {
    if (strtolower($k) === 'authorization') { $authHeader = $v; break; }
}
$authHeader ??= $_SERVER['HTTP_AUTHORIZATION']          ?? null;
$authHeader ??= $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

if (!$authHeader) {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 401,
        'endpoint'        => '/session/driver-stats.php',
        'method'          => $method,
        'error_message'   => 'Authorization header not found',
        'user_identifier' => null,
        'company_id'      => null,
    ]);
    jsonResponse(401, 'Authorization header not found');
}

try {
    $token   = preg_replace('/^Bearer\s+/i', '', trim($authHeader));
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));

    $schema = DB_SCHEMA;

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

} catch (Exception $e) {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/session/driver-stats.php',
        'method'          => $method ?? '',
        'error_message'   => $e->getMessage(),
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $decoded->company_id ?? null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
