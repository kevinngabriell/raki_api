<?php
// Voucher engine. Shared by voucher/check.php (preview) and transaction/index.php (apply), the same
// split as promo/engine.php, so the discount a cashier sees is the one that gets saved.
//
// A voucher is either
//   - multi: one code (voucher.voucher_code) usable `max_total_usage` times (NULL = unlimited), or
//   - unique: a batch of single-use codes PREFIX-XXXX in voucher_code (voucher.code_count > 0).
// A voucher stacks with a promo: it is computed on the cart AFTER the promo discount.

require_once __DIR__ . '/../promo/engine.php';

// 32 characters: no 0, O, 1, I.
const VOUCHER_CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

const VOUCHER_REASON_MESSAGES = [
    'not_found'         => 'Kode voucher tidak ditemukan',
    'inactive'          => 'Voucher sedang tidak aktif',
    'not_started'       => 'Voucher belum berlaku',
    'expired'           => 'Voucher sudah berakhir',
    'quota_used'        => 'Kuota voucher sudah habis',
    'already_used'      => 'Kode ini sudah pernah dipakai',
    'min_subtotal'      => 'Belanja belum mencapai minimum voucher',
    'wrong_outlet'      => 'Voucher tidak berlaku di outlet ini',
    'wrong_channel'     => 'Voucher tidak berlaku di POS ini',
    'no_eligible_items' => 'Tidak ada menu di pesanan yang berlaku untuk voucher ini',
];

function voucherNormalizeCode($code): string {
    return strtoupper(trim((string)$code));
}

// One random code of a batch: PREFIX-XXXX.
function voucherRandomCode(string $prefix, int $length = 4): string {
    $max = strlen(VOUCHER_CODE_ALPHABET) - 1;
    $suffix = '';
    for ($i = 0; $i < $length; $i++) {
        $suffix .= VOUCHER_CODE_ALPHABET[random_int(0, $max)];
    }
    return $prefix . '-' . $suffix;
}

// Pure: the rupiah a voucher takes off $eligibleNet (the eligible part of the cart, after promo).
function voucherComputeDiscount(string $type, int $value, ?int $maxDiscount, int $eligibleNet): int {
    $discount = $type === 'percentage' ? intdiv($eligibleNet * $value, 100) : $value;
    if ($type === 'percentage' && $maxDiscount !== null && $maxDiscount > 0) {
        $discount = min($discount, $maxDiscount);
    }
    return max(0, min($discount, $eligibleNet));
}

function voucherChannels($csv): array {
    return promoParseChannels($csv);
}

// The voucher row for a typed code (a multi code, or a code from a unique batch), or null.
// Adds 'matched_code' (normalized), 'unique_row' (voucher_code row or null), 'scope' and 'company_ids'.
function voucherFindByCode(mysqli $conn, string $schema, string $code, bool $lock = false): ?array {
    $code = voucherNormalizeCode($code);
    if ($code === '') {
        return null;
    }
    $suffix = $lock ? ' FOR UPDATE' : '';

    $uniqueRow = null;
    $stmt = $conn->prepare("SELECT * FROM {$schema}.voucher_code WHERE code = ? LIMIT 1" . $suffix);
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $uniqueRow = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();

    if ($uniqueRow) {
        $stmt = $conn->prepare("SELECT * FROM {$schema}.voucher WHERE voucher_id = ? LIMIT 1" . $suffix);
        $stmt->bind_param('s', $uniqueRow['voucher_id']);
    } else {
        $stmt = $conn->prepare("SELECT * FROM {$schema}.voucher WHERE voucher_code = ? AND (code_count IS NULL OR code_count = 0) LIMIT 1" . $suffix);
        $stmt->bind_param('s', $code);
    }
    $stmt->execute();
    $voucher = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
    if (!$voucher) {
        return null;
    }

    $voucher['matched_code'] = $code;
    $voucher['unique_row'] = $uniqueRow;
    $voucher['channels'] = voucherChannels($voucher['channels'] ?? null);
    $voucher['company_ids'] = voucherLoadList($conn, $schema, 'voucher_company', 'company_id', $voucher['voucher_id']);
    $voucher['scope'] = ['category' => [], 'menu' => []];
    $stmt = $conn->prepare("SELECT scope_type, scope_id FROM {$schema}.voucher_scope WHERE voucher_id = ?");
    $stmt->bind_param('s', $voucher['voucher_id']);
    $stmt->execute();
    foreach ($stmt->get_result() as $r) {
        $voucher['scope'][$r['scope_type']][] = $r['scope_id'];
    }
    $stmt->close();
    return $voucher;
}

function voucherLoadList(mysqli $conn, string $schema, string $table, string $column, string $voucherId): array {
    $stmt = $conn->prepare("SELECT $column FROM {$schema}.$table WHERE voucher_id = ?");
    $stmt->bind_param('s', $voucherId);
    $stmt->execute();
    $out = array_column($stmt->get_result()->fetch_all(MYSQLI_NUM), 0);
    $stmt->close();
    return $out;
}

function voucherUsedCount(mysqli $conn, string $schema, string $voucherId): int {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM {$schema}.voucher_usage WHERE voucher_id = ?");
    $stmt->bind_param('s', $voucherId);
    $stmt->execute();
    $n = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    return $n;
}

function voucherRejected(string $reason, array $extra = []): array {
    return array_merge(['valid' => false, 'reason' => $reason, 'message' => VOUCHER_REASON_MESSAGES[$reason] ?? 'Voucher tidak bisa dipakai'], $extra);
}

// Checks a voucher against the cart. $cart is promoEvaluate()['lines'] (each with net_subtotal, i.e. after promo);
// $menuInfo is promoLoadMenuInfo(). Never throws; the outcome is in 'valid' / 'reason'.
function voucherEvaluate(mysqli $conn, string $schema, array $voucher, string $companyId, ?string $channel, array $cart, array $menuInfo, ?string $saleDate): array {
    if ((int)$voucher['is_active'] !== 1 || (int)($voucher['is_archived'] ?? 0) === 1) {
        return voucherRejected('inactive');
    }
    if ($saleDate !== null && $saleDate < $voucher['start_date']) {
        return voucherRejected('not_started');
    }
    if ($saleDate !== null && $saleDate > $voucher['end_date']) {
        return voucherRejected('expired');
    }
    if ($voucher['company_ids'] && !in_array($companyId, $voucher['company_ids'], true)) {
        return voucherRejected('wrong_outlet');
    }
    if ($channel !== null && $voucher['channels'] && !in_array($channel, $voucher['channels'], true)) {
        return voucherRejected('wrong_channel');
    }

    if ($voucher['unique_row']) {
        if (!empty($voucher['unique_row']['used_at'])) {
            return voucherRejected('already_used');
        }
    } else {
        $used = voucherUsedCount($conn, $schema, $voucher['voucher_id']);
        if ($voucher['usage_type'] === 'one_time' && $used >= 1) {
            return voucherRejected('already_used');
        }
        if ($voucher['max_total_usage'] !== null && $used >= (int)$voucher['max_total_usage']) {
            return voucherRejected('quota_used');
        }
    }

    $cartNet = 0;
    foreach ($cart as $line) {
        $cartNet += $line['net_subtotal'];
    }
    $min = (int)($voucher['min_transaction'] ?? 0);
    if ($min > 0 && $cartNet < $min) {
        return voucherRejected('min_subtotal', ['min_subtotal' => $min]);
    }

    // Scope: no categories/menus = every line (packages included); otherwise single menus in scope only.
    $scoped = $voucher['scope']['category'] || $voucher['scope']['menu'];
    $eligible = [];
    foreach ($cart as $line) {
        if ($line['net_subtotal'] <= 0) {
            continue;
        }
        if ($scoped) {
            if (!empty($line['package_id']) || empty($line['menu_id'])) {
                continue;
            }
            $categoryId = $menuInfo[$line['menu_id']]['category_id'] ?? null;
            $inScope = in_array($line['menu_id'], $voucher['scope']['menu'], true)
                || ($categoryId !== null && in_array($categoryId, $voucher['scope']['category'], true));
            if (!$inScope) {
                continue;
            }
        }
        $eligible[$line['index']] = $line['net_subtotal'];
    }
    if (!$eligible) {
        return voucherRejected('no_eligible_items');
    }

    $max = $voucher['max_discount'] !== null ? (int)$voucher['max_discount'] : null;
    $discount = voucherComputeDiscount($voucher['discount_type'], (int)$voucher['discount_value'], $max, array_sum($eligible));
    if ($discount <= 0) {
        return voucherRejected('no_eligible_items');
    }

    return [
        'valid'           => true,
        'reason'          => null,
        'voucher_id'      => $voucher['voucher_id'],
        'voucher_name'    => $voucher['voucher_name'],
        'code'            => $voucher['matched_code'],
        'discount_amount' => $discount,
        'total_amount'    => $cartNet - $discount,
        'line_discounts'  => promoAllocate($discount, $eligible),
    ];
}

// A cart with no promo applied, in promoEvaluate()['lines'] shape.
function voucherPlainCart(array $lines): array {
    $out = [];
    foreach ($lines as $line) {
        $gross = $line['quantity'] * $line['unit_price'];
        $out[] = $line + ['subtotal' => $gross, 'discount_amount' => 0, 'net_subtotal' => $gross];
    }
    return $out;
}

// Records the use inside the caller's DB transaction: re-validates under a row lock (two tills can't both
// take the last use), writes voucher_usage and burns a unique code. Throws ApiException-style via jsonResponse.
function voucherRecordUse(mysqli $conn, string $schema, string $code, string $transactionId, string $username, string $companyId, ?string $channel, int $discount): void {
    $voucher = voucherFindByCode($conn, $schema, $code, true);
    if (!$voucher) {
        throw new Exception('Voucher disappeared during checkout');
    }
    if ($voucher['unique_row']) {
        if (!empty($voucher['unique_row']['used_at'])) {
            throw new VoucherRaceException('already_used');
        }
    } else {
        $used = voucherUsedCount($conn, $schema, $voucher['voucher_id']);
        if (($voucher['usage_type'] === 'one_time' && $used >= 1)
            || ($voucher['max_total_usage'] !== null && $used >= (int)$voucher['max_total_usage'])) {
            throw new VoucherRaceException($voucher['usage_type'] === 'one_time' ? 'already_used' : 'quota_used');
        }
    }

    $usageId = 'vcu' . uniqid();
    $stmt = $conn->prepare("INSERT INTO {$schema}.voucher_usage (usage_id, voucher_id, transaction_id, user_id, discount_amount, code, company_id, channel) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $voucherId = $voucher['voucher_id'];
    $matched = $voucher['matched_code'];
    $stmt->bind_param('ssssisss', $usageId, $voucherId, $transactionId, $username, $discount, $matched, $companyId, $channel);
    if (!$stmt->execute()) {
        throw new Exception('Execute voucher usage failed: ' . $stmt->error);
    }
    $stmt->close();

    if ($voucher['unique_row']) {
        $stmt = $conn->prepare("UPDATE {$schema}.voucher_code SET used_at = NOW(), used_by = ?, transaction_id = ? WHERE code = ? AND used_at IS NULL");
        $stmt->bind_param('sss', $username, $transactionId, $matched);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            throw new VoucherRaceException('already_used');
        }
        $stmt->close();
    }

    $stmt = $conn->prepare("UPDATE {$schema}.transaction SET voucher_id = ? WHERE transaction_id = ?");
    $stmt->bind_param('ss', $voucherId, $transactionId);
    $stmt->execute();
    $stmt->close();
}

class VoucherRaceException extends Exception {}
