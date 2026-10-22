<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

// GET /dashboard/forecast.php?company_id&date=YYYY-MM-DD   (default: tomorrow)
// Prediksi Produksi: for each menu, the average cups sold on that weekday over the last 4 weeks.
// -> { total_cups, last_week_same_day, menus: [{ menu_name, cups, change }] }
// `change` = predicted cups minus the cups sold on the same weekday last week.
// Add-ons are left out (they are not produced ahead).

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function cupsPerMenu(mysqli $conn, string $schema, string $companyId, array $dates): array {
    [$in, $types] = accessInList($dates);
    $stmt = $conn->prepare("SELECT m.menu_id, m.menu_name, COALESCE(SUM(td.quantity), 0) AS cups
        FROM {$schema}.transaction t
        JOIN {$schema}.transaction_detail td ON td.transaction_id = t.transaction_id
        JOIN {$schema}.menu m ON m.menu_id = td.menu_id
        LEFT JOIN {$schema}.category_menu c ON c.category_id = m.category_id
        WHERE t.company_id = ? AND t.transaction_date IN ($in)
          AND COALESCE(c.category_name, '') NOT REGEXP '^(topping|add-?on)$'
        GROUP BY m.menu_id, m.menu_name");
    $params = array_merge([$companyId], $dates);
    $stmt->bind_param('s' . $types, ...$params);
    $stmt->execute();
    $out = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $out[$r['menu_id']] = ['name' => $r['menu_name'], 'cups' => (int)$r['cups']];
    }
    $stmt->close();
    return $out;
}

try {
    $decoded = requireAuth();
    $conn = DB::conn();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(405, 'Method Not Allowed');
    }
    requireManager($decoded);

    $companyId = requireCompanyAccess($conn, $decoded, $_GET['company_id'] ?? null);
    $date = $_GET['date'] ?? date('Y-m-d', strtotime('+1 day'));
    $parsed = DateTime::createFromFormat('Y-m-d', (string)$date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        jsonResponse(400, 'date harus berformat YYYY-MM-DD');
    }

    $weeks = 4;
    $history = [];
    for ($i = 1; $i <= $weeks; $i++) {
        $history[] = (clone $parsed)->modify("-{$i} weeks")->format('Y-m-d');
    }
    $lastWeekDate = $history[0];

    $avg = cupsPerMenu($conn, DB_SCHEMA, $companyId, $history);
    $last = cupsPerMenu($conn, DB_SCHEMA, $companyId, [$lastWeekDate]);

    $menus = [];
    $total = 0;
    foreach ($avg as $id => $row) {
        $cups = (int)ceil($row['cups'] / $weeks);
        if ($cups <= 0) {
            continue;
        }
        $menus[] = ['menu_name' => $row['name'], 'cups' => $cups, 'change' => $cups - ($last[$id]['cups'] ?? 0)];
        $total += $cups;
    }
    usort($menus, fn($a, $b) => $b['cups'] <=> $a['cups']);

    jsonResponse(200, 'Forecast fetched', [
        'total_cups'         => $total,
        'last_week_same_day' => array_sum(array_column($last, 'cups')),
        'menus'              => $menus,
    ]);
} catch (Exception $e) {
    logApiError(DB::conn(), [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/dashboard/forecast.php',
        'method'        => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(),
    ]);
    jsonResponse(500, 'Internal Server Error');
}
