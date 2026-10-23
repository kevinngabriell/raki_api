<?php
// In-app notification feed (the bell). Rows live in app_notification; company_id scopes who sees them, user_id
// NULL = everyone of that company.
//   GET   /notification/feed.php?limit&unread=1   -> { unread_count, items: [{ id, type, title, body, read, created_at, resource_type, resource_id, company_id }] }
//   PATCH /notification/feed.php { ids: [...] } | { all: true }    mark read
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if (!function_exists('notificationCreate')) {
    /** Adds a feed item for a company. type: supply_order | system | transaction. Never throws (a feed failure must not break the action). */
    function notificationCreate(mysqli $conn, string $schema, string $companyId, string $type, string $title, string $message, ?string $resourceType = null, ?string $resourceId = null, ?string $createdBy = null): void {
        try {
            $id = 'ntf' . bin2hex(random_bytes(8));
            $stmt = $conn->prepare("INSERT INTO {$schema}.app_notification (notification_id, company_id, type, title, message, resource_type, resource_id, channel, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'in_app', ?)");
            $title = mb_substr($title, 0, 200);
            $message = mb_substr($message, 0, 1000);
            $stmt->bind_param('ssssssss', $id, $companyId, $type, $title, $message, $resourceType, $resourceId, $createdBy);
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log('notificationCreate failed: ' . $e->getMessage());
        }
    }
}

// Included by another script (only for notificationCreate): stop here.
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) {
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

$decoded = requireAuth();
$conn = DB::conn();
$schema = DB_SCHEMA;

try {
    $companies = accessCompanyIds($conn, $decoded) ?: ['-'];
    [$in, $types] = accessInList($companies);
    $user = (string)($decoded->username ?? '');

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            $limit = pageLimit($_GET['limit'] ?? null, 15);
            $unreadOnly = in_array((string)($_GET['unread'] ?? ''), ['1', 'true'], true);
            $where = "company_id IN ($in) AND (user_id IS NULL OR user_id = ?)";
            $params = array_merge($companies, [$user]);

            $stmt = $conn->prepare("SELECT COUNT(*) FROM {$schema}.app_notification WHERE $where AND is_read = 0");
            $stmt->bind_param($types . 's', ...$params);
            $stmt->execute();
            $unread = (int)$stmt->get_result()->fetch_row()[0];
            $stmt->close();

            $stmt = $conn->prepare("SELECT notification_id, type, title, message, is_read, created_at, resource_type, resource_id, company_id FROM {$schema}.app_notification
                WHERE $where" . ($unreadOnly ? ' AND is_read = 0' : '') . " ORDER BY created_at DESC LIMIT ?");
            $params[] = $limit;
            $stmt->bind_param($types . 'si', ...$params);
            $stmt->execute();
            $items = [];
            foreach ($stmt->get_result() as $r) {
                $items[] = ['id' => $r['notification_id'], 'type' => $r['type'], 'title' => $r['title'], 'body' => $r['message'], 'read' => (bool)$r['is_read'],
                            'created_at' => $r['created_at'], 'resource_type' => $r['resource_type'], 'resource_id' => $r['resource_id'], 'company_id' => $r['company_id']];
            }
            $stmt->close();
            jsonResponse(200, 'Notifications fetched', ['unread_count' => $unread, 'items' => $items]);
            break;

        case 'PATCH':
            $inp = json_decode(file_get_contents('php://input'), true);
            if (!is_array($inp)) jsonResponse(400, 'Invalid JSON body');
            $where = "company_id IN ($in) AND (user_id IS NULL OR user_id = ?) AND is_read = 0";
            $params = array_merge($companies, [$user]);
            $ptypes = $types . 's';
            if (empty($inp['all'])) {
                $ids = array_values(array_filter(array_map('strval', (array)($inp['ids'] ?? []))));
                if (!$ids) jsonResponse(400, 'ids (array) or all: true is required');
                [$iin, $itypes] = accessInList($ids);
                $where .= " AND notification_id IN ($iin)";
                $params = array_merge($params, $ids);
                $ptypes .= $itypes;
            }
            $stmt = $conn->prepare("UPDATE {$schema}.app_notification SET is_read = 1, read_at = NOW() WHERE $where");
            $stmt->bind_param($ptypes, ...$params);
            $stmt->execute();
            $n = $stmt->affected_rows;
            $stmt->close();
            jsonResponse(200, 'Ditandai dibaca', ['updated' => $n]);
            break;

        default:
            jsonResponse(405, 'Method Not Allowed');
    }
} catch (Throwable $e) {
    logApiError($conn, ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/notification/feed.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
