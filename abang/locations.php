<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

// GET /abang/locations.php?company_id
// Latest known position of every abang with an open work session, for the Dashboard map.
// There is no GPS ping table: the position is the latest transaction of the session that carries coordinates.
// -> [{ username, latitude, longitude, updated_at }]

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $decoded = requireAuth();
    $conn = DB::conn();

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(405, 'Method Not Allowed');
    }
    requireManager($decoded);

    $companyId = requireCompanyAccess($conn, $decoded, $_GET['company_id'] ?? null);
    $schema = DB_SCHEMA;

    $stmt = $conn->prepare("SELECT ws.user_id AS username, t.latitude, t.longitude, t.created_at AS updated_at
        FROM {$schema}.work_session ws
        JOIN {$schema}.transaction t ON t.session_id = ws.session_id AND t.latitude IS NOT NULL AND t.longitude IS NOT NULL
        WHERE ws.company_id = ? AND ws.status = 'active'
          AND t.created_at = (SELECT MAX(t2.created_at) FROM {$schema}.transaction t2
                              WHERE t2.session_id = ws.session_id AND t2.latitude IS NOT NULL AND t2.longitude IS NOT NULL)
        ORDER BY ws.user_id, t.created_at DESC");
    $stmt->bind_param('s', $companyId);
    $stmt->execute();
    $rows = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        if (isset($rows[$r['username']])) continue; // an abang with two open sessions: keep the newest
        $rows[$r['username']] = [
            'username'   => $r['username'],
            'latitude'   => (float)$r['latitude'],
            'longitude'  => (float)$r['longitude'],
            'updated_at' => $r['updated_at'],
        ];
    }
    $stmt->close();

    jsonResponse(200, 'Abang locations fetched', array_values($rows));
} catch (Exception $e) {
    logApiError(DB::conn(), [
        'error_level'   => 'error',
        'http_status'   => 500,
        'endpoint'      => '/abang/locations.php',
        'method'        => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(),
    ]);
    jsonResponse(500, 'Internal Server Error');
}
