<?php
// Rekap Harian WhatsApp (docs/backend-endpoints-revamp.md §3): settings live in wa_recap_setting; the message is
// built here and sent by settings/wa-recap.php (?action=send, "kirim sekarang") and notification/wa_recap_cron.php.

require_once __DIR__ . '/notification.php';

/** "62812…" / "0812…" / "+62 812…" -> "62812…", or null when it isn't a plausible number. */
function waRecapDigits($raw): ?string {
    $d = preg_replace('/\D/', '', (string)$raw);
    if ($d === '') return null;
    if (str_starts_with($d, '0')) $d = '62' . substr($d, 1);
    return preg_match('/^62\d{8,13}$/', $d) ? $d : null;
}

/** Pure: the message text from the day's figures. */
function waRecapMessage(string $outletName, string $date, array $f): string {
    $rp = fn($n) => 'Rp ' . number_format((int)$n, 0, ',', '.');
    $lines = [
        "Halo! 👋",
        "",
        "Rekap harian *{$outletName}* · {$date}",
        "",
        "*Omzet:* " . $rp($f['omzet']),
        "*Transaksi:* {$f['transactions']}",
        "*Cup terjual:* {$f['cups']}",
    ];
    if ($f['last_week'] !== null) {
        $diff = $f['omzet'] - $f['last_week'];
        $lines[] = "*vs minggu lalu:* " . ($diff >= 0 ? '+' : '-') . $rp(abs($diff));
    }
    if (!empty($f['top'])) {
        $lines[] = "*Menu terlaris:* " . implode(', ', array_map(fn($t) => "{$t['name']} ({$t['cups']})", $f['top']));
    }
    $lines[] = "";
    $lines[] = "Detail lengkap ada di *Dashboard Raki*. Terima kasih! ☕";
    return implode("\n", $lines);
}

/** The day's figures of one outlet. */
function waRecapFigures(mysqli $conn, string $schema, string $companyId, string $date): array {
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) AS omzet, COUNT(*) AS n FROM {$schema}.transaction WHERE company_id = ? AND transaction_date = ?");
    $stmt->bind_param('ss', $companyId, $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $lastWeek = (new DateTime($date))->modify('-7 days')->format('Y-m-d');
    $stmt = $conn->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM {$schema}.transaction WHERE company_id = ? AND transaction_date = ?");
    $stmt->bind_param('ss', $companyId, $lastWeek);
    $stmt->execute();
    $last = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();

    $stmt = $conn->prepare("SELECT M.menu_name AS name, SUM(TD.quantity) AS cups FROM {$schema}.transaction T JOIN {$schema}.transaction_detail TD ON TD.transaction_id = T.transaction_id
        JOIN {$schema}.menu M ON M.menu_id = TD.menu_id LEFT JOIN {$schema}.category_menu C ON C.category_id = M.category_id
        WHERE T.company_id = ? AND T.transaction_date = ? AND COALESCE(C.category_name, '') NOT REGEXP '^(topping|add-?on)$' GROUP BY M.menu_id, M.menu_name ORDER BY cups DESC");
    $stmt->bind_param('ss', $companyId, $date);
    $stmt->execute();
    $menus = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'omzet' => (int)$row['omzet'], 'transactions' => (int)$row['n'], 'cups' => array_sum(array_map(fn($m) => (int)$m['cups'], $menus)),
        'last_week' => $last > 0 ? $last : null, 'top' => array_map(fn($m) => ['name' => $m['name'], 'cups' => (int)$m['cups']], array_slice($menus, 0, 3)),
    ];
}

/** Destination digits for an outlet: the setting's, else the outlet's PIC contact. */
function waRecapDestination(mysqli $conn, string $companyId, ?string $configured): ?string {
    if ($configured !== null && $configured !== '') return waRecapDigits($configured);
    $core = MOVIRA_CORE_SCHEMA;
    $stmt = $conn->prepare("SELECT pic_contact FROM {$core}.app_company WHERE company_id = ?");
    $stmt->bind_param('s', $companyId);
    $stmt->execute();
    $pic = $stmt->get_result()->fetch_row()[0] ?? null;
    $stmt->close();
    return waRecapDigits($pic);
}

/** Sends the recap of $date; returns ['ok' => bool, 'error' => ?string, 'message' => string]. */
function waRecapSend(mysqli $conn, string $schema, string $companyId, ?string $configuredDestination, string $date): array {
    $core = MOVIRA_CORE_SCHEMA;
    $dest = waRecapDestination($conn, $companyId, $configuredDestination);
    if ($dest === null) return ['ok' => false, 'error' => 'no_destination', 'message' => ''];
    $stmt = $conn->prepare("SELECT company_name FROM {$core}.app_company WHERE company_id = ?");
    $stmt->bind_param('s', $companyId);
    $stmt->execute();
    $name = (string)($stmt->get_result()->fetch_row()[0] ?? $companyId);
    $stmt->close();

    $text = waRecapMessage($name, $date, waRecapFigures($conn, $schema, $companyId, $date));
    $res = sendWhatsAppText($dest . '@c.us', $text);
    return ['ok' => (bool)($res['success'] ?? false), 'error' => ($res['success'] ?? false) ? null : 'send_failed', 'message' => $text];
}
