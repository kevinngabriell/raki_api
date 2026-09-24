<?php
// Self-registration rules and account status, shared by account/register.php,
// account/login.php, account/otp.php and account/pending.php.
//
// The first half is pure (no DB) so it can be unit-tested; see
// account/tests/account_rules_test.php.

// RAKI's app_id in movira_core_dev.app (same literal as account/login.php).
const ACCOUNT_RAKI_APP_ID = '06660e87-37e7-491b-92c3-c772130eb57c';

// app_user.account_status values written by this API. Accounts created before
// self-registration have NULL (or whatever the column default is) and count as
// active, so only these two states ever block a login.
const ACCOUNT_STATUS_PENDING  = 'pending';
const ACCOUNT_STATUS_ACTIVE   = 'active';
const ACCOUNT_STATUS_REJECTED = 'rejected';

// Roles an Owner may grant when approving a sign-up (matched on app_role.role_name).
const ACCOUNT_GRANTABLE_ROLE_PATTERN = '/\b(mitra|franchise|abang|outlet)\b/i';

// Roles that may look at other cashiers' sales (Owner, Mitra/Franchise).
const ACCOUNT_MANAGER_ROLE_PATTERN = '/\b(owner|mitra|franchise)\b/i';

// Message shown on the login page as-is, or null when the account may log in.
function accountStatusBlockMessage($status): ?string {
    switch (strtolower(trim((string)$status))) {
        case ACCOUNT_STATUS_PENDING:
            return 'Akun kamu masih menunggu persetujuan admin.';
        case ACCOUNT_STATUS_REJECTED:
            return 'Pendaftaran akun kamu ditolak. Hubungi admin RAKI.';
        default:
            return null;
    }
}

// 0812…, +62 812…, 62-812… => 62812…. Returns null when the result isn't 62 + digits, 10–15 long.
function accountNormalizePhone($phone): ?string {
    $digits = preg_replace('/[\s\-().]/', '', trim((string)$phone));
    if (str_starts_with($digits, '+')) {
        $digits = substr($digits, 1);
    }
    if (str_starts_with($digits, '0')) {
        $digits = '62' . substr($digits, 1);
    }
    return preg_match('/^62\d{8,13}$/', $digits) ? $digits : null;
}

// The ways the same number may already be stored (older rows weren't normalized).
function accountPhoneVariants(string $normalized): array {
    $local = substr($normalized, 2);
    return [$normalized, '+' . $normalized, '0' . $local];
}

// Validates a self-registration body. Returns ['error' => message] (Indonesian, shown
// to the user as-is) or ['data' => cleaned fields].
function accountValidateRegistration($input): array {
    if (!is_array($input)) {
        return ['error' => 'Data pendaftaran tidak valid.'];
    }

    $username = trim((string)($input['username'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $fullName = trim((string)($input['full_name'] ?? ''));
    $email    = trim((string)($input['email'] ?? ''));
    $appId    = trim((string)($input['app_id'] ?? ACCOUNT_RAKI_APP_ID));

    if ($username === '') {
        return ['error' => 'Username wajib diisi.'];
    }
    if (!preg_match('/^[a-z0-9.]+$/', $username)) {
        return ['error' => 'Username hanya boleh berisi huruf kecil, angka, dan titik.'];
    }
    if (strlen($username) > 50) {
        return ['error' => 'Username maksimal 50 karakter.'];
    }
    if (strlen($password) < 8) {
        return ['error' => 'Password minimal 8 karakter.'];
    }
    if ($fullName === '') {
        return ['error' => 'Nama lengkap wajib diisi.'];
    }
    if (mb_strlen($fullName) > 100) {
        return ['error' => 'Nama lengkap maksimal 100 karakter.'];
    }
    if (trim((string)($input['phone_number'] ?? '')) === '') {
        return ['error' => 'Nomor HP wajib diisi.'];
    }
    $phone = accountNormalizePhone($input['phone_number']);
    if ($phone === null) {
        return ['error' => 'Nomor HP tidak valid. Gunakan nomor Indonesia yang diawali 62, 10–15 digit.'];
    }
    if ($email === '') {
        return ['error' => 'Email wajib diisi.'];
    }
    if (strlen($email) > 100 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return ['error' => 'Format email tidak valid.'];
    }
    // This endpoint only signs people up for RAKI (login.php only accepts RAKI users).
    if ($appId !== ACCOUNT_RAKI_APP_ID) {
        return ['error' => 'App ID tidak valid.'];
    }

    return ['data' => [
        'username'     => $username,
        'password'     => $password,
        'full_name'    => $fullName,
        'phone_number' => $phone,
        'email'        => $email,
        'app_id'       => $appId,
    ]];
}

function accountIsGrantableRole(?string $roleName): bool {
    return $roleName !== null && preg_match(ACCOUNT_GRANTABLE_ROLE_PATTERN, $roleName) === 1;
}

function accountIsManagerRole(?string $roleName): bool {
    return $roleName !== null && preg_match(ACCOUNT_MANAGER_ROLE_PATTERN, $roleName) === 1;
}

// --- DB helpers ---

// app_role.role_name for a RAKI app_role_id (the JWT's `role` claim), or null.
function accountRoleName(mysqli $conn, ?string $appRoleId): ?string {
    if ($appRoleId === null || $appRoleId === '') {
        return null;
    }
    $stmt = $conn->prepare("SELECT role_name FROM movira_core_dev.app_role WHERE app_role_id = ? AND app_id = ?");
    if (!$stmt) {
        return null;
    }
    $appId = ACCOUNT_RAKI_APP_ID;
    $stmt->bind_param('ss', $appRoleId, $appId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['role_name'] ?? null;
}
