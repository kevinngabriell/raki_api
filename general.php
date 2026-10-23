<?php

require_once __DIR__ . '/core/ErrorCode.php';
require_once __DIR__ . '/core/ApiException.php';
require_once __DIR__ . '/core/Response.php';
require_once __DIR__ . '/core/RequestLogger.php';
require_once __DIR__ . '/core/ObjectStorage.php';
require_once __DIR__ . '/core/Access.php';

$isCli = php_sapi_name() === 'cli';

date_default_timezone_set('Asia/Jakarta');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/storage/logs/php-errors.log');

set_exception_handler(function (Throwable $e): void {
    if ($e instanceof ApiException) {
        RequestLogger::setOutcome($e->httpStatus, $e->errCode, $e->getMessage());
        Response::error($e->errCode, $e->getMessage(), $e->httpStatus, $e->details);
    }

    RequestLogger::setException($e);
    RequestLogger::setOutcome(500, 'ERR_COMMON_005', $e->getMessage());
    Response::internalError();
});

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    // Legacy endpoints rely on warnings/notices/deprecations being non-fatal.
    if ($severity & (E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED | E_STRICT)) {
        error_log("PHP [{$severity}] {$message} in {$file}:{$line}");
        return true;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// .env must be loaded before the CORS check below; endpoints include this file before config.php.
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';

if (!$isCli) {
    $origin =$_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed_origins = array_values(array_filter(array_map('trim', explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'http://localhost:3000'))));

    if (in_array($origin, $allowed_origins, true)) {
        header("Access-Control-Allow-Origin: $origin");
        header("Access-Control-Allow-Credentials: true");
        header("Vary: Origin");
    }

    header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Origin, Accept, X-Request-ID");

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(200);
        exit();
    }

    RequestLogger::init();
} else {
    RequestLogger::init('cli');
}

// Every list endpoint pages with at most this many rows per call (performance); a bigger ?limit is clamped, not refused.
const MAX_PAGE_LIMIT = 15;

function pageLimit($value, int $default = 10): int {
    $n = (int)$value;
    return min(MAX_PAGE_LIMIT, $n >= 1 ? $n : min($default, MAX_PAGE_LIMIT));
}

function jsonResponse($code, $message, $data = []): never
{
    if ($code >= 200 && $code < 300) {
        Response::success($data, null, $code, (string)$message);
    }

    $errorCode = 'ERR_COMMON_005';
    if ($code === 400) $errorCode = 'ERR_COMMON_007';
    elseif ($code === 401) $errorCode = 'ERR_AUTH_002';
    elseif ($code === 403) $errorCode = 'ERR_COMMON_003';
    elseif ($code === 404) $errorCode = 'ERR_COMMON_002';
    elseif ($code === 405) $errorCode = 'ERR_COMMON_006';
    elseif ($code === 409) $errorCode = 'ERR_COMMON_008';
    elseif ($code === 422) $errorCode = 'ERR_COMMON_001';
    elseif ($code === 429) $errorCode = 'ERR_COMMON_004';

    Response::error($errorCode, $message, $code, is_array($data) ? $data : []);
}

function cleanInput($conn, $value, $default = '-', $rejects = ['0', '']) {
    if (!isset($value) || in_array(trim((string)$value), $rejects, true)) {
        return $default;
    }
    return mysqli_real_escape_string($conn, trim((string)$value));
}

function getUserIP() {
    // Forwarded headers are client-controlled; only trust them from a local proxy.
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (in_array($remote, ['127.0.0.1', '::1'], true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $remote;
}

function getCurrentDateTimeJakarta(): string {
    $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    return $dt->format('Y-m-d H:i:s');
}

function getMonthRoman(): string {
    date_default_timezone_set('Asia/Jakarta');
    $month = date('n');

    $romanMonths = [
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV',
        5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII',
        9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'
    ];

    return $romanMonths[$month];
}

function normalizeDateTimeOrNull($s) {
    if (!isset($s) || $s === '' || $s === null) return null;
    $s = trim((string)$s);

    $formats = ['Y-m-d H:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d'];
    foreach ($formats as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt) return $dt->format('Y-m-d H:i:s');
    }
    return null;
}

function findUserID($username, $conn) {
    $stmt = $conn->prepare("SELECT user_id FROM user WHERE username = ? LIMIT 1");
    if (!$stmt) {
        Response::internalError();
    }
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row['user_id'];
    }
    $stmt->close();
    return null;
}

/**
 * Validates an uploaded photo, builds a 600px WEBP thumbnail and stores both in the S3-compatible bucket.
 * Returns [image_url, thumb_url]. Throws ApiException (400) for a bad file, RuntimeException for storage faults.
 */
/** Owner-only rule for managing the menu module (menu, category, package). Role ids: MENU_MANAGER_ROLE_IDS, comma-separated. */
function isMenuManager(object $decoded): bool {
    $roles = array_filter(array_map('trim', explode(',', $_ENV['MENU_MANAGER_ROLE_IDS'] ?? 'app_role6902bbb67a429')));
    return in_array($decoded->role ?? '', $roles, true);
}

/** Logs (via log.php) and sends an error response. */
function apiReject($conn, string $endpoint, string $method, ?string $username, int $status, string $message): never {
    logApiError($conn, [
        'error_level'   => $status >= 500 ? 'error' : 'warning',
        'http_status'   => $status,
        'endpoint'      => $endpoint,
        'method'        => $method,
        'error_message' => $message,
        'user_identifier' => $username,
        'company_id'      => null,
    ]);
    jsonResponse($status, $message);
}

/** Runs a "SELECT COUNT(...) ... WHERE x = ?" style query with one string parameter. */
function referenceCount($conn, string $sql, string $id): int {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Database query error');
    }
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_row()[0] ?? 0);
    $stmt->close();
    return $n;
}

function iniBytes(string $value): int {
    $value = trim($value);
    $n = (int)$value;
    return match (strtolower(substr($value, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

/** PHP silently empties $_POST/$_FILES when the body exceeds post_max_size; say so instead of failing confusingly. */
function rejectOversizedBody(): void {
    $limit = iniBytes((string)ini_get('post_max_size'));
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($limit > 0 && $len > $limit) {
        throw new ApiException('ERR_COMMON_007', 413, 'Upload is too large for the server (limit ' . ini_get('post_max_size') . ')');
    }
}

function handle_menu_image_upload(array $file, string $bucket = 'menu'): array {
    $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new ApiException('ERR_COMMON_007', 413, 'Image is larger than the server upload limit (' . ini_get('upload_max_filesize') . ')');
    }
    if ($err !== UPLOAD_ERR_OK) {
        throw new ApiException('ERR_COMMON_007', 400, 'Upload error code: ' . $err);
    }

    $maxBytes = (int)($_ENV['IMAGE_MAX_BYTES'] ?? 10 * 1024 * 1024);
    if (($file['size'] ?? 0) > $maxBytes) {
        throw new ApiException('ERR_COMMON_007', 400, 'Image is too large (max ' . round($maxBytes / 1048576, 1) . ' MB)');
    }

    $ok = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $size = isset($ok[$mime]) ? @getimagesize($file['tmp_name']) : false;
    if ($size === false) {
        throw new ApiException('ERR_COMMON_007', 400, 'Invalid image type (JPG, PNG or WEBP only)');
    }
    // GD needs width x height x 4 bytes of memory; a memory-limit fatal can't be caught.
    $maxSide = (int)($_ENV['IMAGE_MAX_SIDE_PX'] ?? 4000);
    if ($size[0] > $maxSide || $size[1] > $maxSide) {
        throw new ApiException('ERR_COMMON_007', 400, "Image dimensions are too large (max {$maxSide}px per side)");
    }

    $storage = ObjectStorage::fromEnv();

    $thumbTmp = tempnam(sys_get_temp_dir(), 'raki_thumb_');
    try {
        try {
            create_thumbnail($file['tmp_name'], $thumbTmp);
        } catch (Throwable $e) { // GD warnings arrive as ErrorException, not RuntimeException
            throw new ApiException('ERR_COMMON_007', 400, 'Image cannot be processed');
        }

        $base  = $bucket . '/' . date('Y-m') . '/' . bin2hex(random_bytes(16));
        $imageKey = $base . '.' . $ok[$mime];
        $thumbKey = $base . '_thumb.webp';

        // Store a re-encoded copy: drops EXIF/GPS and any payload appended to the original bytes.
        $cleanTmp = tempnam(sys_get_temp_dir(), 'raki_clean_');
        try {
            reencode_image($file['tmp_name'], $cleanTmp, $size[2]);
        } catch (Throwable $e) {
            @unlink($cleanTmp);
            throw new ApiException('ERR_COMMON_007', 400, 'Image cannot be processed');
        }
        try {
            $imageUrl = $storage->put($imageKey, file_get_contents($cleanTmp), $mime);
        } finally {
            @unlink($cleanTmp);
        }
        try {
            $thumbUrl = $storage->put($thumbKey, file_get_contents($thumbTmp), 'image/webp');
        } catch (Throwable $e) {
            try { $storage->delete($imageKey); } catch (Throwable $ignored) {}
            throw $e;
        }
    } finally {
        @unlink($thumbTmp);
    }

    return [$imageUrl, $thumbUrl];
}

/** Removes photos that live in our bucket. Legacy URLs (old local-disk uploads) are left alone. Never throws. */
function delete_menu_image_urls(array $urls): void {
    try {
        $storage = ObjectStorage::fromEnv();
        foreach (array_unique(array_filter($urls)) as $url) {
            $key = $storage->keyFromUrl($url);
            if ($key !== null) {
                $storage->delete($key);
            }
        }
    } catch (Throwable $e) {
        error_log('delete_menu_image_urls: ' . $e->getMessage());
    }
}

/** Decodes and re-saves an image in its own format, upright, with all metadata stripped. */
function reencode_image(string $srcPath, string $dstPath, int $type): void {
    switch ($type) {
        case IMAGETYPE_JPEG: $img = imagecreatefromjpeg($srcPath); break;
        case IMAGETYPE_PNG:  $img = imagecreatefrompng($srcPath);  break;
        case IMAGETYPE_WEBP: $img = function_exists('imagecreatefromwebp') ? imagecreatefromwebp($srcPath) : false; break;
        default: throw new RuntimeException('Format gambar tidak didukung');
    }
    if (!$img) {
        throw new RuntimeException('Gagal membuka gambar sumber.');
    }

    // Metadata is dropped, so bake the EXIF orientation into the pixels.
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        try {
            $orientation = (int)(@exif_read_data($srcPath)['Orientation'] ?? 1);
        } catch (Throwable $e) {
            $orientation = 1;
        }
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0 && ($rotated = imagerotate($img, $angle, 0))) {
            imagedestroy($img);
            $img = $rotated;
        }
    }

    if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
        imagealphablending($img, false);
        imagesavealpha($img, true);
    }
    $ok = match ($type) {
        IMAGETYPE_JPEG => imagejpeg($img, $dstPath, 90),
        IMAGETYPE_PNG  => imagepng($img, $dstPath, 6),
        IMAGETYPE_WEBP => imagewebp($img, $dstPath, 90),
    };
    imagedestroy($img);
    if (!$ok) {
        throw new RuntimeException('Gagal menyimpan gambar.');
    }
}

function create_thumbnail(string $srcPath, string $dstPath, int $targetW = 600): void {
    if (!file_exists($srcPath)) {
        throw new RuntimeException("Source file tidak ditemukan: $srcPath");
    }

    [$width, $height, $type] = getimagesize($srcPath);
    if (!$width || !$height) {
        throw new RuntimeException("Gagal membaca ukuran gambar.");
    }

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

    // Browsers show the original upright via its EXIF tag, but WEBP output drops EXIF: rotate the thumbnail to match.
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        try {
            $orientation = (int)(@exif_read_data($srcPath)['Orientation'] ?? 1);
        } catch (Throwable $e) {
            $orientation = 1;
        }
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
        if ($angle !== 0) {
            $rotated = imagerotate($src, $angle, 0);
            if ($rotated) {
                imagedestroy($src);
                $src = $rotated;
                $width  = imagesx($src);
                $height = imagesy($src);
            }
        }
    }

    $newW = min($targetW, $width);
    $newH = (int) round($newW * ($height / $width));

    $dst = imagecreatetruecolor($newW, $newH);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $width, $height);

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

function getAuthHeaders(): array {
    $rawHeaders = getallheaders();
    if ($rawHeaders === false) {
        $rawHeaders = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', ' ', substr($key, 5));
                $name = str_replace(' ', '-', ucwords(strtolower($name)));
                $rawHeaders[$name] = $value;
            }
        }
    }
    return array_change_key_case($rawHeaders, CASE_LOWER);
}

function requireAuth(): object {
    $headers = getAuthHeaders();
    $authHeader = $headers['authorization'] ?? null;

    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        Response::error('ERR_AUTH_002', 'Authorization header tidak ditemukan', 401);
    }

    $token = substr($authHeader, 7);

    try {
        $decoded = Firebase\JWT\JWT::decode($token, new Firebase\JWT\Key($_ENV['JWT_SECRET'], 'HS256'));

        RequestLogger::setActor([
            'username'   => $decoded->username ?? null,
            'company_id' => $decoded->company_id ?? null,
            'app_id'     => $decoded->app_id ?? null,
        ]);
    } catch (Exception $e) {
        Response::error('ERR_AUTH_003', 'Token tidak valid atau sudah kedaluwarsa', 401);
    }

    requireActiveAccount($decoded);
    return $decoded;
}

/**
 * A user who was set to nonaktif while logged in loses the session on the next request:
 * 401 with details.reason = "inactive" (the apps clear the token and return to Login).
 */
function requireActiveAccount(object $decoded): void {
    $username = (string)($decoded->username ?? '');
    if ($username === '' || !class_exists('DB')) {
        return;
    }
    require_once __DIR__ . '/account/account_rules.php';

    $coreSchema = $_ENV['MOVIRA_CORE_SCHEMA'] ?? '';
    $appId = ACCOUNT_RAKI_APP_ID;
    $stmt = DB::conn()->prepare("SELECT account_status FROM {$coreSchema}.app_user WHERE username = ? AND app_id = ? LIMIT 1");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('ss', $username, $appId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row && accountIsNonaktif($row['account_status'] ?? null)) {
        Response::error('ERR_AUTH_002', 'Akun kamu nonaktif. Hubungi admin RAKI.', 401, ['reason' => 'inactive']);
    }
}

function otpHash(string $code): string
{
    $pepper = $_ENV['OTP_PEPPER'] ?? '';
    if ($pepper === '') {
        throw new Exception('OTP_PEPPER is not configured');
    }
    return hash_hmac('sha256', $code, $pepper);
}

?>
