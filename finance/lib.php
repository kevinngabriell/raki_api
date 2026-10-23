<?php
// Keuangan helpers (docs/backend-endpoints-revamp.md §11). The first half is pure; see finance/tests/lib_test.php.

const FINANCE_CATEGORIES = ['Gaji', 'Sewa', 'Listrik & air', 'Gas', 'Perawatan', 'Lainnya'];
const FINANCE_PUSAT = 'pusat';

function financePeriod(string $date): string {
    return substr($date, 0, 7);
}

/** Last day of the month of YYYY-MM. */
function financeDaysInMonth(string $period): int {
    return (int)(new DateTime($period . '-01'))->format('t');
}

/** The expense date of a recurring biaya in $period: its day of month, clamped to the month's last day. */
function financeRecurringDate(string $period, int $day): string {
    return sprintf('%s-%02d', $period, min($day, financeDaysInMonth($period)));
}

/** ['2026-09', '2026-10', ...] for the months touched by [start, end]. */
function financeMonthsBetween(string $start, string $end): array {
    $out = [];
    $d = new DateTime(financePeriod($start) . '-01');
    $last = new DateTime(financePeriod($end) . '-01');
    while ($d <= $last) {
        $out[] = $d->format('Y-m');
        $d->modify('+1 month');
    }
    return $out;
}

/**
 * Royalti owed for [start, end] on `$omzet`: omzet x percent (+ fixed per month, pro-rata by the days of each
 * month that fall inside the range). $rule = ['type' => pct|fix|both, 'percent' => float, 'fixed' => int].
 */
function financeRoyalty(array $rule, int $omzet, string $start, string $end): int {
    $amount = 0.0;
    if (in_array($rule['type'], ['pct', 'both'], true)) {
        $amount += $omzet * ((float)$rule['percent']) / 100;
    }
    if (in_array($rule['type'], ['fix', 'both'], true)) {
        foreach (financeMonthsBetween($start, $end) as $period) {
            $monthStart = max($start, $period . '-01');
            $monthEnd = min($end, $period . '-' . financeDaysInMonth($period));
            $days = (int)(new DateTime($monthStart))->diff(new DateTime($monthEnd))->days + 1;
            $amount += (int)$rule['fixed'] * $days / financeDaysInMonth($period);
        }
    }
    return (int)round($amount);
}

// ---------------------------------------------------------------------------
// DB
// ---------------------------------------------------------------------------

/** company_id values the caller may book / read costs for: their outlets, plus 'pusat' for the Owner. */
function financeScopes(mysqli $conn, object $decoded): array {
    $ids = accessCompanyIds($conn, $decoded);
    if (accessIsOwner($decoded)) {
        $ids[] = FINANCE_PUSAT;
    }
    return $ids;
}

function financeAssertScope(mysqli $conn, object $decoded, ?string $companyId): string {
    $companyId = trim((string)$companyId);
    if ($companyId === '') {
        jsonResponse(400, 'company_id wajib diisi');
    }
    if (!in_array($companyId, financeScopes($conn, $decoded), true)) {
        jsonResponse(403, 'Kamu tidak punya akses ke outlet ini');
    }
    return $companyId;
}

/**
 * Creates the monthly rows of every recurring biaya up to the current month (and up to $untilPeriod), for the
 * given companies. Idempotent: (recurring_id, period) is unique, and a month deleted on its own is skipped.
 */
function financeMaterialize(mysqli $conn, string $schema, array $companies, string $fromPeriod, string $untilPeriod): void {
    if (!$companies) return;
    $untilPeriod = min($untilPeriod, date('Y-m'));
    [$in, $types] = accessInList($companies);
    $stmt = $conn->prepare("SELECT * FROM {$schema}.finance_recurring WHERE company_id IN ($in)");
    $stmt->bind_param($types, ...$companies);
    $stmt->execute();
    $rules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $ins = $conn->prepare("INSERT IGNORE INTO {$schema}.finance_expense (expense_id, company_id, category, amount, expense_date, note, recurring_id, period, created_by)
        SELECT ?, ?, ?, ?, ?, ?, ?, ?, ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM {$schema}.finance_recurring_skip WHERE recurring_id = ? AND period = ?)");
    foreach ($rules as $r) {
        $from = max($fromPeriod, $r['start_period']);
        $until = $r['end_period'] !== null ? min($untilPeriod, $r['end_period']) : $untilPeriod;
        for ($p = $from; $p <= $until; $p = (new DateTime($p . '-01'))->modify('+1 month')->format('Y-m')) {
            $id = 'exp' . uniqid();
            $date = financeRecurringDate($p, (int)$r['day_of_month']);
            $ins->bind_param('sssisssssss', $id, $r['company_id'], $r['category'], $r['amount'], $date, $r['note'], $r['recurring_id'], $p, $r['created_by'], $r['recurring_id'], $p);
            $ins->execute();
        }
    }
    $ins->close();
}
