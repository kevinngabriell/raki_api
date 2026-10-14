<?php

final class RequestLogger
{
    private static string $requestId;
    private static string $clientRequestId;
    private static float $startTime;
    private static ?array $actor = null;
    private static int $httpCode = 200;
    private static ?string $errorCode = null;
    private static ?string $errorMessage = null;
    private static ?string $endpoint = null;
    private static ?array $exceptionData = null;
    private static bool $flushed = false;

    public static function init(?string $endpointPattern = null): void
    {
        self::$startTime = microtime(true);
        self::$requestId = self::generateUuidV7();
        self::$clientRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
        self::$endpoint = $endpointPattern ?? self::detectEndpoint();

        register_shutdown_function(fn() => self::flush());
    }

    public static function requestId(): string
    {
        return self::$requestId ?? '00000000-0000-7000-8000-000000000000';
    }

    public static function setActor(array $payload): void
    {
        self::$actor = $payload;
    }

    public static function setOutcome(int $httpCode, ?string $errorCode = null, ?string $errorMessage = null): void
    {
        self::$httpCode = $httpCode;
        if ($errorCode !== null) {
            self::$errorCode = $errorCode;
        }
        if ($errorMessage !== null) {
            self::$errorMessage = $errorMessage;
        }
    }

    public static function setException(Throwable $e): void
    {
        self::$exceptionData = [
            'class'   => $e::class,
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
            'trace'   => $e->getTraceAsString(),
        ];
    }

    public static function flush(): void
    {
        if (self::$flushed) {
            return;
        }
        self::$flushed = true;

        $durationMs = (int) round((microtime(true) - self::$startTime) * 1000);
        $outcome = self::$httpCode < 400 ? 'success' : 'error';
        $level = match (true) {
            self::$httpCode >= 500 => 'error',
            self::$httpCode >= 400 => 'warning',
            default => 'info',
        };

        $username    = self::$actor['username'] ?? null;
        $companyId   = self::$actor['company_id'] ?? null;
        $appId       = self::$actor['app_id'] ?? null;
        $ip          = getUserIP();
        $ua          = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $method      = $_SERVER['REQUEST_METHOD'] ?? 'CLI';

        try {
            $logDb = MOVIRA_CORE_SCHEMA;
            $conn = DB::conn($logDb);

            $stmt = $conn->prepare(
                "INSERT INTO log_request
                    (request_id, client_request_id, timestamp, endpoint, http_method,
                     status_code, outcome, level, error_code, error_message, retryable,
                     username, company_id, app_id,
                     ip_address, user_agent, duration_ms, db_connected,
                     exception_class, exception_message, exception_file, exception_line, stack_trace)
                 VALUES
                    (?, ?, NOW(3), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)"
            );

            $retryable   = self::$httpCode < 500 ? 1 : 0;
            $clientReqId = self::$clientRequestId ?: null;
            $errMsg      = self::$errorMessage ? mb_substr(self::$errorMessage, 0, 500) : null;
            $excClass    = self::$exceptionData['class'] ?? null;
            $excMsg      = mb_substr(self::$exceptionData['message'] ?? '', 0, 65535);
            $excFile     = self::$exceptionData['file'] ?? null;
            $excLine     = self::$exceptionData['line'] ?? null;
            $excTrace    = mb_substr(self::$exceptionData['trace'] ?? '', 0, 65535);

            $uaTrimmed   = mb_substr($ua, 0, 500);

            $stmt->bind_param(
                'ssssissssisssssisssis',
                self::$requestId,
                $clientReqId,
                self::$endpoint,
                $method,
                self::$httpCode,
                $outcome,
                $level,
                self::$errorCode,
                $errMsg,
                $retryable,
                $username,
                $companyId,
                $appId,
                $ip,
                $uaTrimmed,
                $durationMs,
                $excClass,
                $excMsg,
                $excFile,
                $excLine,
                $excTrace,
            );

            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            $row = [
                'request_id'  => self::$requestId,
                'endpoint'    => self::$endpoint,
                'status_code' => self::$httpCode,
                'error_code'  => self::$errorCode,
                'duration_ms' => $durationMs,
                'ip'          => $ip,
                'timestamp'   => date('Y-m-d H:i:s'),
            ];
            $line = json_encode($row, JSON_UNESCAPED_UNICODE);
            $date = date('Y-m-d');
            $dir  = __DIR__ . '/../storage/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents("$dir/request-$date.jsonl", $line . "\n", FILE_APPEND);
        }
    }

    private static function detectEndpoint(): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '/';
        $path = str_replace('/raki-api/', '', $script);
        $segments = explode('/', trim($path, '/'));
        $pattern = count($segments) > 0
            ? preg_replace('/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}|^[a-zA-Z0-9_]{10,}$/', '{id}', implode('/', $segments))
            : '/';
        return $pattern;
    }

    private static function generateUuidV7(): string
    {
        $ts = floor(microtime(true) * 1000);
        $tsHex = sprintf('%012x', $ts);

        $rand = random_bytes(10);
        $rand[0] = chr((ord($rand[0]) & 0x0F) | 0x70);
        $rand[2] = chr((ord($rand[2]) & 0x3F) | 0x80);

        $uuid = substr($tsHex, 0, 8) . '-'
              . substr($tsHex, 8, 4) . '-'
              . bin2hex(substr($rand, 0, 2)) . '-'
              . bin2hex(substr($rand, 2, 2)) . '-'
              . bin2hex(substr($rand, 4, 6));

        return $uuid;
    }
}
