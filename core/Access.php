<?php
// Which companies (outlets) a signed-in user may read or change. Shared by the dashboard revamp endpoints
// (docs: dashboard repo, docs/backend-endpoints-revamp.md).
//
//  - Owner: every active RAKI outlet (the Development test company aside), plus the token's own company.
//  - Mitra/Franchise, Outlet, Abang: only the token's company.

const ACCESS_ROLE_OWNER = 'app_role6902bbb67a429';
const ACCESS_ROLE_MITRA = 'app_role6902bc0633f27';
const ACCESS_ROLE_ABANG = 'app_role6902bc0cbb991';
// Test company, kept out of every "all outlets" list.
const ACCESS_DEV_COMPANY_ID = 'company691b31b41ea7b';

require_once __DIR__ . '/../account/account_rules.php';

function accessIsOwner(object $decoded): bool {
    return ($decoded->role ?? '') === ACCESS_ROLE_OWNER;
}

function accessIsMitra(object $decoded): bool {
    return ($decoded->role ?? '') === ACCESS_ROLE_MITRA;
}

/** Owner or Mitra: the roles that manage an outlet from the dashboard. */
function accessIsManager(object $decoded): bool {
    return accessIsOwner($decoded) || accessIsMitra($decoded);
}

/** Company ids the user may act on. */
function accessCompanyIds(mysqli $conn, object $decoded): array {
    $own = (string)($decoded->company_id ?? '');
    $ids = $own !== '' ? [$own] : [];

    if (!accessIsOwner($decoded)) {
        return $ids;
    }

    $coreSchema = MOVIRA_CORE_SCHEMA;
    $app = ACCOUNT_RAKI_APP_ID;
    $dev = ACCESS_DEV_COMPANY_ID;
    $stmt = $conn->prepare("SELECT company_id FROM {$coreSchema}.app_company WHERE app_id = ? AND status = 'active' AND company_id <> ?");
    $stmt->bind_param('ss', $app, $dev);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ids[] = $row['company_id'];
    }
    $stmt->close();

    return array_values(array_unique($ids));
}

/** Answers 403 unless the user may act on $companyId. Returns it for chaining. */
function requireCompanyAccess(mysqli $conn, object $decoded, ?string $companyId): string {
    $companyId = trim((string)$companyId);
    if ($companyId === '') {
        jsonResponse(400, 'company_id wajib diisi');
    }
    if (!in_array($companyId, accessCompanyIds($conn, $decoded), true)) {
        jsonResponse(403, 'Kamu tidak punya akses ke outlet ini');
    }
    return $companyId;
}

/** Answers 403 unless the user is an Owner or Mitra. */
function requireManager(object $decoded): void {
    if (!accessIsManager($decoded)) {
        jsonResponse(403, 'Hanya Owner atau Mitra yang bisa melakukan ini');
    }
}

/** Answers 403 unless the user is an Owner. */
function requireOwner(object $decoded): void {
    if (!accessIsOwner($decoded)) {
        jsonResponse(403, 'Hanya Owner yang bisa melakukan ini');
    }
}

/** True when the company has a Mitra/Franchise account (it pays pusat for supply and royalty). */
function accessCompanyIsMitra(mysqli $conn, string $companyId): bool {
    $coreSchema = MOVIRA_CORE_SCHEMA;
    $role = ACCESS_ROLE_MITRA;
    $stmt = $conn->prepare("SELECT 1 FROM {$coreSchema}.app_user WHERE company_id = ? AND app_role_id = ? LIMIT 1");
    $stmt->bind_param('ss', $companyId, $role);
    $stmt->execute();
    $found = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $found;
}

/** Binds a list of strings into "?,?,?" placeholders; returns [placeholders, types]. */
function accessInList(array $values): array {
    return [implode(',', array_fill(0, count($values), '?')), str_repeat('s', count($values))];
}

/** company_ids of a voucher/promo body: Mitra is forced to their own outlet, Owner may pick any RAKI outlets ([] = all). */
function accessParseCompanyIds(mysqli $conn, object $decoded, $raw): array {
    if (accessIsMitra($decoded)) {
        return [(string)$decoded->company_id];
    }
    if ($raw === null || $raw === []) {
        return [];
    }
    if (!is_array($raw)) {
        jsonResponse(400, 'company_ids must be an array');
    }
    $ids = array_values(array_unique(array_map('strval', $raw)));
    $core = MOVIRA_CORE_SCHEMA;
    [$in, $types] = accessInList($ids);
    $app = ACCOUNT_RAKI_APP_ID;
    $stmt = $conn->prepare("SELECT COUNT(*) FROM {$core}.app_company WHERE app_id = ? AND company_id IN ($in)");
    $params = array_merge([$app], $ids);
    $stmt->bind_param('s' . $types, ...$params);
    $stmt->execute();
    $found = (int)$stmt->get_result()->fetch_row()[0];
    $stmt->close();
    if ($found !== count($ids)) {
        jsonResponse(400, 'Satu atau lebih outlet tidak ditemukan');
    }
    return $ids;
}

/** channels of a body as the stored csv: NULL = both. */
function accessParseChannels($raw): ?string {
    if ($raw === null) {
        return null;
    }
    if (!is_array($raw)) {
        jsonResponse(400, 'channels must be an array');
    }
    $valid = array_values(array_intersect(['outlet', 'abang'], $raw));
    if (!$valid) {
        jsonResponse(400, 'channels must contain outlet and/or abang');
    }
    return count($valid) === 2 ? null : $valid[0];
}
