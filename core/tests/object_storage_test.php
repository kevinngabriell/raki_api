<?php
// Unit tests for core/ObjectStorage.php (no network).
//   php core/tests/object_storage_test.php
// CLI only: this directory is served by the PHP built-in server in production.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../ObjectStorage.php';

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

// AWS docs "Example: GET Object" (SigV4 header-based auth). Published signature below.
$auth = ObjectStorage::authorization(
    'GET', '/test.txt', '',
    [
        'host' => 'examplebucket.s3.amazonaws.com',
        'range' => 'bytes=0-9',
        'x-amz-content-sha256' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
        'x-amz-date' => '20130524T000000Z',
    ],
    'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    'us-east-1', 'AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', '20130524T000000Z',
);
check('aws get-object vector', $auth,
    'AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, '
    . 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, '
    . 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41');

$s = new ObjectStorage('https://kencana.basic.box.cloudeka.id/', 'kencana', 'bkt', 'k', 's', 'https://kencana.basic.box.cloudeka.id/bkt/', 'public-read');
check('publicUrl', $s->publicUrl('menu/2026-10/a.jpg'), 'https://kencana.basic.box.cloudeka.id/bkt/menu/2026-10/a.jpg');
check('keyFromUrl own', $s->keyFromUrl('https://kencana.basic.box.cloudeka.id/bkt/menu/2026-10/a.jpg'), 'menu/2026-10/a.jpg');
check('keyFromUrl legacy', $s->keyFromUrl('https://getmovira.com/raki-uploads/menu/2026-09/a.jpg'), null);
check('keyFromUrl null', $s->keyFromUrl(null), null);
check('keyFromUrl empty key', $s->keyFromUrl('https://kencana.basic.box.cloudeka.id/bkt/'), null);

$enc = new ObjectStorage('https://e.example', 'r', 'b', 'k', 's', 'https://e.example/b', null, ['https://old.example/b/']);
check('publicUrl encodes', $enc->publicUrl('menu/a b.jpg'), 'https://e.example/b/menu/a%20b.jpg');
check('keyFromUrl decodes', $enc->keyFromUrl('https://e.example/b/menu/a%20b.jpg'), 'menu/a b.jpg');
check('keyFromUrl previous base', $enc->keyFromUrl('https://old.example/b/menu/x.jpg'), 'menu/x.jpg');

$_ENV = [];
try {
    ObjectStorage::fromEnv();
    check('fromEnv unconfigured throws', false, true);
} catch (RuntimeException $e) {
    check('fromEnv lists missing keys', str_contains($e->getMessage(), 'S3_ACCESS_KEY_ID'), true);
}

$_ENV = ['S3_ENDPOINT' => 'https://e.example', 'S3_REGION' => 'r', 'S3_BUCKET' => 'b', 'S3_ACCESS_KEY_ID' => 'k', 'S3_SECRET_ACCESS_KEY' => 's'];
check('default public base', ObjectStorage::fromEnv()->publicUrl('x.jpg'), 'https://e.example/b/x.jpg');
$_ENV['S3_PUBLIC_BASE_URL'] = 'https://cdn.example/img';
check('custom public base', ObjectStorage::fromEnv()->publicUrl('x.jpg'), 'https://cdn.example/img/x.jpg');

echo $failures === 0 ? "OK ($checks checks)\n" : "$failures of $checks FAILED\n";
exit($failures === 0 ? 0 : 1);
