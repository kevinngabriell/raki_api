<?php
// Pure helpers for pos/history.php (no DB), unit-tested in pos/tests/history_test.php.

// Validates the optional start_date / end_date (YYYY-MM-DD, inclusive, Jakarta calendar dates).
// Returns ['error' => message] or ['from' => ?'Y-m-d', 'until' => ?'Y-m-d'] where `until` is the
// day AFTER end_date, so the SQL can use transaction_date >= from AND transaction_date < until
// whether the column is a DATE or a DATETIME.
function posHistoryDateBounds($startDate, $endDate): array {
    $parse = function ($value, string $name) {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }
        $value = trim((string)$value);
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$dt || $dt->format('Y-m-d') !== $value) {
            return ['error' => "$name must be YYYY-MM-DD"];
        }
        return $dt;
    };

    $start = $parse($startDate, 'start_date');
    if (is_array($start)) {
        return $start;
    }
    $end = $parse($endDate, 'end_date');
    if (is_array($end)) {
        return $end;
    }
    if ($start && $end && $start > $end) {
        return ['error' => 'start_date must not be after end_date'];
    }

    return [
        'from'  => $start ? $start->format('Y-m-d') : null,
        'until' => $end ? $end->modify('+1 day')->format('Y-m-d') : null,
    ];
}

// Turns transaction_detail rows (in insert order) into the lines the cashier sold.
//
// transaction/index.php stores a package as one row per component menu, splitting the price
// across them. Rows saved with package_id + line_no are folded back into one package line;
// older rows (saved before those columns existed) come out as individual menu lines.
// Amounts are gross (before promo): subtotal + discount_amount, as the modal shows them.
function posHistoryBuildLines(array $rows): array {
    $lines = [];
    $packageLineIndex = []; // line_no => index in $lines

    foreach ($rows as $row) {
        $gross = (int)round((float)$row['subtotal'] + (float)($row['discount_amount'] ?? 0));
        $quantity = (int)$row['quantity'];

        if ($row['package_id'] !== null && $row['line_no'] !== null) {
            $key = (int)$row['line_no'];
            if (!isset($packageLineIndex[$key])) {
                $packageLineIndex[$key] = count($lines);
                $lines[] = [
                    'menu_id'     => null,
                    'package_id'  => $row['package_id'],
                    'name'        => $row['package_name'],
                    'quantity'    => $quantity,
                    'unit_price'  => 0,
                    'subtotal'    => 0,
                    'sugar_level' => null,
                    'ice_level'   => null,
                ];
            }
            $lines[$packageLineIndex[$key]]['subtotal'] += $gross;
            continue;
        }

        $lines[] = [
            'menu_id'     => $row['menu_id'],
            'package_id'  => null,
            'name'        => $row['menu_name'],
            'quantity'    => $quantity,
            'unit_price'  => 0,
            'subtotal'    => $gross,
            'sugar_level' => $row['sugar_level'] ?? null,
            'ice_level'   => $row['ice_level'] ?? null,
        ];
    }

    foreach ($lines as &$line) {
        $line['unit_price'] = $line['quantity'] > 0 ? (int)round($line['subtotal'] / $line['quantity']) : 0;
    }
    unset($line);

    return $lines;
}

// Escapes a user search term for LIKE '%…%'.
function posHistoryLikePattern(string $search): string {
    return '%' . addcslashes($search, '\\%_') . '%';
}
