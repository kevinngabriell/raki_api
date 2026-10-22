<?php
// /supply/stock.php - bahan stock of an outlet (docs §10.4). Menus have no recipe yet, so usage is derived by the
// dashboard as counted + received - now.
//   GET  ?company_id          -> { last_count_at, items: [{ ingredient_id, counted_qty, received_since }] }
//        received_since = qty of the outlet's completed supply orders after the last count (all of them before the first count)
//   POST { company_id, items: [{ ingredient_id, qty }] }   stores a count; it becomes the new starting point
// Pusat (Owner, any own outlet) and the outlet's own staff may count (H5).
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$decoded = requireAuth();
$conn = DB::conn();
$schema = DB_SCHEMA;

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            $companyId = requireCompanyAccess($conn, $decoded, $_GET['company_id'] ?? null);

            $stmt = $conn->prepare("SELECT count_id, counted_at FROM {$schema}.supply_stock_count WHERE company_id = ? ORDER BY counted_at DESC LIMIT 1");
            $stmt->bind_param('s', $companyId);
            $stmt->execute();
            $last = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $since = $last['counted_at'] ?? '1970-01-01 00:00:00';

            $counted = [];
            if ($last) {
                $stmt = $conn->prepare("SELECT ingredient_id, qty FROM {$schema}.supply_stock_count_item WHERE count_id = ?");
                $stmt->bind_param('s', $last['count_id']);
                $stmt->execute();
                foreach ($stmt->get_result() as $r) $counted[$r['ingredient_id']] = (float)$r['qty'];
                $stmt->close();
            }

            $received = [];
            $stmt = $conn->prepare("SELECT D.ingredient_id, SUM(D.qty) AS qty FROM {$schema}.supply_order O JOIN {$schema}.supply_order_detail D ON D.supply_order_id = O.supply_order_id
                WHERE O.from_company_id = ? AND O.status = 'completed' AND O.completed_at > ? GROUP BY D.ingredient_id");
            $stmt->bind_param('ss', $companyId, $since);
            $stmt->execute();
            foreach ($stmt->get_result() as $r) $received[$r['ingredient_id']] = (float)$r['qty'];
            $stmt->close();

            // Average daily use over the last 7 days+ from consecutive counts: use = previous count + received between - next count.
            $avg = [];
            $stmt = $conn->prepare("SELECT C.count_id, C.counted_at, I.ingredient_id, I.qty FROM {$schema}.supply_stock_count C JOIN {$schema}.supply_stock_count_item I ON I.count_id = C.count_id
                WHERE C.company_id = ? AND C.counted_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) ORDER BY C.counted_at");
            $stmt->bind_param('s', $companyId);
            $stmt->execute();
            $series = [];
            foreach ($stmt->get_result() as $r) $series[$r['ingredient_id']][] = ['at' => $r['counted_at'], 'qty' => (float)$r['qty']];
            $stmt->close();
            foreach ($series as $ingId => $points) {
                if (count($points) < 2) continue;
                $used = 0.0;
                for ($i = 1; $i < count($points); $i++) {
                    $stmt = $conn->prepare("SELECT COALESCE(SUM(D.qty), 0) FROM {$schema}.supply_order O JOIN {$schema}.supply_order_detail D ON D.supply_order_id = O.supply_order_id
                        WHERE O.from_company_id = ? AND O.status = 'completed' AND D.ingredient_id = ? AND O.completed_at > ? AND O.completed_at <= ?");
                    $stmt->bind_param('ssss', $companyId, $ingId, $points[$i - 1]['at'], $points[$i]['at']);
                    $stmt->execute();
                    $recv = (float)$stmt->get_result()->fetch_row()[0];
                    $stmt->close();
                    $used += max(0, $points[$i - 1]['qty'] + $recv - $points[$i]['qty']);
                }
                $days = max(1, (strtotime($points[count($points) - 1]['at']) - strtotime($points[0]['at'])) / 86400);
                $avg[$ingId] = round($used / $days, 2);
            }

            $items = [];
            foreach (array_unique(array_merge(array_keys($counted), array_keys($received))) as $id) {
                $items[] = ['ingredient_id' => $id, 'counted_qty' => $counted[$id] ?? 0, 'received_since' => $received[$id] ?? 0, 'avg_daily_use' => $avg[$id] ?? null];
            }
            jsonResponse(200, 'Stock fetched', ['last_count_at' => $last['counted_at'] ?? null, 'items' => $items]);
            break;

        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            if (!is_array($input)) jsonResponse(400, 'Invalid JSON body');
            $companyId = requireCompanyAccess($conn, $decoded, $input['company_id'] ?? null);
            $items = $input['items'] ?? null;
            if (!is_array($items) || !$items) jsonResponse(400, 'items (array) is required');

            $clean = [];
            foreach ($items as $i => $it) {
                $id = trim((string)($it['ingredient_id'] ?? ''));
                if ($id === '' || !isset($it['qty']) || !is_numeric($it['qty']) || $it['qty'] < 0) jsonResponse(400, "items[$i]: ingredient_id dan qty (>= 0) wajib diisi");
                if (referenceCount($conn, "SELECT COUNT(*) FROM {$schema}.ingredient WHERE ingredient_id = ?", $id) === 0) jsonResponse(404, "items[$i]: bahan tidak ditemukan");
                $clean[$id] = (float)$it['qty'];
            }

            $countId = 'cnt' . uniqid();
            $by = $decoded->username;
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("INSERT INTO {$schema}.supply_stock_count (count_id, company_id, counted_at, counted_by) VALUES (?, ?, NOW(), ?)");
                $stmt->bind_param('sss', $countId, $companyId, $by);
                $stmt->execute();
                $stmt->close();
                $stmt = $conn->prepare("INSERT INTO {$schema}.supply_stock_count_item (count_id, ingredient_id, qty) VALUES (?, ?, ?)");
                foreach ($clean as $id => $qty) {
                    $stmt->bind_param('ssd', $countId, $id, $qty);
                    $stmt->execute();
                }
                $stmt->close();
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            jsonResponse(201, 'Stok dihitung', ['count_id' => $countId]);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Throwable $e) {
    logApiError($conn, ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/supply/stock.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
