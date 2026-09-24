<?php
// Unit tests for pos/history_lines.php (no DB).
//   php pos/tests/history_test.php
// CLI only: this directory is served by the PHP built-in server in production.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../history_lines.php';

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

function row(array $overrides): array {
    return array_merge([
        'menu_id' => null, 'menu_name' => null, 'package_id' => null, 'package_name' => null,
        'line_no' => null, 'quantity' => 1, 'subtotal' => '0.00', 'discount_amount' => 0,
        'sugar_level' => null, 'ice_level' => null,
    ], $overrides);
}

// --- dates ---
check('no dates = all', posHistoryDateBounds(null, ''), ['from' => null, 'until' => null]);
check('range: until is the day after end', posHistoryDateBounds('2026-09-18', '2026-09-24'), ['from' => '2026-09-18', 'until' => '2026-09-25']);
check('month rollover', posHistoryDateBounds(null, '2026-09-30')['until'], '2026-10-01');
check('same day', posHistoryDateBounds('2026-09-24', '2026-09-24'), ['from' => '2026-09-24', 'until' => '2026-09-25']);
check('bad format', posHistoryDateBounds('24-09-2026', null)['error'], 'start_date must be YYYY-MM-DD');
check('impossible date', posHistoryDateBounds(null, '2026-02-30')['error'], 'end_date must be YYYY-MM-DD');
check('reversed', posHistoryDateBounds('2026-09-24', '2026-09-01')['error'], 'start_date must not be after end_date');

// --- lines: the spec example (promo sale, 2 Aren Latte + 1 package of 2 menus) ---
$lines = posHistoryBuildLines([
    row(['menu_id' => 'menu001', 'menu_name' => 'Aren Latte', 'line_no' => 0, 'quantity' => 2, 'subtotal' => '13000.00', 'discount_amount' => 3000, 'sugar_level' => 'Less Sugar', 'ice_level' => 'Normal']),
    row(['menu_id' => 'menuA', 'menu_name' => 'Raki Signature Aren', 'package_id' => 'pkg001', 'package_name' => 'Bundle 2 Raki Signature Aren 500ML', 'line_no' => 1, 'subtotal' => '7500.00']),
    row(['menu_id' => 'menuB', 'menu_name' => 'Raki Signature Aren', 'package_id' => 'pkg001', 'package_name' => 'Bundle 2 Raki Signature Aren 500ML', 'line_no' => 1, 'subtotal' => '7500.00']),
]);
check('menu line gross of promo', $lines[0], [
    'menu_id' => 'menu001', 'package_id' => null, 'name' => 'Aren Latte', 'quantity' => 2,
    'unit_price' => 8000, 'subtotal' => 16000, 'sugar_level' => 'Less Sugar', 'ice_level' => 'Normal',
]);
check('package components folded into one line', $lines[1], [
    'menu_id' => null, 'package_id' => 'pkg001', 'name' => 'Bundle 2 Raki Signature Aren 500ML', 'quantity' => 1,
    'unit_price' => 15000, 'subtotal' => 15000, 'sugar_level' => null, 'ice_level' => null,
]);
check('two lines total', count($lines), 2);

// Same package twice in one cart stays two lines (different line_no).
$twice = posHistoryBuildLines([
    row(['menu_id' => 'a', 'package_id' => 'pkg1', 'package_name' => 'P', 'line_no' => 0, 'quantity' => 2, 'subtotal' => '10001']),
    row(['menu_id' => 'b', 'package_id' => 'pkg1', 'package_name' => 'P', 'line_no' => 0, 'quantity' => 2, 'subtotal' => '9999']),
    row(['menu_id' => 'a', 'package_id' => 'pkg1', 'package_name' => 'P', 'line_no' => 1, 'quantity' => 1, 'subtotal' => '5000']),
    row(['menu_id' => 'b', 'package_id' => 'pkg1', 'package_name' => 'P', 'line_no' => 1, 'quantity' => 1, 'subtotal' => '5000']),
]);
check('repeated package: two lines', count($twice), 2);
check('repeated package: remainder split summed back', [$twice[0]['quantity'], $twice[0]['subtotal'], $twice[0]['unit_price']], [2, 20000, 10000]);
check('repeated package: second line', [$twice[1]['quantity'], $twice[1]['subtotal']], [1, 10000]);

// Rows saved before package_id/line_no existed come out as menu lines.
$old = posHistoryBuildLines([
    row(['menu_id' => 'a', 'menu_name' => 'Kopi Susu', 'quantity' => 1, 'subtotal' => '7500']),
    row(['menu_id' => 'b', 'menu_name' => 'Aren', 'quantity' => 1, 'subtotal' => '7500']),
]);
check('legacy rows: one line each', array_column($old, 'name'), ['Kopi Susu', 'Aren']);
check('no rows', posHistoryBuildLines([]), []);

// --- search ---
check('LIKE wildcards escaped', posHistoryLikePattern('50%_off'), '%50\\%\\_off%');

echo $failures === 0 ? "OK: $checks checks passed\n" : "\n$failures of $checks checks FAILED\n";
exit($failures === 0 ? 0 : 1);
