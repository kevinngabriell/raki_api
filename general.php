<?php

// CORS setup
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
$allowed_origins = [
    'http://localhost:3000',
    'https://your-production-domain.com'
];

if (in_array($origin, $allowed_origins)) {
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
}

header("Access-Control-Allow-Methods: POST, GET, OPTIONS, DELETE");
header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    http_response_code(200);
    exit();
}

// Error reporting
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

function jsonResponse($code, $message, $data = []) {
    http_response_code($code);
    echo json_encode([
        'status_code' => $code,
        'status_message' => $message,
        'data' => $data
    ]);
    exit;
}

function cleanInput($conn, $value, $default = '-', $rejects = ['0', '']) {
    if (!isset($value) || in_array(trim((string)$value), $rejects, true)) {
        return $default;
    }
    return mysqli_real_escape_string($conn, trim((string)$value));
}

function getUserIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP']; // Shared internet
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]; // Proxy
    } else {
        return $_SERVER['REMOTE_ADDR']; // Default
    }
}

function getCurrentDateTimeJakarta(): string {
    $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    return $dt->format('Y-m-d H:i:s');
}

function getMonthRoman(): string {
    date_default_timezone_set('Asia/Jakarta');
    $month = date('n');

    $romanMonths = [
        1 => 'I',
        2 => 'II',
        3 => 'III',
        4 => 'IV',
        5 => 'V',
        6 => 'VI',
        7 => 'VII',
        8 => 'VIII',
        9 => 'IX',
        10 => 'X',
        11 => 'XI',
        12 => 'XII'
    ];

    $romanMonth = $romanMonths[$month];
    return $romanMonth;
}

function normalizeDateTimeOrNull($s) {
    if (!isset($s) || $s === '' || $s === null) return null;
    $s = trim((string)$s);

    // coba beberapa format umum
    $formats = ['Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt) return $dt->format('Y-m-d H:i:s');
    }
    return null; // invalid → biarkan null (atau lempar error sendiri)
}

function findUserID($username, $conn) {
    // Sanitasi input untuk keamanan
    $username = mysqli_real_escape_string($conn, $username);

    // Query user_id berdasarkan username
    $find_query = "SELECT user_id FROM user WHERE username = '$username' LIMIT 1";
    $find_result = mysqli_query($conn, $find_query);

    if (!$find_result) {
        // Kalau query error (syntax/db issue)
        jsonResponse(500, 'Database query error', ['error' => mysqli_error($conn)]);
    }

    if (mysqli_num_rows($find_result) > 0) {
        // Ambil hasilnya
        $row = mysqli_fetch_assoc($find_result);
        return $row['user_id'];
    } else {
        // Kalau gak ketemu
        return null;
    }
}

function handle_menu_image_upload(array $file, string $bucket='menu'): array {
    $UPLOAD_ROOT = '/var/www/raki/uploads';
    $finfo = new finfo(FILEINFO_MIME_TYPE);
     $mime  = $finfo->file($file['tmp_name']);
    $ok = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!isset($ok[$mime])) throw new RuntimeException('Invalid image type');

    // buat subfolder per bulan
    $sub = $bucket . '/' . date('Y-m');
    $dir = "$UPLOAD_ROOT/$sub";
    if (!is_dir($dir)) mkdir($dir, 0775, true);

    $name = bin2hex(random_bytes(16)) . '.' . $ok[$mime];
    $dest = "/$dir/$name";

    // echo $file['tmp_name'];
    // echo $dest;

    if (!move_uploaded_file($file['tmp_name'], $dest))
        throw new RuntimeException('Failed to save uploaded file');

    // generate thumbnail 600px webp
    $thumb = pathinfo($name, PATHINFO_FILENAME) . '_thumb.webp';
    $thumbPath = "$dir/$thumb";
    // create_thumbnail($dest, $thumbPath, 600);

    // build URL publik
    $base = ($_SERVER['HTTPS'] ? 'http' : 'http') . '://getmovira.com';
    // . $_SERVER['HTTP_HOST'];
    return ["$base/raki-uploads/$sub/$name", "$base/raki-uploads/$sub/$thumb"];
}

function create_thumbnail(string $srcPath, string $dstPath, int $targetW = 600): void {
    if (!file_exists($srcPath)) {
        throw new RuntimeException("Source file tidak ditemukan: $srcPath");
    }

    [$width, $height, $type] = getimagesize($srcPath);
    if (!$width || !$height) {
        throw new RuntimeException("Gagal membaca ukuran gambar.");
    }

    // Hitung rasio tinggi-lebar
    $ratio = $height / $width;
    $newW  = $targetW;
    $newH  = (int) round($targetW * $ratio);

    // Buat resource image dari format yang sesuai
    switch ($type) {
        case IMAGETYPE_JPEG:
            $src = imagecreatefromjpeg($srcPath);
            break;
        case IMAGETYPE_PNG:
            $src = imagecreatefrompng($srcPath);
            imagealphablending($src, true);
            imagesavealpha($src, true);
            break;
        case IMAGETYPE_WEBP:
            if (!function_exists('imagecreatefromwebp')) {
                throw new RuntimeException('Server belum mendukung WEBP');
            }
            $src = imagecreatefromwebp($srcPath);
            break;
        default:
            throw new RuntimeException('Format gambar tidak didukung');
    }

    if (!$src) {
        throw new RuntimeException('Gagal membuka gambar sumber.');
    }

    // Buat canvas baru sesuai ukuran thumbnail
    $dst = imagecreatetruecolor($newW, $newH);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);

    // Resize (resample) gambar ke ukuran baru
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);

    // Simpan hasil sebagai WEBP kualitas 80
    if (!function_exists('imagewebp')) {
        imagedestroy($src);
        imagedestroy($dst);
        throw new RuntimeException('Server tidak mendukung penyimpanan WEBP.');
    }

    if (!imagewebp($dst, $dstPath, 80)) {
        throw new RuntimeException('Gagal menyimpan thumbnail.');
    }

    imagedestroy($src);
    imagedestroy($dst);
}

// Sesuaikan sama URL WAHA dashboard kamu
define('WAHA_BASE_URL', 'https://waha-e8n85xppf1xs.cgk-lab.sumopod.my.id');
define('WAHA_SESSION', 'session_movira_default');

// Kalau WAHA kamu pakai API key / basic auth, isi di sini:
define('WAHA_API_KEY', 'AZGSGUOZIoF4qSvHC6roINaxEkMXr1qO'); // kalau nggak pakai, biarkan kosong

?>