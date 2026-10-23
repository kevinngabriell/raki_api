<?php
// Run: php setoran/tests/lib_test.php
require_once __DIR__ . '/../lib.php';

$checks = 0; $failures = 0;
function check(string $name, $actual, $expected): void {
    global $checks, $failures;
    $checks++;
    if ($actual !== $expected) { $failures++; echo "FAIL: $name\n  expected " . json_encode($expected) . "\n  actual   " . json_encode($actual) . "\n"; }
}

check('amount rupiah', setoranParseAmount('Rp 15.000'), 15000);
check('amount decimals', setoranParseAmount('15,000.00'), 15000);
check('amount plain', setoranParseAmount('15000'), 15000);
check('amount empty', setoranParseAmount('-'), null);
check('time with date', setoranParseTime('2026-10-13 19:42:11'), '19:42');
check('time dotted', setoranParseTime('19.42'), '19:42');
check('excel serial time', setoranParseTime('45213.8'), '19:12');
check('time none', setoranParseTime('13/10/2026'), null);

$pay = [['id' => 'a', 'amount' => 15000, 'time' => '19:42'], ['id' => 'b', 'amount' => 15000, 'time' => '10:00'], ['id' => 'c', 'amount' => 9000, 'time' => null]];
$rows = [['amount' => 15000, 'time' => '19:40'], ['amount' => 15000, 'time' => '10:05']];
$r = setoranMatch($pay, $rows);
check('matched by amount+time', array_keys($r['matched']), ['a', 'b']);
check('unmatched', $r['unmatched'], ['c']);

// Same amount, only one settlement row: the nearest sale gets it.
$r = setoranMatch([['id' => 'x', 'amount' => 5000, 'time' => '08:00'], ['id' => 'y', 'amount' => 5000, 'time' => '12:00']], [['amount' => 5000, 'time' => '12:02']]);
check('nearest wins', array_keys($r['matched']), ['y']);
// Back-dated sale (time unrelated) still matches on amount alone in the second pass.
$r = setoranMatch([['id' => 'z', 'amount' => 7000, 'time' => '23:59']], [['amount' => 7000, 'time' => '09:00']]);
check('amount-only fallback', array_keys($r['matched']), ['z']);

$tmp = tempnam(sys_get_temp_dir(), 'set');
file_put_contents($tmp, "Waktu;Nominal;Ref\n2026-10-13 19:42:00;Rp 15.000;A1\n;;\n2026-10-13 20:10:00;9.000;A2\n");
$csv = setoranParseCsv($tmp);
check('csv rows', $csv['rows'], [['amount' => 15000, 'time' => '19:42'], ['amount' => 9000, 'time' => '20:10']]);
file_put_contents($tmp, "foo,bar\n1,2\n");
check('csv without amount column', isset(setoranParseCsv($tmp)['error']), true);
unlink($tmp);

// xlsx: header + two rows, with a shared string header and a numeric amount
$x = tempnam(sys_get_temp_dir(), 'xl') . '.xlsx';
$zip = new ZipArchive();
$zip->open($x, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Waktu</t></si><si><t>Nominal</t></si><si><t>2026-10-13 19:42:00</t></si></sst>');
$zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
  . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
  . '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>15000</v></c></row>'
  . '<row r="3"><c r="B3"><v>9000</v></c></row></sheetData></worksheet>');
$zip->close();
check('xlsx rows', setoranParseXlsx($x), ['rows' => [['amount' => 15000, 'time' => '19:42'], ['amount' => 9000, 'time' => null]]]);
unlink($x);

echo $failures === 0 ? "OK: $checks checks passed\n" : "\n$failures of $checks checks FAILED\n";
exit($failures === 0 ? 0 : 1);
