<?php
// Promo engine. Shared by promo/check.php (preview) and transaction/index.php
// (apply) so the discount a cashier sees is exactly the one that gets saved.
//
// The first half is pure (no DB, no clock) so it can be unit-tested; see
// promo/tests/engine_test.php. The loaders below it read the rules.

const PROMO_TIMEZONE = 'Asia/Jakarta';
const PROMO_TYPE_BUY_N_NOMINAL_OFF = 'buy_n_nominal_off';

// app_role.role_name values allowed to create/edit/delete promos. Own company only.
const PROMO_MANAGER_ROLES = ['Owner'];

// RAKI's app_id in movira_core_dev.app (same literal as account/login.php).
const PROMO_APP_ID = '06660e87-37e7-491b-92c3-c772130eb57c';

function promoRupiah(int $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function promoTodayJakarta(): string {
    return (new DateTimeImmutable('now', new DateTimeZone(PROMO_TIMEZONE)))->format('Y-m-d');
}

// The calendar date of the sale, exactly as MySQL will store it in the DATE column
// `transaction.transaction_date` (the leading Y-m-d of whatever the client sent).
// Missing => today. Unparseable => null (the promo is then never applied).
function promoSaleDate($transactionDate, string $today): ?string {
    if ($transactionDate === null || trim((string)$transactionDate) === '') {
        return $today;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim((string)$transactionDate), $m)) {
        return null;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
}

function promoIsoWeekday(string $date): int {
    return (int)(new DateTimeImmutable($date, new DateTimeZone(PROMO_TIMEZONE)))->format('N');
}

// A cart line counts toward a promo when it is a single menu (packages are never
// discounted), is in the promo's categories/menus, is not excluded and is in the
// price range. A promo with neither category nor menu conditions covers every menu.
function promoLineEligible(array $promo, array $line, array $menuInfo): bool {
    if (!empty($line['package_id']) || empty($line['menu_id'])) {
        return false;
    }
    $menuId = $line['menu_id'];
    if (!isset($menuInfo[$menuId])) {
        return false;
    }

    $c = $promo['conditions'];
    if (in_array($menuId, $c['exclude_menu'], true)) {
        return false;
    }
    if ($c['category'] || $c['menu']) {
        $categoryId = $menuInfo[$menuId]['category_id'];
        $inCategory = $categoryId !== null && in_array($categoryId, $c['category'], true);
        if (!$inCategory && !in_array($menuId, $c['menu'], true)) {
            return false;
        }
    }
    if ($promo['min_item_price'] !== null && $line['unit_price'] < $promo['min_item_price']) {
        return false;
    }
    if ($promo['max_item_price'] !== null && $line['unit_price'] > $promo['max_item_price']) {
        return false;
    }
    return true;
}

// Spread $discount over the eligible lines in proportion to their value. Each
// share is floored and the leftover rupiah go one at a time to the earliest lines
// that still have room, so the shares always add up to exactly $discount.
function promoAllocate(int $discount, array $eligibleSubtotals): array {
    $total = array_sum($eligibleSubtotals);
    $shares = [];
    $given = 0;
    foreach ($eligibleSubtotals as $index => $subtotal) {
        $shares[$index] = intdiv($discount * $subtotal, $total);
        $given += $shares[$index];
    }
    $left = $discount - $given;
    while ($left > 0) {
        $moved = false;
        foreach ($eligibleSubtotals as $index => $subtotal) {
            if ($left > 0 && $shares[$index] < $subtotal) {
                $shares[$index]++;
                $left--;
                $moved = true;
            }
        }
        if (!$moved) {
            break;
        }
    }
    return $shares;
}

function promoRejected(array $eval, string $reason, string $message): array {
    $eval['reason'] = $reason;
    $eval['message'] = $message;
    return $eval;
}

// Evaluates a single promo against the cart. Never throws; the outcome is in
// 'applicable' / 'reason'. Checks run cheapest-first: date, day, outlet, items.
function promoEvaluateOne(array $promo, array $lines, array $menuInfo, array $ctx): array {
    $eval = [
        'promo_id'          => $promo['promo_id'],
        'promo_name'        => $promo['promo_name'],
        'applicable'        => false,
        'reason'            => null,
        'message'           => null,
        'eligible_quantity' => 0,
        'applications'      => 0,
        'cap_reached'       => false,
        'discount_amount'   => 0,
        'next_reward'       => null,
        'line_discounts'    => [],
    ];
    $c = $promo['conditions'];

    // Guard: the promo only applies to a sale dated today (Jakarta). Blocks
    // back-dating a sale onto a promo day, and future-dating.
    if ($ctx['sale_date'] === null || $ctx['sale_date'] !== $ctx['today']) {
        return promoRejected($eval, 'sale_date_not_today', 'Promo only applies to sales dated today (Jakarta time).');
    }
    if ($c['day_of_week'] && !in_array(promoIsoWeekday($ctx['sale_date']), $c['day_of_week'], true)) {
        return promoRejected($eval, 'day_not_allowed', 'Promo is not valid on this day.');
    }
    if ($c['outlet_type']) {
        $allowed = array_map('strtolower', $c['outlet_type']);
        if ($ctx['role_name'] === null || !in_array(strtolower($ctx['role_name']), $allowed, true)) {
            return promoRejected($eval, 'outlet_type_not_allowed', 'Promo is not valid for this outlet type.');
        }
    }

    $buy = $promo['buy_quantity'];
    $eligibleSubtotals = [];
    $quantity = 0;
    foreach ($lines as $line) {
        if (promoLineEligible($promo, $line, $menuInfo)) {
            $eligibleSubtotals[$line['index']] = $line['quantity'] * $line['unit_price'];
            $quantity += $line['quantity'];
        }
    }
    $eval['eligible_quantity'] = $quantity;

    if ($quantity === 0) {
        $eval['next_reward'] = ['items_needed' => $buy, 'discount_amount' => $promo['discount_amount']];
        return promoRejected($eval, 'no_eligible_items', 'No eligible items in the cart.');
    }

    $pairs = intdiv($quantity, $buy);
    if ($pairs === 0) {
        $needed = $buy - $quantity;
        $eval['next_reward'] = ['items_needed' => $needed, 'discount_amount' => $promo['discount_amount']];
        return promoRejected($eval, 'need_more_items',
            "Add $needed more eligible item(s) to get " . promoRupiah($promo['discount_amount']) . ' off.');
    }

    $eligibleTotal = array_sum($eligibleSubtotals);
    if ($promo['min_eligible_subtotal'] !== null && $eligibleTotal < $promo['min_eligible_subtotal']) {
        return promoRejected($eval, 'below_min_subtotal',
            'Eligible items must total at least ' . promoRupiah($promo['min_eligible_subtotal']) . '.');
    }

    $applications = $promo['max_applications'] !== null ? min($pairs, $promo['max_applications']) : $pairs;
    $discount = min($applications * $promo['discount_amount'], $eligibleTotal);
    if ($discount <= 0) {
        return promoRejected($eval, 'nothing_to_discount', 'Eligible items have no value to discount.');
    }

    $eval['applicable']      = true;
    $eval['applications']    = $applications;
    $eval['cap_reached']     = $applications < $pairs;
    $eval['discount_amount'] = $discount;
    $eval['line_discounts']  = promoAllocate($discount, $eligibleSubtotals);

    // Nudge for the cashier: how many more items unlock another application.
    $canGrow = $promo['max_applications'] === null || $applications < $promo['max_applications'];
    if ($canGrow) {
        $eval['next_reward'] = [
            'items_needed'    => $buy - ($quantity % $buy),
            'discount_amount' => $promo['discount_amount'],
        ];
    }
    return $eval;
}

// Evaluates every active promo and applies the single best one (largest discount;
// ties go to the promo listed first, i.e. the oldest). Promos never stack.
//
// $promos   promoLoadActive() shape
// $lines    [['index','menu_id','package_id','quantity','unit_price'], ...] (ints for qty/price)
// $menuInfo menu_id => ['category_id' => ?string]
// $ctx      ['today' => 'Y-m-d', 'sale_date' => ?'Y-m-d', 'role_name' => ?string]
function promoEvaluate(array $promos, array $lines, array $menuInfo, array $ctx): array {
    $gross = 0;
    foreach ($lines as $line) {
        $gross += $line['quantity'] * $line['unit_price'];
    }

    $evaluations = [];
    $best = null;
    foreach ($promos as $promo) {
        $eval = promoEvaluateOne($promo, $lines, $menuInfo, $ctx);
        $evaluations[] = $eval;
        if ($eval['applicable'] && ($best === null || $eval['discount_amount'] > $best['discount_amount'])) {
            $best = $eval;
        }
    }

    $discount = $best['discount_amount'] ?? 0;
    $lineDiscounts = $best['line_discounts'] ?? [];

    $outLines = [];
    foreach ($lines as $line) {
        $lineGross = $line['quantity'] * $line['unit_price'];
        $lineDiscount = $lineDiscounts[$line['index']] ?? 0;
        $outLines[] = [
            'index'           => $line['index'],
            'menu_id'         => $line['menu_id'],
            'package_id'      => $line['package_id'],
            'quantity'        => $line['quantity'],
            'unit_price'      => $line['unit_price'],
            'subtotal'        => $lineGross,
            'discount_amount' => $lineDiscount,
            'net_subtotal'    => $lineGross - $lineDiscount,
        ];
    }

    foreach ($evaluations as &$eval) {
        unset($eval['line_discounts']);
    }
    unset($eval);

    return [
        'eligible'        => $best !== null,
        'sale_date'       => $ctx['sale_date'],
        'subtotal'        => $gross,
        'discount_amount' => $discount,
        'total'           => $gross - $discount,
        'applied'         => $best === null ? null : [
            'promo_id'        => $best['promo_id'],
            'promo_name'      => $best['promo_name'],
            'promo_type'      => PROMO_TYPE_BUY_N_NOMINAL_OFF,
            'applications'    => $best['applications'],
            'cap_reached'     => $best['cap_reached'],
            'discount_amount' => $best['discount_amount'],
            'next_reward'     => $best['next_reward'],
        ],
        'lines'           => $outLines,
        'evaluations'     => $evaluations,
    ];
}

// ---------------------------------------------------------------------------
// Request helpers + loaders (DB / clock)
// ---------------------------------------------------------------------------

// Validates a client cart (same rules as POST /transaction/index.php) and returns
// ['lines' => [...]] or ['error' => message]. A line with both menu_id and
// package_id counts as a package, matching createTransaction().
function promoParseCartLines($items): array {
    if (!is_array($items) || count($items) === 0) {
        return ['error' => 'items must be a non-empty array'];
    }
    $lines = [];
    foreach (array_values($items) as $idx => $it) {
        if (!is_array($it) || (!isset($it['menu_id']) && !isset($it['package_id']))) {
            return ['error' => "Invalid item at index $idx. Require menu_id or package_id, quantity, unit_price."];
        }
        if (!isset($it['quantity']) || !isset($it['unit_price'])
            || !is_numeric($it['quantity']) || !is_numeric($it['unit_price'])) {
            return ['error' => "Invalid item at index $idx. Require quantity and unit_price."];
        }
        $quantity = (int)$it['quantity'];
        $unitPrice = (int)$it['unit_price'];
        if ($quantity <= 0 || $unitPrice < 0) {
            return ['error' => "Invalid item values at index $idx."];
        }
        $isPackage = isset($it['package_id']);
        $lines[] = [
            'index'      => $idx,
            'menu_id'    => $isPackage ? null : (string)$it['menu_id'],
            'package_id' => $isPackage ? (string)$it['package_id'] : null,
            'quantity'   => $quantity,
            'unit_price' => $unitPrice,
        ];
    }
    return ['lines' => $lines];
}

function promoResolveRoleName(mysqli $conn, ?string $appRoleId): ?string {
    if ($appRoleId === null || $appRoleId === '') {
        return null;
    }
    $stmt = $conn->prepare("SELECT role_name FROM movira_core_dev.app_role WHERE app_role_id = ?");
    if (!$stmt) {
        throw new Exception('Prepare role lookup failed: ' . $conn->error);
    }
    $stmt->bind_param('s', $appRoleId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['role_name'] ?? null;
}

function promoIsManagerRole(?string $roleName): bool {
    return $roleName !== null && in_array($roleName, PROMO_MANAGER_ROLES, true);
}

function promoEmptyConditions(): array {
    return ['day_of_week' => [], 'category' => [], 'menu' => [], 'exclude_menu' => [], 'outlet_type' => []];
}

// Casts a `promo` row to the types the evaluator expects.
function promoNormalizeRow(array $row): array {
    foreach (['buy_quantity', 'discount_amount', 'is_active'] as $k) {
        $row[$k] = (int)$row[$k];
    }
    foreach (['max_applications', 'min_item_price', 'max_item_price', 'min_eligible_subtotal'] as $k) {
        $row[$k] = $row[$k] === null ? null : (int)$row[$k];
    }
    $row['conditions'] = promoEmptyConditions();
    return $row;
}

// Adds a promo_condition row to $promo['conditions'] (day_of_week values become ints).
function promoAddCondition(array &$promo, string $type, string $value): void {
    $promo['conditions'][$type][] = $type === 'day_of_week' ? (int)$value : $value;
}

// Active promos of one company with their conditions, oldest first.
function promoLoadActive(mysqli $conn, string $schema, string $companyId): array {
    $stmt = $conn->prepare(
        "SELECT * FROM {$schema}.promo WHERE company_id = ? AND is_active = 1 ORDER BY created_at, promo_id"
    );
    if (!$stmt) {
        throw new Exception('Prepare promo load failed: ' . $conn->error);
    }
    $stmt->bind_param('s', $companyId);
    $stmt->execute();
    $promos = [];
    foreach ($stmt->get_result() as $row) {
        $promos[$row['promo_id']] = promoNormalizeRow($row);
    }
    $stmt->close();
    if (!$promos) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT pc.promo_id, pc.condition_type, pc.condition_value
         FROM {$schema}.promo_condition pc
         JOIN {$schema}.promo p ON p.promo_id = pc.promo_id
         WHERE p.company_id = ? AND p.is_active = 1"
    );
    if (!$stmt) {
        throw new Exception('Prepare promo condition load failed: ' . $conn->error);
    }
    $stmt->bind_param('s', $companyId);
    $stmt->execute();
    foreach ($stmt->get_result() as $row) {
        if (isset($promos[$row['promo_id']])) {
            promoAddCondition($promos[$row['promo_id']], $row['condition_type'], $row['condition_value']);
        }
    }
    $stmt->close();
    return array_values($promos);
}

// menu_id => ['category_id' => ?string] for the menus in the cart.
function promoLoadMenuInfo(mysqli $conn, string $schema, array $menuIds): array {
    $menuIds = array_values(array_unique(array_filter($menuIds, 'strlen')));
    if (!$menuIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($menuIds), '?'));
    $stmt = $conn->prepare("SELECT menu_id, category_id FROM {$schema}.menu WHERE menu_id IN ($placeholders)");
    if (!$stmt) {
        throw new Exception('Prepare menu lookup failed: ' . $conn->error);
    }
    $stmt->bind_param(str_repeat('s', count($menuIds)), ...$menuIds);
    $stmt->execute();
    $info = [];
    foreach ($stmt->get_result() as $row) {
        $info[$row['menu_id']] = ['category_id' => $row['category_id']];
    }
    $stmt->close();
    return $info;
}

// The one entry point both endpoints use. $transactionDate is whatever the client
// sent as transaction_date (or null); $roleName is the cashier's app_role.role_name.
function promoEvaluateRequest(mysqli $conn, string $schema, string $companyId, ?string $roleName, $transactionDate, array $lines): array {
    $today = promoTodayJakarta();
    $promos = promoLoadActive($conn, $schema, $companyId);
    $menuInfo = promoLoadMenuInfo($conn, $schema, array_column($lines, 'menu_id'));
    return promoEvaluate($promos, $lines, $menuInfo, [
        'today'     => $today,
        'sale_date' => promoSaleDate($transactionDate, $today),
        'role_name' => $roleName,
    ]);
}

// Bearer-JWT check for the promo endpoints. Unlike the older endpoints, a bad or
// expired token is a 401 rather than a 500.
function promoAuthenticate(): object {
    $header = null;
    foreach (function_exists('getallheaders') ? getallheaders() : [] as $k => $v) {
        if (strtolower($k) === 'authorization') {
            $header = $v;
            break;
        }
    }
    $header = $header ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
    if (!$header) {
        jsonResponse(401, 'Authorization header not found');
    }

    $decoded = null;
    try {
        $token = preg_replace('/^Bearer\s+/i', '', trim($header));
        $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($_ENV['JWT_SECRET'], 'HS256'));
    } catch (Throwable $e) {
        // falls through to the 401 below
    }
    if (!$decoded) {
        jsonResponse(401, 'Invalid or expired token');
    }
    return $decoded;
}
