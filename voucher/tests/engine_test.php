<?php
// Pure parts of voucher/engine.php and the new promo types. Run: php voucher/tests/engine_test.php
require_once __DIR__ . '/../engine.php';

$checks = 0; $failures = 0;
function check(string $name, $actual, $expected): void {
    global $checks, $failures;
    $checks++;
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL: $name\n  expected " . json_encode($expected) . "\n  actual   " . json_encode($actual) . "\n";
    }
}

check('nominal', voucherComputeDiscount('nominal', 5000, null, 40000), 5000);
check('nominal capped by cart', voucherComputeDiscount('nominal', 50000, null, 30000), 30000);
check('percentage', voucherComputeDiscount('percentage', 10, null, 40000), 4000);
check('percentage with max', voucherComputeDiscount('percentage', 50, 8000, 40000), 8000);
check('percentage floors', voucherComputeDiscount('percentage', 15, null, 999), 149);

check('code normalized', voucherNormalizeCode(' tst-ab12 '), 'TST-AB12');
$code = voucherRandomCode('TST');
check('code shape', preg_match('/^TST-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{4}$/', $code), 1);

// promo types
$promoBase = ['promo_id' => 'p', 'promo_name' => 'P', 'buy_quantity' => 1, 'max_applications' => null, 'min_item_price' => null,
    'max_item_price' => null, 'min_eligible_subtotal' => null, 'channels' => [], 'start_date' => null, 'end_date' => null,
    'conditions' => promoEmptyConditions()];
$lines = [['index' => 0, 'menu_id' => 'm1', 'package_id' => null, 'quantity' => 3, 'unit_price' => 10000]];
$info = ['m1' => ['category_id' => null]];
$ctx = ['today' => '2026-10-22', 'sale_date' => '2026-10-22', 'role_name' => null];

$pct = ['promo_type' => PROMO_TYPE_PERCENT_OFF, 'discount_amount' => 10] + $promoBase;
check('percent_off', promoEvaluateOne($pct, $lines, $info, $ctx)['discount_amount'], 3000);
$per = ['promo_type' => PROMO_TYPE_NOMINAL_OFF_PER_ITEM, 'discount_amount' => 1500] + $promoBase;
check('nominal_off_per_item', promoEvaluateOne($per, $lines, $info, $ctx)['discount_amount'], 4500);
$perCap = ['max_applications' => 2] + $per;
check('nominal_off_per_item capped', promoEvaluateOne($perCap, $lines, $info, $ctx)['discount_amount'], 3000);
check('before start', promoEvaluateOne(['start_date' => '2026-11-01'] + $pct, $lines, $info, $ctx)['reason'], 'not_started');
check('after end', promoEvaluateOne(['end_date' => '2026-10-01'] + $pct, $lines, $info, $ctx)['reason'], 'expired');
check('wrong channel', promoEvaluateOne(['channels' => ['outlet']] + $pct, $lines, $info, $ctx + ['channel' => 'abang'])['reason'], 'wrong_channel');
$past = ['sale_date' => '2026-10-20'] + $ctx;
check('past sale rejected by default', promoEvaluateOne($pct, $lines, $info, $past)['reason'], 'sale_date_not_today');
check('past sale allowed for managers', promoEvaluateOne($pct, $lines, $info, $past + ['allow_past' => true])['applicable'], true);
check('largest promo wins', promoEvaluate([$pct, $per], $lines, $info, $ctx)['applied']['promo_type'], PROMO_TYPE_NOMINAL_OFF_PER_ITEM);

echo $failures === 0 ? "OK: $checks checks passed\n" : "\n$failures of $checks checks FAILED\n";
exit($failures === 0 ? 0 : 1);
