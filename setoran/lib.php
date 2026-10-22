<?php
// Setoran helpers (docs/backend-endpoints-revamp.md §4). The first half is pure so it can be unit-tested;
// see setoran/tests/lib_test.php.

const SETORAN_MATCH_WINDOW_MINUTES = 30;

function setoranMinutes(?string $hhmm): ?int {
    if ($hhmm === null || !preg_match('/^(\d{1,2}):(\d{2})/', $hhmm, $m)) {
        return null;
    }
    return (int)$m[1] * 60 + (int)$m[2];
}

/**
 * Matches system payments to settlement rows by amount, then time.
 *   $payments  [['id' => string, 'amount' => int, 'time' => ?'HH:MM'], ...]
 *   $rows      [['amount' => int, 'time' => ?'HH:MM'], ...]
 * A row is used once. First pass: same amount within SETORAN_MATCH_WINDOW_MINUTES (nearest wins; when either side
 * has no time, any same-amount row is close enough). Second pass: same amount at any time of the day, because
 * back-dated sales carry the time they were typed in, not the time of the payment.
 * Returns ['matched' => [id => true], 'unmatched' => [id, ...]].
 */
function setoranMatch(array $payments, array $rows): array {
    $used = [];
    $matched = [];
    $pass = function (bool $strict) use (&$payments, &$rows, &$used, &$matched): void {
        foreach ($payments as $p) {
            if (isset($matched[$p['id']])) {
                continue;
            }
            $best = null;
            $bestGap = PHP_INT_MAX;
            $pm = setoranMinutes($p['time'] ?? null);
            foreach ($rows as $i => $r) {
                if (isset($used[$i]) || (int)$r['amount'] !== (int)$p['amount']) {
                    continue;
                }
                $rm = setoranMinutes($r['time'] ?? null);
                $gap = ($pm === null || $rm === null) ? 0 : abs($pm - $rm);
                if ($strict && $gap > SETORAN_MATCH_WINDOW_MINUTES) {
                    continue;
                }
                if ($gap < $bestGap) {
                    $best = $i;
                    $bestGap = $gap;
                }
            }
            if ($best !== null) {
                $used[$best] = true;
                $matched[$p['id']] = true;
            }
        }
    };
    $pass(true);
    $pass(false);

    $unmatched = [];
    foreach ($payments as $p) {
        if (!isset($matched[$p['id']])) {
            $unmatched[] = $p['id'];
        }
    }
    return ['matched' => $matched, 'unmatched' => $unmatched];
}

// "Rp 15.000", "15,000.00", "15000" => 15000. null when there is no number.
function setoranParseAmount(string $raw): ?int {
    $s = preg_replace('/[^\d.,-]/', '', trim($raw));
    if ($s === '' || $s === '-') {
        return null;
    }
    // A trailing ",00" / ".00" / ",5" is the decimal part; every other . or , is a thousands separator.
    $s = preg_replace('/[.,]\d{1,2}$/', '', $s);
    $s = str_replace([',', '.'], '', $s);
    return $s === '' || !is_numeric($s) ? null : (int)$s;
}

// "2026-10-13 19:42:11", "13/10/2026 19:42", "19.42" => "19:42"; null when there is no time.
function setoranParseTime(string $raw): ?string {
    // Excel stores a date-time as a serial number: the fraction is the time of day.
    if (preg_match('/^\d+\.\d+$/', trim($raw)) && (float)$raw > 20000) {
        $minutes = (int)round(((float)$raw - floor((float)$raw)) * 1440);
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
    if (preg_match('/(\d{1,2})[:.](\d{2})(?:[:.]\d{2})?\s*$/', trim($raw), $m) && (int)$m[1] < 24 && (int)$m[2] < 60) {
        return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    }
    return null;
}

/**
 * Turns a table (list of rows of cells, first row = header) into settlement rows. The amount column is the first
 * header containing amount / nominal / jumlah / gross / total; the time column the first containing waktu / time /
 * tanggal / date. Returns ['rows' => [['amount','time'], ...]] or ['error' => message].
 */
function setoranTableToRows(array $table): array {
    if (!$table) {
        return ['error' => 'File kosong'];
    }
    $header = array_map(fn($h) => mb_strtolower(trim((string)$h)), array_shift($table));
    $amountCol = null;
    $timeCol = null;
    foreach ($header as $i => $h) {
        if ($amountCol === null && preg_match('/amount|nominal|jumlah|gross|total/', $h)) $amountCol = $i;
        if ($timeCol === null && preg_match('/waktu|time|tanggal|date/', $h)) $timeCol = $i;
    }
    if ($amountCol === null) {
        return ['error' => 'Kolom nominal (amount / nominal / jumlah) tidak ditemukan di baris pertama'];
    }
    $rows = [];
    foreach ($table as $cells) {
        $amount = setoranParseAmount((string)($cells[$amountCol] ?? ''));
        if ($amount === null || $amount <= 0) continue;
        $rows[] = ['amount' => $amount, 'time' => $timeCol !== null ? setoranParseTime((string)($cells[$timeCol] ?? '')) : null];
    }
    return $rows ? ['rows' => $rows] : ['error' => 'Tidak ada baris dengan nominal di file ini'];
}

/** Reads a settlement CSV (comma or semicolon separated, header row). */
function setoranParseCsv(string $path): array {
    $fh = @fopen($path, 'r');
    if (!$fh) {
        return ['error' => 'File tidak bisa dibaca'];
    }
    $first = fgets($fh);
    if ($first === false) {
        fclose($fh);
        return ['error' => 'File kosong'];
    }
    $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
    $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    $table = [str_getcsv($first, $delim, '"', '')];
    while (($line = fgets($fh)) !== false) {
        if (trim($line) !== '') $table[] = str_getcsv($line, $delim, '"', '');
    }
    fclose($fh);
    return setoranTableToRows($table);
}

/** "A", "B", ..., "AA" (a cell reference like "C12") -> zero-based column index. */
function setoranColumnIndex(string $ref): int {
    preg_match('/^[A-Z]+/', strtoupper($ref), $m);
    $n = 0;
    foreach (str_split($m[0] ?? 'A') as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

/** Reads the first sheet of an .xlsx (a zip of XML files; no library needed). */
function setoranParseXlsx(string $path): array {
    if (!class_exists('ZipArchive')) {
        return ['error' => 'Server belum mendukung file Excel. Simpan sebagai CSV lalu unggah lagi.'];
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return ['error' => 'File Excel tidak bisa dibaca'];
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        // The first sheet is not always called sheet1.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) { $sheetXml = $zip->getFromIndex($i); break; }
        }
    }
    $stringsXml = $zip->getFromName('xl/sharedStrings.xml');
    $zip->close();
    if ($sheetXml === false || $sheetXml === null) {
        return ['error' => 'Sheet pertama tidak ditemukan di file Excel'];
    }

    $shared = [];
    if ($stringsXml) {
        $sx = @simplexml_load_string($stringsXml);
        foreach ($sx ? $sx->si : [] as $si) {
            $text = '';
            foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $t) $text .= (string)$t;
            $shared[] = $text;
        }
    }
    $sheet = @simplexml_load_string($sheetXml);
    if (!$sheet) {
        return ['error' => 'File Excel tidak bisa dibaca'];
    }
    $table = [];
    foreach ($sheet->sheetData->row ?? [] as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $idx = setoranColumnIndex((string)$c['r']);
            $type = (string)$c['t'];
            $val = $type === 'inlineStr' ? (string)$c->is->t : (string)$c->v;
            $cells[$idx] = $type === 's' ? ($shared[(int)$val] ?? '') : $val;
        }
        if ($cells) {
            $max = max(array_keys($cells));
            $line = [];
            for ($k = 0; $k <= $max; $k++) $line[] = $cells[$k] ?? '';
            $table[] = $line;
        }
    }
    return setoranTableToRows($table);
}

// ---------------------------------------------------------------------------
// DB
// ---------------------------------------------------------------------------

// Editing or deleting a sale of a finished day reopens it (done = false).
function setoranReopen(mysqli $conn, string $schema, string $companyId, string $date): void {
    try {
        $date = substr($date, 0, 10);
        $stmt = $conn->prepare("UPDATE {$schema}.setoran_day SET done = 0, finished_by = NULL, finished_at = NULL WHERE company_id = ? AND setoran_date = ? AND done = 1");
        $stmt->bind_param('ss', $companyId, $date);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log('setoranReopen failed: ' . $e->getMessage());
    }
}

/**
 * Transaction ids of the day whose QRIS / EDC payments are all matched to a settlement row (or marked
 * "received"). Used by pos/history.php for the "sudah cocok setoran" tick.
 */
function setoranMatchedTransactions(mysqli $conn, string $schema, string $companyId, string $date): array {
    $stmt = $conn->prepare("SELECT t.transaction_id, tp.payment_method, tp.amount, TIME_FORMAT(IF(t.sell_end IS NOT NULL, CONCAT(t.sell_end, ':00'), TIME(t.created_at)), '%H:%i') AS at
        FROM {$schema}.transaction t JOIN {$schema}.transaction_payment tp ON tp.transaction_id = t.transaction_id
        WHERE t.company_id = ? AND t.transaction_date = ? AND tp.payment_method IN ('qris', 'qris_midtrans', 'edc_flazz')");
    $stmt->bind_param('ss', $companyId, $date);
    $stmt->execute();
    $pays = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if (!$pays) {
        return [];
    }

    $rows = ['qris' => [], 'edc' => []];
    $stmt = $conn->prepare("SELECT s.kind, r.amount, r.paid_time FROM {$schema}.setoran_settlement s
        JOIN {$schema}.setoran_settlement_row r ON r.settlement_id = s.settlement_id
        WHERE s.company_id = ? AND s.setoran_date = ? ORDER BY s.uploaded_at, r.row_no");
    $stmt->bind_param('ss', $companyId, $date);
    $stmt->execute();
    foreach ($stmt->get_result() as $r) {
        $rows[$r['kind']][] = ['amount' => (int)$r['amount'], 'time' => $r['paid_time']];
    }
    $stmt->close();

    $flags = [];
    $stmt = $conn->prepare("SELECT transaction_id FROM {$schema}.setoran_qris_flag WHERE company_id = ? AND status = 'received'");
    $stmt->bind_param('s', $companyId);
    $stmt->execute();
    foreach ($stmt->get_result() as $r) {
        $flags[$r['transaction_id']] = true;
    }
    $stmt->close();

    $ok = [];
    $bad = [];
    foreach (['qris' => ['qris', 'qris_midtrans'], 'edc' => ['edc_flazz']] as $kind => $methods) {
        $list = [];
        foreach ($pays as $p) {
            if (in_array($p['payment_method'], $methods, true)) {
                $list[] = ['id' => $p['transaction_id'] . '|' . $p['payment_method'], 'trx' => $p['transaction_id'], 'amount' => (int)$p['amount'], 'time' => $p['at']];
            }
        }
        $m = setoranMatch($list, $rows[$kind]);
        foreach ($list as $p) {
            if (isset($m['matched'][$p['id']]) || isset($flags[$p['trx']])) {
                $ok[$p['trx']] = true;
            } else {
                $bad[$p['trx']] = true;
            }
        }
    }
    return array_diff_key($ok, $bad);
}
