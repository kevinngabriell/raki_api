<?php

require_once __DIR__ . '/../connection/db.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../general.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../log.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/../dashboard/weekly_report_data.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// ── Formatting helpers ────────────────────────────────────────────────────────

function wrFmtRp(float $n): string {
    return 'Rp ' . number_format((int)$n, 0, ',', '.');
}

function wrFmtRpShort(float $n): string {
    if ($n >= 1_000_000) return 'Rp ' . rtrim(rtrim(number_format($n / 1_000_000, 2, ',', '.'), '0'), ',') . ' jt';
    if ($n >= 1_000)     return 'Rp ' . number_format($n / 1_000, 0, ',', '.') . ' rb';
    return 'Rp ' . number_format((int)$n, 0, ',', '.');
}

function wrGrowth(?float $pct): string {
    if ($pct === null) return '<span style="color:#aaa;font-size:12px;">–</span>';
    $arrow = $pct >= 0 ? '▲' : '▼';
    $color = $pct >= 0 ? '#2d7a2d' : '#c0392b';
    $abs   = number_format(abs($pct), 1, ',', '.');
    return '<span style="color:' . $color . ';font-size:12px;">' . $arrow . ' ' . $abs . '%</span>';
}

function wrPaymentLabel(string $method): string {
    return match($method) {
        'cash'          => 'Tunai',
        'qris'          => 'QRIS',
        'transfer'      => 'Transfer',
        'qris_midtrans' => 'QRIS Midtrans',
        default         => ucfirst($method),
    };
}

// ── HTML builder ──────────────────────────────────────────────────────────────

function buildWeeklyReportHtml(array $data, string $recipientName): string {
    $period      = $data['period'];
    $companies   = $data['companies'];
    $cashierPerf = $data['cashier_performance'];
    $topProducts = $data['top_products'];

    $dotColors   = ['#378ADD', '#1D9E75', '#E87C3E', '#9B59B6'];
    $periodLabel = htmlspecialchars($period['label']);
    $recipient   = htmlspecialchars($recipientName);

    // ── Section: company summary + payment ────────────────────────────────────
    $companySections = '';
    foreach ($companies as $i => $company) {
        $dot  = $dotColors[$i % count($dotColors)];
        $name = htmlspecialchars($company['company_name']);
        $cur  = $company['current_week'];
        $g    = $company['growth'];

        $revenueVal  = wrFmtRpShort($cur['total_revenue']);
        $trxVal      = number_format($cur['total_trx']);
        $avgVal      = wrFmtRpShort($cur['avg_per_trx']);
        $revenueGrow = wrGrowth($g['revenue_pct']);
        $trxGrow     = wrGrowth($g['trx_pct']);
        $avgGrow     = wrGrowth($g['avg_pct']);

        // payment rows
        $paymentRows = '';
        foreach ($company['payment_breakdown'] as $method => $pay) {
            $label  = wrPaymentLabel($method);
            $amount = wrFmtRp($pay['amount']);
            $count  = $pay['trx_count'];
            $paymentRows .=
                '<tr>'
                . '<td style="padding:8px 0;color:#555;font-size:13px;">' . $label . '</td>'
                . '<td style="padding:8px 0;text-align:right;font-weight:500;font-size:13px;">' . $amount . '</td>'
                . '<td style="padding:8px 0;text-align:right;color:#aaa;font-size:12px;">' . $count . ' trx</td>'
                . '</tr>';
        }

        if (!$paymentRows) {
            $paymentRows = '<tr><td colspan="3" style="padding:8px 0;color:#aaa;font-size:13px;">Tidak ada data pembayaran</td></tr>';
        }

        $companySections .=
            '<tr><td style="padding-bottom:20px;">'

            // Company header
            . '<table width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . $dot . ';margin-right:6px;"></span>'
            . '<strong style="font-size:14px;color:#1a1a1a;">' . $name . '</strong></td>'
            . '</tr></table>'

            // Metrics row
            . '<table width="100%" cellpadding="0" cellspacing="0" style="margin-top:10px;border:1px solid #e8e8e8;border-radius:8px;overflow:hidden;">'
            . '<tr style="background:#fafafa;">'
            . '<td width="33%" style="padding:12px 14px;border-right:1px solid #e8e8e8;">'
            .   '<div style="font-size:11px;color:#aaa;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.05em;">Penjualan</div>'
            .   '<div style="font-size:16px;font-weight:600;color:#1a1a1a;">' . $revenueVal . '</div>'
            .   '<div style="margin-top:3px;">' . $revenueGrow . '</div>'
            . '</td>'
            . '<td width="33%" style="padding:12px 14px;border-right:1px solid #e8e8e8;">'
            .   '<div style="font-size:11px;color:#aaa;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.05em;">Transaksi</div>'
            .   '<div style="font-size:16px;font-weight:600;color:#1a1a1a;">' . $trxVal . '</div>'
            .   '<div style="margin-top:3px;">' . $trxGrow . '</div>'
            . '</td>'
            . '<td width="34%" style="padding:12px 14px;">'
            .   '<div style="font-size:11px;color:#aaa;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.05em;">Rata-rata</div>'
            .   '<div style="font-size:16px;font-weight:600;color:#1a1a1a;">' . $avgVal . '</div>'
            .   '<div style="margin-top:3px;">' . $avgGrow . '</div>'
            . '</td>'
            . '</tr>'

            // Payment breakdown
            . '<tr><td colspan="3" style="padding:12px 14px;border-top:1px solid #e8e8e8;">'
            .   '<table width="100%" cellpadding="0" cellspacing="0">' . $paymentRows . '</table>'
            . '</td></tr>'
            . '</table>'

            . '</td></tr>';
    }

    if (!$companySections) {
        $companySections = '<tr><td style="padding:12px 0;color:#aaa;font-size:13px;">Tidak ada data perusahaan untuk periode ini.</td></tr>';
    }

    // ── Section: cashier / driver ─────────────────────────────────────────────
    $cashierRows = '';
    foreach ($cashierPerf as $c) {
        $username    = htmlspecialchars($c['username']);
        $companyName = htmlspecialchars($c['company_name']);
        $cashierRows .=
            '<tr>'
            . '<td style="padding:9px 12px;border-top:1px solid #f0f0f0;font-size:13px;">' . $username . '</td>'
            . '<td style="padding:9px 12px;border-top:1px solid #f0f0f0;font-size:12px;color:#888;">' . $companyName . '</td>'
            . '<td style="padding:9px 12px;border-top:1px solid #f0f0f0;text-align:right;font-size:13px;">' . number_format($c['trx_count']) . '</td>'
            . '<td style="padding:9px 12px;border-top:1px solid #f0f0f0;text-align:right;font-size:13px;font-weight:500;">' . wrFmtRp($c['total_revenue']) . '</td>'
            . '</tr>';
    }
    if (!$cashierRows) {
        $cashierRows = '<tr><td colspan="4" style="padding:12px;text-align:center;color:#aaa;font-size:13px;">Belum ada data</td></tr>';
    }

    // ── Section: top products (2-column card grid) ────────────────────────────
    $productGrid = '';
    if (empty($topProducts)) {
        $productGrid = '<tr><td colspan="2" style="padding:12px;text-align:center;color:#aaa;font-size:13px;">Belum ada data</td></tr>';
    } else {
        $chunks = array_chunk($topProducts, 2);
        foreach ($chunks as $pair) {
            $productGrid .= '<tr>';
            foreach ($pair as $idx => $p) {
                $menuName = htmlspecialchars($p['menu_name']);
                $border   = $idx === 0 ? 'border-right:1px solid #f0f0f0;' : '';
                $productGrid .=
                    '<td width="50%" style="padding:10px 14px;border-top:1px solid #f0f0f0;' . $border . 'vertical-align:top;">'
                    . '<div style="font-size:13px;color:#1a1a1a;font-weight:500;">' . $menuName . '</div>'
                    . '<div style="margin-top:3px;font-size:12px;color:#aaa;">'
                    .   number_format($p['qty_sold']) . ' qty &nbsp;·&nbsp; ' . wrFmtRp($p['total_revenue'])
                    . '</div>'
                    . '</td>';
            }
            // pad odd row
            if (count($pair) === 1) {
                $productGrid .= '<td width="50%" style="padding:10px 14px;border-top:1px solid #f0f0f0;"></td>';
            }
            $productGrid .= '</tr>';
        }
    }

    // ── Assemble full HTML ────────────────────────────────────────────────────
    return '<!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;color:#1a1a1a;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:24px 12px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;border:1px solid #e0e0e0;">

  <!-- Header -->
  <tr>
    <td style="padding:24px 28px 20px;border-bottom:1px solid #eeeeee;">
      <table width="100%" cellpadding="0" cellspacing="0"><tr>
        <td style="font-size:12px;color:#aaa;text-transform:uppercase;letter-spacing:0.06em;">Laporan Mingguan</td>
        <td align="right" style="font-size:12px;color:#aaa;">' . $periodLabel . '</td>
      </tr></table>
      <div style="margin-top:8px;font-size:20px;font-weight:700;color:#1a1a1a;">Ringkasan Penjualan &amp; Pembayaran</div>
      <div style="margin-top:4px;font-size:13px;color:#888;">Untuk: ' . $recipient . ' &nbsp;&middot;&nbsp; Dikirim otomatis setiap Senin pagi</div>
    </td>
  </tr>

  <!-- Company sections -->
  <tr>
    <td style="padding:24px 28px 0;">
      <div style="font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:16px;">Ringkasan Minggu Ini</div>
      <table width="100%" cellpadding="0" cellspacing="0">' . $companySections . '</table>
    </td>
  </tr>

  <!-- Driver performance -->
  <tr>
    <td style="padding:24px 28px 0;">
      <div style="font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:12px;">Performa per Driver</div>
      <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e8e8e8;border-radius:8px;overflow:hidden;">
        <tr style="background:#fafafa;">
          <th style="padding:9px 12px;font-size:11px;color:#aaa;text-align:left;font-weight:600;text-transform:uppercase;">Driver</th>
          <th style="padding:9px 12px;font-size:11px;color:#aaa;text-align:left;font-weight:600;text-transform:uppercase;">Perusahaan</th>
          <th style="padding:9px 12px;font-size:11px;color:#aaa;text-align:right;font-weight:600;text-transform:uppercase;">Trx</th>
          <th style="padding:9px 12px;font-size:11px;color:#aaa;text-align:right;font-weight:600;text-transform:uppercase;">Penjualan</th>
        </tr>
        ' . $cashierRows . '
      </table>
    </td>
  </tr>

  <!-- Top products -->
  <tr>
    <td style="padding:24px 28px 0;">
      <div style="font-size:11px;font-weight:700;color:#aaa;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:12px;">Produk Terlaris</div>
      <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e8e8e8;border-radius:8px;overflow:hidden;">
        ' . $productGrid . '
      </table>
    </td>
  </tr>

  <!-- Footer -->
  <tr>
    <td style="padding:24px 28px;margin-top:8px;">
      <div style="border-top:1px solid #eeeeee;padding-top:16px;font-size:12px;color:#bbb;text-align:center;">
        Dikirim otomatis oleh sistem &middot; Jangan balas email ini
      </div>
    </td>
  </tr>

</table>
</td></tr>
</table>

</body>
</html>';
}

// ── Orchestrator ──────────────────────────────────────────────────────────────

function sendWeeklyReportEmail($conn, $schema, string $to, string $recipientName, $company_id, $start_date, $end_date): array {
    $data    = buildWeeklyReportData($conn, $schema, $company_id, $start_date, $end_date);
    $html    = buildWeeklyReportHtml($data, $recipientName);
    $subject = 'Laporan Mingguan – ' . $data['period']['label'];
    $altBody = 'Laporan mingguan ' . $recipientName . ' periode ' . $data['period']['label'] . '. Buka email dengan tampilan HTML untuk melihat detail.';

    return sendEmail($to, $subject, $html, $altBody);
}

// ── HTTP endpoint: POST /notification/weekly_report_email.php ─────────────────

$isCli  = php_sapi_name() === 'cli';
$isHttp = !$isCli
    && isset($_SERVER['SCRIPT_FILENAME'])
    && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__);

if (!$isCli && !$isHttp) {
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization");
    http_response_code(200);
    exit();
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['status_code' => 405, 'status_message' => 'Method Not Allowed. Use GET or POST.']);
    exit;
}

$headers = getallheaders();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(['status_code' => 401, 'status_message' => 'Authorization header not found.']);
    exit;
}

try {
    $token   = str_replace('Bearer ', '', $headers['Authorization']);
    $decoded = JWT::decode($token, new Key($_ENV['JWT_SECRET'], 'HS256'));
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['status_code' => 401, 'status_message' => 'Invalid or expired token.']);
    exit;
}

// ── GET: debug / preview ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $conn           = DB::conn();
    $schema         = DB_SCHEMA;
    $company_id     = $_GET['company_id']     ?? null;
    $start_date     = $_GET['start_date']     ?? null;
    $end_date       = $_GET['end_date']       ?? null;
    $recipient_name = $_GET['recipient_name'] ?? 'Preview';
    $mode           = $_GET['mode']           ?? 'data'; // 'data' | 'preview'

    if ($mode === 'check') {
        header('Content-Type: application/json');
        $where = $company_id
            ? "WHERE company_id = '" . $conn->real_escape_string($company_id) . "'"
            : "WHERE 1=1";
        $sql = "SELECT
                    company_id,
                    MIN(transaction_date) AS first_trx,
                    MAX(transaction_date) AS last_trx,
                    COUNT(*)              AS total_trx
                FROM {$schema}.`transaction`
                {$where}
                GROUP BY company_id
                ORDER BY last_trx DESC";
        $res  = $conn->query($sql);
        $rows = [];
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        echo json_encode(['status_code' => 200, 'data' => $rows], JSON_PRETTY_PRINT);
        exit;
    }

    $data = buildWeeklyReportData($conn, $schema, $company_id, $start_date, $end_date);

    if ($mode === 'preview') {
        header('Content-Type: text/html; charset=UTF-8');
        echo buildWeeklyReportHtml($data, $recipient_name);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['status_code' => 200, 'status_message' => 'Debug data', 'data' => $data], JSON_PRETTY_PRINT);
    }
    exit;
}

// ── POST: send email ──────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE || empty($input)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['status_code' => 400, 'status_message' => 'Invalid JSON body.']);
    exit;
}

$to             = $input['to']             ?? null;
$recipient_name = $input['recipient_name'] ?? 'Tim';
$company_id     = $input['company_id']     ?? null;
$start_date     = $input['start_date']     ?? null;
$end_date       = $input['end_date']       ?? null;

if (empty($to)) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['status_code' => 400, 'status_message' => 'Missing required field: to.']);
    exit;
}

$conn   = DB::conn();
$schema = DB_SCHEMA;

$result = sendWeeklyReportEmail($conn, $schema, $to, $recipient_name, $company_id, $start_date, $end_date);

header('Content-Type: application/json');
if ($result['success']) {
    http_response_code(200);
    echo json_encode(['status_code' => 200, 'status_message' => 'Weekly report email sent successfully.']);
} else {
    logApiError($conn, [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/notification/weekly_report_email.php',
        'method'          => 'POST',
        'error_message'   => $result['error'] ?? 'Unknown SMTP error',
        'user_identifier' => $decoded->username ?? null,
        'company_id'      => $company_id ?? null,
    ]);
    http_response_code(500);
    echo json_encode(['status_code' => 500, 'status_message' => 'Failed to send email.', 'error' => $result['error'] ?? null]);
}

exit;
?>
