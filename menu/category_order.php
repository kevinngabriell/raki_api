<?php
// PUT /menu/category_order.php { category_ids: [...] } - saves the full order of the categories (POS tabs use it too).
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
    if ($_SERVER['REQUEST_METHOD'] !== 'PUT') jsonResponse(405, 'Method Not Allowed');
    if (!isMenuManager($decoded)) jsonResponse(403, 'Only the Owner can manage categories');

    $input = json_decode(file_get_contents('php://input'), true);
    $ids = is_array($input) ? ($input['category_ids'] ?? null) : null;
    if (!is_array($ids) || !$ids) jsonResponse(400, 'category_ids (array) is required');
    $ids = array_values(array_unique(array_map('strval', $ids)));

    // The list must be exactly the existing categories, so no category is left without a place.
    $existing = array_column($conn->query("SELECT category_id FROM {$schema}.category_menu")->fetch_all(MYSQLI_NUM), 0);
    sort($existing);
    $sent = $ids;
    sort($sent);
    if ($existing !== $sent) jsonResponse(400, 'category_ids harus berisi semua kategori, tanpa duplikat');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE {$schema}.category_menu SET sort_order = ? WHERE category_id = ?");
        foreach ($ids as $i => $id) {
            $order = $i + 1;
            $stmt->bind_param('is', $order, $id);
            $stmt->execute();
        }
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    jsonResponse(200, 'Urutan kategori disimpan');
} catch (Throwable $e) {
    logApiError($conn, ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/menu/category_order.php', 'method' => 'PUT',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => null]);
    jsonResponse(500, 'Internal Server Error');
}
