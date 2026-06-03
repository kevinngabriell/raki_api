<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Origin: *");
    header("Vary: Origin");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    http_response_code(204);
    exit();
}

// ─── Mask phone/username for public display ───────────────────────────────────
function maskDriver(string $raw): string {
    $u = preg_replace('/\s+/', '', $raw);
    if (strlen($u) <= 6) return 'Abang ' . $u;
    return 'Abang ' . substr($u, 0, 4) . '****' . substr($u, -3);
}

function getNearbyAbang(mysqli $conn, string $schema, float $lat, float $lng, float $radius): void {
    $latEsc    = (float)$lat;
    $lngEsc    = (float)$lng;
    $radiusEsc = (float)$radius;

    // ── Step 1: active sessions with last known GPS + distance ────────────────
    // Uses a lateral-style subquery (compatible with MySQL 5.7+) to get the
    // most-recent transaction row per session that has non-null coordinates.
    $qSessions = "
        SELECT
            ws.session_id,
            ws.user_id,
            COALESCE(au.first_name, '') AS first_name,
            loc.latitude,
            loc.longitude,
            (6371 * acos(
                GREATEST(-1, LEAST(1,
                    cos(radians($latEsc)) * cos(radians(loc.latitude))
                        * cos(radians(loc.longitude) - radians($lngEsc))
                    + sin(radians($latEsc)) * sin(radians(loc.latitude))
                ))
            )) AS distance_km
        FROM {$schema}.work_session ws
        INNER JOIN (
            SELECT t1.session_id, t1.latitude, t1.longitude
            FROM {$schema}.transaction t1
            INNER JOIN (
                SELECT session_id, MAX(created_at) AS max_at
                FROM {$schema}.transaction
                WHERE latitude IS NOT NULL AND longitude IS NOT NULL
                GROUP BY session_id
            ) t2 ON t1.session_id = t2.session_id AND t1.created_at = t2.max_at
        ) loc ON loc.session_id = ws.session_id
        LEFT JOIN movira_core_dev.app_user au
            ON au.username = ws.user_id
            OR au.phone_number = ws.user_id
            OR au.user_id = ws.user_id
        WHERE ws.status = 'active'
        HAVING distance_km <= $radiusEsc
        ORDER BY distance_km ASC
    ";

    $rSessions = mysqli_query($conn, $qSessions);
    if (!$rSessions) {
        logApiError($conn, [
            'error_level'     => 'error',
            'http_status'     => 500,
            'endpoint'        => '/session/nearby-abang.php',
            'method'          => 'GET',
            'error_message'   => mysqli_error($conn),
            'user_identifier' => null,
            'company_id'      => null,
        ]);
        jsonResponse(500, 'DB error (sessions)', ['error' => mysqli_error($conn)]);
    }

    $sessions = [];
    $sessionIds = [];

    while ($row = mysqli_fetch_assoc($rSessions)) {
        $sid = $row['session_id'];
        $sessionIds[] = mysqli_real_escape_string($conn, $sid);
        $sessions[$sid] = [
            'id'          => $sid,
            'name'        => maskDriver($row['user_id']),
            'lat'         => (float)$row['latitude'],
            'lng'         => (float)$row['longitude'],
            'distance_km' => round((float)$row['distance_km'], 2),
            'stocks'      => [],
        ];
    }

    if (empty($sessions)) {
        jsonResponse(200, 'No nearby abang found', []);
    }

    // ── Step 2: stocks for the found sessions ─────────────────────────────────
    $inList = "('" . implode("','", $sessionIds) . "')";

    $qStocks = "
        SELECT
            ws.session_id,
            m.menu_id,
            m.menu_name,
            m.thumb_url,
            m.price,
            wss.qty_start,
            COALESCE(SUM(td.quantity), 0) AS qty_sold
        FROM {$schema}.work_session ws
        JOIN {$schema}.work_session_stock wss ON wss.session_id = ws.session_id
        JOIN {$schema}.menu m ON m.menu_id = wss.menu_id
        LEFT JOIN {$schema}.transaction t  ON t.session_id = ws.session_id
        LEFT JOIN {$schema}.transaction_detail td
            ON td.transaction_id = t.transaction_id AND td.menu_id = m.menu_id
        WHERE ws.session_id IN $inList
        GROUP BY ws.session_id, m.menu_id, m.menu_name, m.thumb_url, m.price, wss.qty_start
        ORDER BY m.menu_name ASC
    ";

    $rStocks = mysqli_query($conn, $qStocks);
    if ($rStocks) {
        while ($row = mysqli_fetch_assoc($rStocks)) {
            $sid      = $row['session_id'];
            $qtyLeft  = max(0, (int)$row['qty_start'] - (int)$row['qty_sold']);

            if (isset($sessions[$sid])) {
                $sessions[$sid]['stocks'][] = [
                    'menu_id'   => $row['menu_id'],
                    'menu_name' => $row['menu_name'],
                    'thumb_url' => $row['thumb_url'],
                    'price'     => (int)$row['price'],
                    'qty_left'  => $qtyLeft,
                ];
            }
        }
    }

    jsonResponse(200, 'Nearby abang found', array_values($sessions));
}

// ─── Routing ──────────────────────────────────────────────────────────────────
try {
    $conn   = DB::conn();
    $schema = DB_SCHEMA;
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'GET') {
        jsonResponse(405, 'Method Not Allowed');
    }

    $lat    = isset($_GET['lat'])    ? (float)$_GET['lat']    : null;
    $lng    = isset($_GET['lng'])    ? (float)$_GET['lng']    : null;
    $radius = isset($_GET['radius']) ? (float)$_GET['radius'] : 3.0;

    if ($lat === null || $lng === null || ($lat === 0.0 && $lng === 0.0)) {
        jsonResponse(400, 'lat and lng are required');
    }

    if ($radius <= 0 || $radius > 50) {
        jsonResponse(400, 'radius must be between 0 and 50 km');
    }

    getNearbyAbang($conn, $schema, $lat, $lng, $radius);

} catch (Exception $e) {
    logApiError($conn ?? DB::conn(), [
        'error_level'     => 'error',
        'http_status'     => 500,
        'endpoint'        => '/session/nearby-abang.php',
        'method'          => $method ?? 'GET',
        'error_message'   => $e->getMessage(),
        'user_identifier' => null,
        'company_id'      => null,
    ]);
    jsonResponse(500, 'Internal Server Error', ['error' => $e->getMessage()]);
}
