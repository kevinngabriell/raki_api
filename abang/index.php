<?php
// /abang/index.php - account actions on an abang (docs/backend-endpoints-revamp.md §7). Owner / Mitra only.
//   PUT   { username, full_name?, new_username?, phone_number?, company_id }   edit
//   PATCH { username, company_id, action: "deactivate" | "activate" }
// Deactivating ends any open work session; the abang's token stops working on the next call (general.php
// requireActiveAccount answers 401 reason "inactive"). Reset password is not here: how the reset reaches the
// abang (link or temporary password) is still an open question (E5).
require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';
require_once '../log.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

/** The abang row in $companyId, or a 404. */
function abangFind(mysqli $conn, string $companyId, string $username): array {
    $core = MOVIRA_CORE_SCHEMA;
    $role = ACCESS_ROLE_ABANG;
    $app = ACCOUNT_RAKI_APP_ID;
    $stmt = $conn->prepare("SELECT user_id, username, first_name, last_name, phone_number, account_status FROM {$core}.app_user
        WHERE username = ? AND company_id = ? AND app_role_id = ? AND app_id = ? LIMIT 1");
    $stmt->bind_param('ssss', $username, $companyId, $role, $app);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) jsonResponse(404, 'Abang tidak ditemukan di outlet ini');
    return $row;
}

try {
    $decoded = requireAuth();
    $conn = DB::conn();
    $schema = DB_SCHEMA;
    $core = MOVIRA_CORE_SCHEMA;
    requireManager($decoded);

    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['PUT', 'PATCH', 'POST'], true)) jsonResponse(405, 'Method Not Allowed');
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) jsonResponse(400, 'Invalid JSON body');

    $username = trim((string)($input['username'] ?? ''));
    if ($username === '') jsonResponse(400, 'username wajib diisi');
    // The dashboard sends only the username for activate / deactivate: take the outlet from the abang's account.
    $companyId = $input['company_id'] ?? null;
    if ($companyId === null || $companyId === '') {
        $stmt = $conn->prepare("SELECT company_id FROM {$core}.app_user WHERE username = ? AND app_role_id = ? AND app_id = ? LIMIT 1");
        $role = ACCESS_ROLE_ABANG;
        $app = ACCOUNT_RAKI_APP_ID;
        $stmt->bind_param('sss', $username, $role, $app);
        $stmt->execute();
        $companyId = $stmt->get_result()->fetch_row()[0] ?? null;
        $stmt->close();
        if (!$companyId) jsonResponse(404, 'Abang tidak ditemukan');
    }
    $companyId = requireCompanyAccess($conn, $decoded, $companyId);

    if ($method === 'POST') {
        // Reset password for an abang: waiting on the team's answer (link or temporary password, question E5).
        jsonResponse(503, 'Reset password abang belum tersedia.', ['reason' => 'reset_unavailable']);
    }
    $abang = abangFind($conn, $companyId, $username);
    $by = $decoded->username;

    if ($method === 'PATCH') {
        $action = $input['action'] ?? '';
        if (!in_array($action, ['deactivate', 'activate'], true)) jsonResponse(400, 'action must be deactivate or activate');

        $conn->begin_transaction();
        try {
            if ($action === 'deactivate') {
                $stmt = $conn->prepare("UPDATE {$core}.app_user SET account_status = 'suspended', suspended_via = 'admin', updated_by = ?, updated_at = NOW() WHERE user_id = ?");
                $stmt->bind_param('ss', $by, $abang['user_id']);
                $stmt->execute();
                $stmt->close();

                // End the open session(s). work_session.user_id may hold the username, user_id or phone number.
                $ids = array_values(array_filter([$abang['username'], $abang['user_id'], $abang['phone_number']]));
                [$in, $types] = accessInList($ids);
                $stmt = $conn->prepare("UPDATE {$schema}.work_session SET status = 'closed', ended_at = NOW() WHERE company_id = ? AND status = 'active' AND user_id IN ($in)");
                $params = array_merge([$companyId], $ids);
                $stmt->bind_param('s' . $types, ...$params);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("UPDATE {$core}.app_user SET account_status = 'verified', suspended_via = NULL, suspended_by_identity_id = NULL, updated_by = ?, updated_at = NOW() WHERE user_id = ?");
                $stmt->bind_param('ss', $by, $abang['user_id']);
                $stmt->execute();
                $stmt->close();
            }
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
        jsonResponse(200, $action === 'deactivate' ? 'Abang dinonaktifkan' : 'Abang diaktifkan lagi', ['username' => $username]);
    }

    // PUT: edit
    $set = [];
    $types = '';
    $vals = [];
    if (array_key_exists('full_name', $input)) {
        $name = trim((string)$input['full_name']);
        if ($name === '' || mb_strlen($name) > 100) jsonResponse(400, 'Nama lengkap wajib diisi (maksimal 100 karakter)');
        $set[] = 'first_name = ?'; $types .= 's'; $vals[] = $name;
        $set[] = 'last_name = NULL';
    }
    if (!empty($input['phone_number'])) {
        $phone = accountNormalizePhone($input['phone_number']);
        if ($phone === null) jsonResponse(400, 'Nomor HP tidak valid. Gunakan nomor Indonesia yang diawali 62, 10–15 digit.');
        $variants = accountPhoneVariants($phone);
        $app = ACCOUNT_RAKI_APP_ID;
        $stmt = $conn->prepare("SELECT 1 FROM {$core}.app_user WHERE app_id = ? AND phone_number IN (?, ?, ?) AND user_id <> ? LIMIT 1");
        $stmt->bind_param('sssss', $app, $variants[0], $variants[1], $variants[2], $abang['user_id']);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) jsonResponse(409, 'Nomor HP sudah terdaftar');
        $stmt->close();
        $set[] = 'phone_number = ?'; $types .= 's'; $vals[] = $phone;
    }
    $newUsername = null;
    if (!empty($input['new_username']) && trim((string)$input['new_username']) !== $username) {
        $newUsername = trim((string)$input['new_username']);
        if (!preg_match('/^[a-z0-9.]+$/', $newUsername) || strlen($newUsername) > 50) jsonResponse(400, 'Username hanya boleh huruf kecil, angka, dan titik (maksimal 50).');
        $stmt = $conn->prepare("SELECT 1 FROM {$core}.app_user WHERE username = ? LIMIT 1");
        $stmt->bind_param('s', $newUsername);
        $stmt->execute();
        if ($stmt->get_result()->fetch_row()) jsonResponse(409, 'Username sudah dipakai');
        $stmt->close();
        $set[] = 'username = ?'; $types .= 's'; $vals[] = $newUsername;
    }
    if (!$set) jsonResponse(400, 'No fields provided for update');

    $conn->begin_transaction();
    try {
        $set[] = 'updated_by = ?'; $types .= 's'; $vals[] = $by;
        $set[] = 'updated_at = NOW()';
        $stmt = $conn->prepare("UPDATE {$core}.app_user SET " . implode(', ', $set) . " WHERE user_id = ?");
        $types .= 's'; $vals[] = $abang['user_id'];
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $stmt->close();

        // Sales, sessions and payouts are keyed by username: move them with the account.
        if ($newUsername !== null) {
            foreach ([['transaction', 'created_by'], ['transaction', 'created_for'], ['work_session', 'user_id'], ['bonus_payout', 'username']] as [$table, $col]) {
                $stmt = $conn->prepare("UPDATE {$schema}.$table SET $col = ? WHERE $col = ?");
                $stmt->bind_param('ss', $newUsername, $username);
                $stmt->execute();
                $stmt->close();
            }
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
    jsonResponse(200, 'Data abang disimpan', ['username' => $newUsername ?? $username]);
} catch (Throwable $e) {
    logApiError(DB::conn(), ['error_level' => 'error', 'http_status' => 500, 'endpoint' => '/abang/index.php', 'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'error_message' => $e->getMessage(), 'user_identifier' => $decoded->username ?? null, 'company_id' => $decoded->company_id ?? null]);
    jsonResponse(500, 'Internal Server Error');
}
