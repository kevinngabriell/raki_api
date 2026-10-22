<?php
// Run: php finance/tests/lib_test.php
require_once __DIR__ . '/../lib.php';
$checks = 0; $failures = 0;
function check(string $name, $actual, $expected): void {
    global $checks, $failures; $checks++;
    if ($actual !== $expected) { $failures++; echo "FAIL: $name\n  expected " . json_encode($expected) . "\n  actual   " . json_encode($actual) . "\n"; }
}
check('date clamped to month end', financeRecurringDate('2026-02', 31), '2026-02-28');
check('date kept', financeRecurringDate('2026-10', 5), '2026-10-05');
check('months between', financeMonthsBetween('2026-09-15', '2026-11-02'), ['2026-09', '2026-10', '2026-11']);
check('pct royalty', financeRoyalty(['type' => 'pct', 'percent' => 5, 'fixed' => 0], 25180000, '2026-10-01', '2026-10-31'), 1259000);
check('fixed royalty full month', financeRoyalty(['type' => 'fix', 'percent' => 0, 'fixed' => 3100000], 0, '2026-10-01', '2026-10-31'), 3100000);
check('fixed royalty pro-rata (to 10 Oct)', financeRoyalty(['type' => 'fix', 'percent' => 0, 'fixed' => 3100000], 0, '2026-10-01', '2026-10-10'), 1000000);
check('both', financeRoyalty(['type' => 'both', 'percent' => 2, 'fixed' => 1000000], 10000000, '2026-10-01', '2026-10-31'), 1200000);
check('fixed across two months', financeRoyalty(['type' => 'fix', 'percent' => 0, 'fixed' => 3000000], 0, '2026-09-16', '2026-10-15'), 2951613);
echo $failures === 0 ? "OK: $checks checks passed\n" : "\n$failures of $checks checks FAILED\n";
exit($failures === 0 ? 0 : 1);
