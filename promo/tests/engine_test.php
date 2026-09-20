<?php
// Unit tests for the pure half of promo/engine.php (no DB, no clock).
//   php promo/tests/engine_test.php
// CLI only: this directory is served by the PHP built-in server in production.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../engine.php';

$failures = 0;
$checks = 0;

function check(string $name, $actual, $expected): void {
    global $failures, $checks;
    $checks++;
    if ($actual === $expected) {
        return;
    }
    $failures++;
    echo "FAIL: $name\n  expected: " . json_encode($expected) . "\n  actual:   " . json_encode($actual) . "\n";
}

// 2026-09-21 is a Monday.
const MON = '2026-09-21';
const TUE = '2026-09-22';
const SUN = '2026-09-20';

$menuInfo = [
    'coffee1' => ['category_id' => 'catCoffee'],
    'non1'    => ['category_id' => 'catNon'],
    'non2'    => ['category_id' => 'catNon'],
    'non3'    => ['category_id' => 'catNon'],
    'topping' => ['category_id' => 'catTop'],
    'nocat'   => ['category_id' => null],
];

function promo(array $overrides = [], array $conditions = []): array {
    return array_merge([
        'promo_id' => 'promoA', 'promo_name' => 'Monday Bestie Day', 'company_id' => 'co1',
        'promo_type' => PROMO_TYPE_BUY_N_NOMINAL_OFF, 'buy_quantity' => 2, 'discount_amount' => 3000,
        'max_applications' => null, 'min_item_price' => null, 'max_item_price' => null,
        'min_eligible_subtotal' => null, 'is_active' => 1,
        'conditions' => array_merge(promoEmptyConditions(), [
            'day_of_week' => [1], 'category' => ['catNon'], 'outlet_type' => ['Outlet'],
        ], $conditions),
    ], $overrides);
}

function cart(array $spec): array {
    $lines = [];
    foreach ($spec as $i => [$menu, $qty, $price]) {
        $isPackage = str_starts_with($menu, 'pkg');
        $lines[] = [
            'index' => $i, 'menu_id' => $isPackage ? null : $menu, 'package_id' => $isPackage ? $menu : null,
            'quantity' => $qty, 'unit_price' => $price,
        ];
    }
    return $lines;
}

function ctx(string $today = MON, ?string $saleDate = null, ?string $role = 'Outlet'): array {
    return ['today' => $today, 'sale_date' => $saleDate ?? $today, 'role_name' => $role];
}

function run(array $promos, array $lines, array $ctx): array {
    global $menuInfo;
    return promoEvaluate($promos, $lines, $menuInfo, $ctx);
}

// ---- the Monday Bestie Day promo: buy 2 non-coffee, Rp 3.000 off -------------

$r = run([promo()], cart([['non1', 1, 15000], ['non2', 1, 10000]]), ctx());
check('two non-coffee on Monday for Outlet: eligible', $r['eligible'], true);
check('two non-coffee: discount', $r['discount_amount'], 3000);
check('two non-coffee: subtotal', $r['subtotal'], 25000);
check('two non-coffee: total', $r['total'], 22000);
check('two non-coffee: 1 application', $r['applied']['applications'], 1);

$r = run([promo()], cart([['non1', 1, 15000]]), ctx());
check('one non-coffee: not eligible', $r['eligible'], false);
check('one non-coffee: reason', $r['evaluations'][0]['reason'], 'need_more_items');
check('one non-coffee: next reward needs 1', $r['evaluations'][0]['next_reward']['items_needed'], 1);
check('one non-coffee: no discount', $r['total'], 15000);

$r = run([promo()], cart([['coffee1', 2, 12000]]), ctx());
check('two coffee: reason', $r['evaluations'][0]['reason'], 'no_eligible_items');
check('two coffee: nudge is a full pair', $r['evaluations'][0]['next_reward']['items_needed'], 2);
check('two coffee: no discount', $r['discount_amount'], 0);

$r = run([promo()], cart([['non1', 1, 15000], ['non2', 1, 10000], ['coffee1', 1, 12000]]), ctx());
check('mixed cart: discount', $r['discount_amount'], 3000);
check('mixed cart: coffee line untouched', $r['lines'][2]['discount_amount'], 0);
check('mixed cart: coffee net = gross', $r['lines'][2]['net_subtotal'], 12000);
check('mixed cart: total', $r['total'], 34000);

$r = run([promo()], cart([['non1', 2, 15000]]), ctx());
check('same menu x2 counts as a pair', $r['discount_amount'], 3000);

$r = run([promo()], cart([['non1', 4, 15000]]), ctx());
check('4 eligible: 2 pairs', $r['discount_amount'], 6000);
$r = run([promo()], cart([['non1', 5, 15000]]), ctx());
check('5 eligible: still 2 pairs', $r['discount_amount'], 6000);
check('5 eligible: 1 more unlocks the next pair', $r['applied']['next_reward']['items_needed'], 1);
$r = run([promo()], cart([['non1', 4, 15000]]), ctx());
check('4 eligible: next pair needs 2 more', $r['applied']['next_reward']['items_needed'], 2);

// ---- pair cap (lumped end-of-day entries) -------------------------------------

$r = run([promo(['max_applications' => 1])], cart([['non1', 4, 15000]]), ctx());
check('cap 1: only one pair discounted', $r['discount_amount'], 3000);
check('cap 1: cap_reached', $r['applied']['cap_reached'], true);
check('cap 1: no further nudge', $r['applied']['next_reward'], null);
$r = run([promo(['max_applications' => 2])], cart([['non1', 3, 15000]]), ctx());
check('cap 2 with 3 items: below cap', $r['applied']['cap_reached'], false);
check('cap 2 with 3 items: one pair', $r['discount_amount'], 3000);

// ---- day / outlet type / sale-date guard --------------------------------------

$two = cart([['non1', 1, 15000], ['non2', 1, 10000]]);
$r = run([promo()], $two, ctx(TUE));
check('Tuesday: day_not_allowed', $r['evaluations'][0]['reason'], 'day_not_allowed');
check('Tuesday: no discount', $r['discount_amount'], 0);
$r = run([promo()], $two, ctx(SUN));
check('Sunday: day_not_allowed', $r['evaluations'][0]['reason'], 'day_not_allowed');

$r = run([promo()], $two, ctx(MON, null, 'Abang'));
check('Abang role: outlet_type_not_allowed', $r['evaluations'][0]['reason'], 'outlet_type_not_allowed');
$r = run([promo()], $two, ctx(MON, null, null));
check('unknown role: outlet_type_not_allowed', $r['evaluations'][0]['reason'], 'outlet_type_not_allowed');
$r = run([promo()], $two, ctx(MON, null, 'outlet'));
check('outlet type match is case-insensitive', $r['eligible'], true);

$r = run([promo()], $two, ctx(TUE, MON));
check('Monday sale entered on Tuesday: guard blocks', $r['evaluations'][0]['reason'], 'sale_date_not_today');
$r = run([promo()], $two, ctx(MON, TUE));
check('future-dated sale: guard blocks', $r['evaluations'][0]['reason'], 'sale_date_not_today');
$r = run([promo()], $two, ['today' => MON, 'sale_date' => null, 'role_name' => 'Outlet']);
check('unparseable sale date: guard blocks', $r['evaluations'][0]['reason'], 'sale_date_not_today');

$noDay = promo([], ['day_of_week' => [], 'outlet_type' => []]);
$r = run([$noDay], $two, ctx(TUE, null, 'Abang'));
check('no day / outlet conditions: applies any day, any role', $r['eligible'], true);

// ---- menu / category targeting ------------------------------------------------

$r = run([promo()], cart([['pkg1', 2, 20000]]), ctx());
check('packages are never discounted', $r['evaluations'][0]['reason'], 'no_eligible_items');
$r = run([promo()], cart([['pkg1', 1, 20000], ['non1', 1, 15000]]), ctx());
check('package + 1 drink: still needs one more', $r['evaluations'][0]['reason'], 'need_more_items');
check('package line keeps its full price', $r['lines'][0]['net_subtotal'], 20000);

$r = run([promo([], ['exclude_menu' => ['non2']])], $two, ctx());
check('excluded menu does not count', $r['evaluations'][0]['reason'], 'need_more_items');

$r = run([promo([], ['category' => [], 'menu' => ['non1', 'non2']])], $two, ctx());
check('explicit menu list works without a category', $r['eligible'], true);
$r = run([promo([], ['category' => ['catNon'], 'menu' => ['coffee1']])], cart([['non1', 1, 15000], ['coffee1', 1, 12000]]), ctx());
check('category OR menu: coffee1 added by menu id', $r['eligible'], true);
$r = run([promo([], ['category' => [], 'menu' => []])], cart([['coffee1', 1, 12000], ['topping', 1, 2000]]), ctx());
check('no menu/category conditions: every single menu qualifies', $r['eligible'], true);
$r = run([promo()], cart([['nocat', 2, 10000]]), ctx());
check('menu without a category never matches a category rule', $r['evaluations'][0]['reason'], 'no_eligible_items');
$r = run([promo()], cart([['ghost', 2, 10000]]), ctx());
check('unknown menu id never matches', $r['evaluations'][0]['reason'], 'no_eligible_items');

// ---- price conditions ---------------------------------------------------------

$r = run([promo(['min_item_price' => 12000])], cart([['non1', 1, 15000], ['non2', 1, 10000]]), ctx());
check('min item price: cheap item ignored', $r['evaluations'][0]['reason'], 'need_more_items');
$r = run([promo(['max_item_price' => 12000])], cart([['non1', 1, 15000], ['non2', 1, 10000]]), ctx());
check('max item price: pricey item ignored', $r['evaluations'][0]['reason'], 'need_more_items');
$r = run([promo(['min_item_price' => 10000, 'max_item_price' => 15000])], $two, ctx());
check('price range is inclusive', $r['eligible'], true);
$r = run([promo(['min_eligible_subtotal' => 30000])], $two, ctx());
check('min eligible subtotal not met', $r['evaluations'][0]['reason'], 'below_min_subtotal');
$r = run([promo(['min_eligible_subtotal' => 25000])], $two, ctx());
check('min eligible subtotal met exactly', $r['eligible'], true);

// ---- discount allocation ------------------------------------------------------

$r = run([promo()], cart([['non1', 1, 15000], ['non2', 1, 10000], ['non3', 1, 16000]]), ctx());
$shares = array_column($r['lines'], 'discount_amount');
check('allocation sums exactly to the discount', array_sum($shares), 3000);
check('allocation is proportional, remainder to earliest lines', $shares, [1098, 732, 1170]);
check('net subtotals sum to the total', array_sum(array_column($r['lines'], 'net_subtotal')), $r['total']);

$r = run([promo(['discount_amount' => 3000])], cart([['non1', 1, 1000], ['non2', 1, 1000]]), ctx());
check('discount never exceeds eligible subtotal', $r['discount_amount'], 2000);
check('so no line goes negative', min(array_column($r['lines'], 'net_subtotal')) >= 0, true);

$r = run([promo()], cart([['non1', 2, 0]]), ctx());
check('free eligible items: nothing to discount', $r['evaluations'][0]['reason'], 'nothing_to_discount');
check('free eligible items: not eligible', $r['eligible'], false);

check('promoAllocate: leftover spills past a full line',
    promoAllocate(5, [0 => 1, 1 => 100]), [0 => 1, 1 => 4]);

// ---- several promos: best single wins, no stacking ----------------------------

$small = promo(['promo_id' => 'small', 'discount_amount' => 3000]);
$big   = promo(['promo_id' => 'big', 'discount_amount' => 5000]);
$r = run([$small, $big], $two, ctx());
check('best promo wins', $r['applied']['promo_id'], 'big');
check('promos do not stack', $r['discount_amount'], 5000);
check('every promo is still reported', count($r['evaluations']), 2);
$tie = promo(['promo_id' => 'tie', 'discount_amount' => 3000]);
$r = run([$small, $tie], $two, ctx());
check('tie goes to the first (oldest) promo', $r['applied']['promo_id'], 'small');
$r = run([], $two, ctx());
check('no promos configured: not eligible', $r['eligible'], false);
check('no promos configured: full price', $r['total'], 25000);

// ---- helpers ------------------------------------------------------------------

check('sale date: missing = today', promoSaleDate(null, MON), MON);
check('sale date: blank = today', promoSaleDate('  ', MON), MON);
check('sale date: plain date', promoSaleDate('2026-09-21', TUE), '2026-09-21');
check('sale date: datetime keeps its date part', promoSaleDate('2026-09-21 23:59:59', TUE), '2026-09-21');
check('sale date: ISO 8601', promoSaleDate('2026-09-21T10:00:00+07:00', TUE), '2026-09-21');
check('sale date: garbage', promoSaleDate('yesterday', MON), null);
check('sale date: impossible date', promoSaleDate('2026-02-30', MON), null);
check('ISO weekday: Monday', promoIsoWeekday(MON), 1);
check('ISO weekday: Sunday', promoIsoWeekday(SUN), 7);

check('cart: empty rejected', isset(promoParseCartLines([])['error']), true);
check('cart: missing price rejected', isset(promoParseCartLines([['menu_id' => 'a', 'quantity' => 1]])['error']), true);
check('cart: zero quantity rejected', isset(promoParseCartLines([['menu_id' => 'a', 'quantity' => 0, 'unit_price' => 1]])['error']), true);
check('cart: negative price rejected', isset(promoParseCartLines([['menu_id' => 'a', 'quantity' => 1, 'unit_price' => -1]])['error']), true);
check('cart: no menu or package rejected', isset(promoParseCartLines([['quantity' => 1, 'unit_price' => 1]])['error']), true);
$parsed = promoParseCartLines([['menu_id' => 'a', 'package_id' => 'p', 'quantity' => '2', 'unit_price' => '5000']]);
check('cart: package_id wins over menu_id, like createTransaction', $parsed['lines'][0]['menu_id'], null);
check('cart: numeric strings are cast', [$parsed['lines'][0]['quantity'], $parsed['lines'][0]['unit_price']], [2, 5000]);

check('Owner may manage promos', promoIsManagerRole('Owner'), true);
check('Outlet may not manage promos', promoIsManagerRole('Outlet'), false);
check('Abang may not manage promos', promoIsManagerRole('Abang'), false);
check('no role may not manage promos', promoIsManagerRole(null), false);

echo $failures === 0 ? "OK: $checks checks passed\n" : "\n$failures of $checks checks FAILED\n";
exit($failures === 0 ? 0 : 1);
