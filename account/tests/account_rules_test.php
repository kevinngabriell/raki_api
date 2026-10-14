<?php
// Unit tests for the pure half of account/account_rules.php (no DB).
//   php account/tests/account_rules_test.php
// CLI only: this directory is served by the PHP built-in server in production.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../account_rules.php';

$failures = 0;
$checks = 0;

function check(string $name, $actual, $expected): void {
    global $failures, $checks;
    $checks++;
    if ($actual === $expected) {
        return;
    }
    $failures++;
    echo "FAIL: $name\n  expected: " . json_encode($expected) . "\n  actual:   " . json_encode($actual) . "\n";
}

function valid(array $overrides = []): array {
    return array_merge([
        'username'     => 'adi.raki',
        'password'     => 'rahasia123',
        'full_name'    => '  Adi Pratama ',
        'phone_number' => '6281234567890',
        'email'        => 'adi@email.com',
        'app_id'       => ACCOUNT_RAKI_APP_ID,
        'app_role_id'  => 'app_role6902bbb67a429', // Owner: must be ignored
    ], $overrides);
}

// --- account status ---
check('pending blocked', accountStatusBlockMessage('pending'), 'Akun kamu masih menunggu persetujuan admin.');
check('rejected blocked', accountStatusBlockMessage('Rejected'), 'Pendaftaran akun kamu ditolak. Hubungi admin RAKI.');
check('verified allowed', accountStatusBlockMessage('verified'), null);
check('NULL (existing users) allowed', accountStatusBlockMessage(null), null);
check('unknown legacy value allowed', accountStatusBlockMessage('verified'), null);

// --- phone ---
check('phone: 62 kept', accountNormalizePhone('6281234567890'), '6281234567890');
check('phone: leading 0', accountNormalizePhone('081234567890'), '6281234567890');
check('phone: +62 with spaces', accountNormalizePhone('+62 812-3456-7890'), '6281234567890');
check('phone: too short', accountNormalizePhone('62812345'), null);
check('phone: 10 digits ok', accountNormalizePhone('6281234567'), '6281234567');
check('phone: 16 digits too long', accountNormalizePhone('6281234567890123'), null);
check('phone: letters rejected', accountNormalizePhone('62812abc7890'), null);
check('phone: other country rejected', accountNormalizePhone('6581234567'), null);
check('phone variants', accountPhoneVariants('6281234567890'), ['6281234567890', '+6281234567890', '081234567890']);

// --- registration ---
$ok = accountValidateRegistration(valid());
check('valid registration', isset($ok['data']), true);
check('full_name trimmed', $ok['data']['full_name'], 'Adi Pratama');
check('role from client dropped', array_key_exists('app_role_id', $ok['data']), false);
check('phone normalized', accountValidateRegistration(valid(['phone_number' => '0812 3456 7890']))['data']['phone_number'], '6281234567890');
check('app_id defaults to RAKI', accountValidateRegistration(array_diff_key(valid(), ['app_id' => 1]))['data']['app_id'], ACCOUNT_RAKI_APP_ID);

check('uppercase username rejected', isset(accountValidateRegistration(valid(['username' => 'Adi']))['error']), true);
check('username with space rejected', isset(accountValidateRegistration(valid(['username' => 'adi raki']))['error']), true);
check('empty username rejected', accountValidateRegistration(valid(['username' => ' ']))['error'], 'Username wajib diisi.');
check('short password rejected', accountValidateRegistration(valid(['password' => '1234567']))['error'], 'Password minimal 8 karakter.');
check('8-char password ok', isset(accountValidateRegistration(valid(['password' => '12345678']))['data']), true);
check('blank full_name rejected', accountValidateRegistration(valid(['full_name' => '   ']))['error'], 'Nama lengkap wajib diisi.');
check('missing phone rejected', accountValidateRegistration(valid(['phone_number' => '']))['error'], 'Nomor HP wajib diisi.');
check('bad phone rejected', isset(accountValidateRegistration(valid(['phone_number' => '12345']))['error']), true);
check('missing email rejected', accountValidateRegistration(valid(['email' => '']))['error'], 'Email wajib diisi.');
check('bad email rejected', accountValidateRegistration(valid(['email' => 'adi@']))['error'], 'Format email tidak valid.');
check('other app rejected', accountValidateRegistration(valid(['app_id' => 'bizion']))['error'], 'App ID tidak valid.');
check('non-array body rejected', isset(accountValidateRegistration(null)['error']), true);

// --- profile update ---
$me = ['username' => 'adi.raki', 'first_name' => 'Adi', 'email' => 'adi@email.com', 'phone_number' => '081234567890', 'language' => 'id'];
$upd = fn(array $in) => accountValidateProfileUpdate($in, $me);

check('first_name alone needs no password', $upd(['first_name' => ' Budi ']), ['changes' => ['first_name' => 'Budi'], 'needs_password' => false]);
check('language alone needs no password', $upd(['language' => 'EN']), ['changes' => ['language' => 'en'], 'needs_password' => false]);
check('username needs password', $upd(['username' => 'budi.raki'])['needs_password'], true);
check('email needs password', $upd(['email' => 'budi@email.com'])['needs_password'], true);
check('phone needs password', $upd(['phone_number' => '0812 9999 8888']), ['changes' => ['phone_number' => '6281299998888'], 'needs_password' => true]);
check('same phone in another format is no change', $upd(['phone_number' => '+6281234567890'])['error'], 'Tidak ada perubahan untuk disimpan.');
check('same values are no change', $upd(['username' => 'adi.raki', 'first_name' => 'Adi'])['error'], 'Tidak ada perubahan untuk disimpan.');
check('unchanged sensitive field does not force password', $upd(['email' => 'adi@email.com', 'first_name' => 'Budi'])['needs_password'], false);
check('empty body is no change', $upd([])['error'], 'Tidak ada perubahan untuk disimpan.');
check('unknown fields ignored', $upd(['role' => 'owner', 'first_name' => 'Budi'])['changes'], ['first_name' => 'Budi']);
check('uppercase username rejected', isset($upd(['username' => 'Budi'])['error']), true);
check('bad email rejected', $upd(['email' => 'adi@'])['error'], 'Format email tidak valid.');
check('bad phone rejected', isset($upd(['phone_number' => '123'])['error']), true);
check('blank first_name rejected', $upd(['first_name' => '  '])['error'], 'Nama depan wajib diisi.');
check('language outside id/en rejected', $upd(['language' => 'fr'])['error'], "Bahasa harus 'id' atau 'en'.");
check('non-string first_name rejected', $upd(['first_name' => ['x']])['error'], 'Nama depan wajib diisi.');
check('non-array body rejected', isset(accountValidateProfileUpdate(null, $me)['error']), true);

// --- roles ---
check('Outlet grantable', accountIsGrantableRole('Outlet'), true);
check('Abang grantable', accountIsGrantableRole('Abang'), true);
check('Mitra grantable', accountIsGrantableRole('Mitra'), true);
check('Mitra/Franchise grantable', accountIsGrantableRole('Mitra/Franchise'), true);
check('Owner not grantable', accountIsGrantableRole('Owner'), false);
check('Customer not grantable', accountIsGrantableRole('Customer'), false);
check('null not grantable', accountIsGrantableRole(null), false);
check('Owner is manager', accountIsManagerRole('Owner'), true);
check('Mitra is manager', accountIsManagerRole('Mitra'), true);
check('Outlet is not manager', accountIsManagerRole('Outlet'), false);
check('Abang is not manager', accountIsManagerRole('Abang'), false);

echo $failures === 0 ? "OK: $checks checks passed\n" : "\n$failures of $checks checks FAILED\n";
exit($failures === 0 ? 0 : 1);
