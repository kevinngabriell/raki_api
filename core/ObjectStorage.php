<?php

/**
 * Minimal S3-compatible client (AWS Signature V4, path-style) for the menu/package photos.
 * No SDK on purpose: vendor/ is committed to git and the deploy does not run composer.
 *
 * Config (.env):
 *   S3_ENDPOINT           e.g. https://kencana.basic.box.cloudeka.id
 *   S3_REGION             e.g. kencana
 *   S3_BUCKET             bucket name
 *   S3_ACCESS_KEY_ID
 *   S3_SECRET_ACCESS_KEY
 *   S3_PUBLIC_BASE_URL    optional, base of the URLs stored in the DB. Default: {endpoint}/{bucket}
 *   S3_PREVIOUS_PUBLIC_BASE_URLS  optional, comma-separated old S3_PUBLIC_BASE_URL values, so photos stored under
 *                         them can still be removed after the base URL changes
 *   S3_OBJECT_ACL         optional, default "public-read". Set to "none" to send no ACL header
 *                         (then the bucket itself must allow public reads).
 */
final class ObjectStorage
{
    private const SERVICE = 's3';

    public function __construct(
        private string $endpoint,
        private string $region,
        private string $bucket,
        private string $accessKey,
        private string $secretKey,
        private string $publicBaseUrl,
        private ?string $acl,
        private array $previousBaseUrls = [],
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->publicBaseUrl = rtrim($publicBaseUrl, '/');
        $this->previousBaseUrls = array_map(fn($u) => rtrim($u, '/'), $previousBaseUrls);
    }

    public static function fromEnv(): self
    {
        $required = ['S3_ENDPOINT', 'S3_REGION', 'S3_BUCKET', 'S3_ACCESS_KEY_ID', 'S3_SECRET_ACCESS_KEY'];
        $missing = [];
        foreach ($required as $name) {
            if (trim((string)($_ENV[$name] ?? '')) === '') {
                $missing[] = $name;
            }
        }
        if ($missing) {
            throw new RuntimeException('Object storage is not configured, missing: ' . implode(', ', $missing));
        }

        $endpoint = trim($_ENV['S3_ENDPOINT']);
        $bucket   = trim($_ENV['S3_BUCKET']);
        $public   = trim((string)($_ENV['S3_PUBLIC_BASE_URL'] ?? ''));
        $acl      = trim((string)($_ENV['S3_OBJECT_ACL'] ?? 'public-read'));

        return new self(
            $endpoint,
            trim($_ENV['S3_REGION']),
            $bucket,
            trim($_ENV['S3_ACCESS_KEY_ID']),
            trim($_ENV['S3_SECRET_ACCESS_KEY']),
            $public !== '' ? $public : rtrim($endpoint, '/') . '/' . $bucket,
            ($acl === '' || strtolower($acl) === 'none') ? null : $acl,
            array_values(array_filter(array_map('trim', explode(',', (string)($_ENV['S3_PREVIOUS_PUBLIC_BASE_URLS'] ?? ''))))),
        );
    }

    public function put(string $key, string $body, string $contentType): string
    {
        $headers = ['content-type' => $contentType];
        if ($this->acl !== null) {
            $headers['x-amz-acl'] = $this->acl;
        }
        $status = $this->request('PUT', $key, $body, $headers, $responseBody);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Object storage PUT $key failed (HTTP $status): " . substr($responseBody, 0, 300));
        }
        return $this->publicUrl($key);
    }

    /** Idempotent: a missing object is not an error. */
    public function delete(string $key): void
    {
        $status = $this->request('DELETE', $key, '', [], $responseBody);
        if (($status < 200 || $status >= 300) && $status !== 404) {
            throw new RuntimeException("Object storage DELETE $key failed (HTTP $status): " . substr($responseBody, 0, 300));
        }
    }

    public function publicUrl(string $key): string
    {
        return $this->publicBaseUrl . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    /** Object key for a URL that lives in this bucket, null for anything else (e.g. legacy local-disk URLs). */
    public function keyFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }
        foreach (array_merge([$this->publicBaseUrl], $this->previousBaseUrls) as $base) {
            $prefix = $base . '/';
            if (str_starts_with($url, $prefix)) {
                $key = implode('/', array_map('rawurldecode', explode('/', substr($url, strlen($prefix)))));
                return $key === '' ? null : $key;
            }
        }
        return null;
    }

    /**
     * SigV4 Authorization header value.
     *
     * @param array<string,string> $headers header name => value, every one of them is signed
     */
    public static function authorization(
        string $method,
        string $canonicalUri,
        string $canonicalQuery,
        array $headers,
        string $payloadHash,
        string $region,
        string $accessKey,
        string $secretKey,
        string $amzDate,
    ): string {
        $canon = [];
        foreach ($headers as $name => $value) {
            $canon[strtolower($name)] = trim(preg_replace('/\s+/', ' ', (string)$value));
        }
        ksort($canon);

        $canonicalHeaders = '';
        foreach ($canon as $name => $value) {
            $canonicalHeaders .= "$name:$value\n";
        }
        $signedHeaders = implode(';', array_keys($canon));

        $canonicalRequest = implode("\n", [
            $method, $canonicalUri, $canonicalQuery, $canonicalHeaders, $signedHeaders, $payloadHash,
        ]);

        $date  = substr($amzDate, 0, 8);
        $scope = "$date/$region/" . self::SERVICE . '/aws4_request';

        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonicalRequest),
        ]);

        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', self::SERVICE, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return "AWS4-HMAC-SHA256 Credential=$accessKey/$scope, SignedHeaders=$signedHeaders, Signature=$signature";
    }

    private function request(string $method, string $key, string $body, array $extraHeaders, ?string &$responseBody): int
    {
        $parts = parse_url($this->endpoint);
        if (!$parts || empty($parts['host'])) {
            throw new RuntimeException('S3_ENDPOINT is not a valid URL');
        }
        $host = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $scheme = $parts['scheme'] ?? 'https';

        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
        $uri = rtrim($parts['path'] ?? '', '/') . '/' . rawurlencode($this->bucket) . '/' . $encodedKey;

        $amzDate = gmdate('Ymd\THis\Z');
        $payloadHash = hash('sha256', $body);

        $headers = array_merge($extraHeaders, [
            'host'                 => $host,
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date'           => $amzDate,
        ]);

        $auth = self::authorization(
            $method, $uri, '', $headers, $payloadHash,
            $this->region, $this->accessKey, $this->secretKey, $amzDate,
        );

        $curlHeaders = ['Authorization: ' . $auth, 'Expect:'];
        foreach ($headers as $name => $value) {
            if ($name !== 'host') {
                $curlHeaders[] = "$name: $value";
            }
        }

        $ch = curl_init("$scheme://$host$uri");
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $result = curl_exec($ch);
        if ($result === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("Object storage $method $key failed: $err");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $responseBody = (string)$result;
        return $status;
    }
}
