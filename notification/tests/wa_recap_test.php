<?php
// Run: php notification/tests/wa_recap_test.php
require_once __DIR__ . '/../wa_recap_lib.php';
$checks = 0; $failures = 0;
function check(string $name, $actual, $expected): void {
    global $checks, $failures; $checks++;
    if ($actual !== $expected) { $failures++; echo "FAIL: $name\n  expected " . json_encode($expected) . "\n  actual   " . json_encode($actual) . "\n"; }
}
check('digits 08', waRecapDigits('0812-3456-7890'), '6281234567890');
check('digits +62', waRecapDigits('+62 812 3456 7890'), '6281234567890');
check('digits bad', waRecapDigits('12345'), null);
$msg = waRecapMessage('Raki Tebet', '2026-10-21', ['omzet' => 1500000, 'transactions' => 40, 'cups' => 95, 'last_week' => 1000000, 'top' => [['name' => 'Aren Latte', 'cups' => 30]]]);
check('message has omzet', str_contains($msg, 'Rp 1.500.000'), true);
check('message has diff', str_contains($msg, '+Rp 500.000'), true);
check('message has top menu', str_contains($msg, 'Aren Latte (30)'), true);
$msg2 = waRecapMessage('X', '2026-10-21', ['omzet' => 0, 'transactions' => 0, 'cups' => 0, 'last_week' => null, 'top' => []]);
check('no diff line without last week', str_contains($msg2, 'vs minggu lalu'), false);
echo $failures === 0 ? "OK: $checks checks passed\n" : "\n$failures of $checks checks FAILED\n";
exit($failures === 0 ? 0 : 1);
