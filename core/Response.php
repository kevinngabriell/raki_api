<?php

final class Response
{
    public static function success(mixed $data, ?array $meta = null, int $httpCode = 200, ?string $message = null): never
    {
        self::emit([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'meta'    => $meta,
            'error'   => null,
        ], $httpCode);
    }

    public static function error(string $code, string $message, int $httpStatus, array $details = []): never
    {
        $requestId = RequestLogger::requestId();

        self::emit([
            'success' => false,
            'data'    => null,
            'meta'    => null,
            'error'   => [
                'code'        => $code,
                'message'     => $message,
                'http_status' => $httpStatus,
                'details'     => $details,
                'request_id'  => $requestId,
            ],
        ], $httpStatus);
    }

    public static function notFound(string $message = 'Data tidak ditemukan'): never
    {
        self::error('ERR_COMMON_002', $message, 404);
    }

    public static function methodNotAllowed(): never
    {
        self::error('ERR_COMMON_006', 'Metode HTTP tidak diizinkan untuk endpoint ini', 405);
    }

    public static function forbidden(string $message = 'Anda tidak memiliki izin untuk mengakses endpoint ini'): never
    {
        self::error('ERR_COMMON_003', $message, 403);
    }

    public static function tooManyRequests(int $retryAfter = 60): never
    {
        self::error(
            'ERR_COMMON_004',
            'Terlalu banyak permintaan. Silakan coba lagi dalam beberapa saat.',
            429,
            ['retry_after_seconds' => $retryAfter],
        );
    }

    public static function validationError(array $fieldErrors): never
    {
        self::error(
            'ERR_COMMON_001',
            'Validasi gagal',
            422,
            ['fields' => $fieldErrors],
        );
    }

    public static function internalError(): never
    {
        self::error(
            'ERR_COMMON_005',
            'Terjadi kesalahan pada server. Tim kami telah diberi notifikasi.',
            500,
        );
    }

    private static function emit(array $body, int $httpCode): never
    {
        RequestLogger::setOutcome($httpCode);

        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Request-ID: ' . RequestLogger::requestId());
        }

        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
