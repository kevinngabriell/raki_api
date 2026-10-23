<?php

final class ErrorCode
{
    public const CATALOG = [
        'ERR_COMMON_001' => [422, 'Validasi gagal'],
        'ERR_COMMON_002' => [404, 'Data tidak ditemukan'],
        'ERR_COMMON_003' => [403, 'Anda tidak memiliki izin untuk mengakses endpoint ini'],
        'ERR_COMMON_004' => [429, 'Terlalu banyak permintaan. Silakan coba lagi dalam beberapa saat.'],
        'ERR_COMMON_005' => [500, 'Terjadi kesalahan pada server. Tim kami telah diberi notifikasi.'],
        'ERR_COMMON_006' => [405, 'Metode HTTP tidak diizinkan untuk endpoint ini'],
        'ERR_COMMON_007' => [400, 'Parameter tidak valid'],
        'ERR_COMMON_008' => [409, 'Data sudah ada di database'],
        'ERR_COMMON_009' => [409, 'Permintaan yang sama masih diproses. Coba lagi beberapa detik lagi.'],

        'ERR_AUTH_001' => [401, 'Username atau password salah'],
        'ERR_AUTH_002' => [401, 'Token autentikasi tidak ditemukan'],
        'ERR_AUTH_003' => [401, 'Token tidak valid atau sudah kedaluwarsa'],
        'ERR_AUTH_004' => [403, 'Akun Anda masih menunggu persetujuan admin'],
        'ERR_AUTH_005' => [403, 'Pendaftaran akun Anda ditolak. Hubungi admin RAKI.'],
        'ERR_AUTH_006' => [404, 'Nomor HP tidak terdaftar. Silakan daftar terlebih dahulu.'],
        'ERR_AUTH_007' => [400, 'Kode OTP salah'],
        'ERR_AUTH_008' => [400, 'Kode OTP sudah kedaluwarsa'],
        'ERR_AUTH_009' => [400, 'Kode OTP sudah pernah digunakan'],
        'ERR_AUTH_010' => [423, 'Terlalu banyak percobaan OTP gagal. Silakan minta OTP baru.'],
        'ERR_AUTH_011' => [429, 'Harap tunggu 2-3 menit sebelum meminta OTP baru'],
        'ERR_AUTH_012' => [423, 'Akun sementara dikunci karena terlalu banyak permintaan OTP'],
        'ERR_AUTH_013' => [422, 'Password minimal 6 karakter'],
        'ERR_AUTH_014' => [401, 'Reset token tidak valid atau sudah kedaluwarsa'],
        'ERR_AUTH_015' => [400, 'Username dan password wajib diisi'],
        'ERR_AUTH_016' => [400, 'Phone number dan OTP wajib diisi'],
        'ERR_AUTH_017' => [400, 'Username wajib diisi'],
        'ERR_AUTH_018' => [400, 'Reset token dan password baru wajib diisi'],

        'ERR_ACCOUNT_001' => [409, 'Username sudah dipakai'],
        'ERR_ACCOUNT_002' => [409, 'Nomor HP sudah terdaftar'],
        'ERR_ACCOUNT_003' => [400, 'Hanya Owner yang bisa meninjau pendaftaran akun'],
        'ERR_ACCOUNT_004' => [409, 'Akun ini tidak sedang menunggu persetujuan'],
        'ERR_ACCOUNT_005' => [404, 'Akun tidak ditemukan'],
        'ERR_ACCOUNT_006' => [400, 'app_role_id wajib diisi saat approval'],
        'ERR_ACCOUNT_007' => [400, 'app_role_id tidak valid'],
        'ERR_ACCOUNT_008' => [403, 'Role ini tidak bisa diberikan lewat persetujuan akun'],

        'ERR_MENU_001' => [400, 'menu_name wajib diisi'],
        'ERR_MENU_002' => [400, 'category_id wajib diisi'],
        'ERR_MENU_003' => [404, 'Menu tidak ditemukan'],
        'ERR_MENU_004' => [404, 'Kategori tidak ditemukan'],
        'ERR_MENU_005' => [404, 'Paket tidak ditemukan'],
        'ERR_MENU_006' => [404, 'Ingredient tidak ditemukan'],
        'ERR_MENU_007' => [404, 'Kategori ingredient tidak ditemukan'],

        'ERR_SESSION_001' => [400, 'cash_start wajib diisi'],
        'ERR_SESSION_002' => [400, 'Stock wajib diisi'],
        'ERR_SESSION_003' => [404, 'Tidak ada sesi aktif'],
        'ERR_SESSION_004' => [400, 'cash_end wajib diisi dan >= 0'],
        'ERR_SESSION_005' => [400, 'lat dan lng wajib diisi'],

        'ERR_TRX_001' => [400, 'items dan payments wajib diisi'],
        'ERR_TRX_002' => [400, 'Total pembayaran tidak sesuai dengan total transaksi'],
        'ERR_TRX_003' => [404, 'Transaksi tidak ditemukan'],
        'ERR_TRX_004' => [403, 'Anda tidak memiliki izin untuk menghapus transaksi ini'],
        'ERR_TRX_005' => [400, 'Tidak ada sesi kerja aktif. Mulai sesi terlebih dahulu.'],
        'ERR_TRX_006' => [400, 'Stok tidak mencukupi'],

        'ERR_POS_001' => [400, 'company_id wajib diisi'],

        'ERR_BONUS_001' => [400, 'schema_name, qty, dan bonus_nominal wajib diisi'],
        'ERR_BONUS_002' => [409, 'Bonus schema sudah ada di database'],
        'ERR_BONUS_003' => [404, 'Bonus schema tidak ditemukan'],
        'ERR_BONUS_004' => [400, 'frequency tidak valid. Pilih: daily, weekly, monthly'],

        'ERR_REWARD_001' => [400, 'reward_name dan point_cost wajib diisi'],
        'ERR_REWARD_002' => [404, 'Reward tidak ditemukan atau tidak tersedia'],
        'ERR_REWARD_003' => [400, 'Reward habis'],
        'ERR_REWARD_004' => [400, 'Poin tidak mencukupi'],
        'ERR_REWARD_005' => [400, 'redemption_id dan status wajib diisi'],
        'ERR_REWARD_006' => [403, 'Hanya Owner/Mitra/Franchise yang bisa mengubah status redemption'],

        'ERR_VOUCHER_001' => [400, 'Field wajib belum lengkap'],
        'ERR_VOUCHER_002' => [409, 'Voucher sudah ada di database'],
        'ERR_VOUCHER_003' => [404, 'Voucher tidak ditemukan'],
        'ERR_VOUCHER_004' => [400, 'discount_type tidak valid. Pilih: nominal, percentage'],
        'ERR_VOUCHER_005' => [400, 'usage_type tidak valid. Pilih: one_time, multi_use'],

        'ERR_PROMO_001' => [400, 'promo_name wajib diisi'],
        'ERR_PROMO_002' => [404, 'Promo tidak ditemukan'],
        'ERR_PROMO_003' => [403, 'Hanya Owner yang bisa mengelola promo'],

        'ERR_SUPPLY_001' => [400, 'Field wajib belum lengkap'],
        'ERR_SUPPLY_002' => [404, 'Supplier tidak ditemukan'],
        'ERR_SUPPLY_003' => [404, 'Order tidak ditemukan'],
        'ERR_SUPPLY_004' => [403, 'Anda tidak memiliki izin untuk mengubah order ini'],

        'ERR_COMPANY_001' => [400, 'company_id wajib diisi'],
        'ERR_COMPANY_002' => [404, 'Company tidak ditemukan'],

        'ERR_NOTIF_001' => [400, 'general_notif wajib diisi'],
    ];

    public static function httpStatus(string $code): int
    {
        return self::CATALOG[$code][0] ?? 500;
    }

    public static function message(string $code): string
    {
        return self::CATALOG[$code][1] ?? 'Terjadi kesalahan';
    }
}
