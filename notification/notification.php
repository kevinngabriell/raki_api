<?php

require_once '../connection/db.php';
require_once '../vendor/autoload.php';
require_once '../general.php';
require_once '../config.php';

function sendWhatsAppText($chatId, $text, $session = WAHA_SESSION) {
    $url = rtrim(WAHA_BASE_URL, '/') . '/api/sendText';

    $payload = [
        'chatId'  => $chatId,
        'text'    => $text,
        'session' => $session,
    ];

    $ch = curl_init($url);

    $headers = [
        'Content-Type: application/json',
    ];

    // Kalau pakai API key / token, tambahin header di sini
    if (!empty(WAHA_API_KEY)) {
        $headers[] = 'X-Api-Key: ' . WAHA_API_KEY;
        // atau 'Authorization: Bearer ' . WAHA_API_KEY;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 10,
        // Kalau SSL-nya self-signed dan suka error, bisa sementara dimatikan:
        // CURLOPT_SSL_VERIFYPEER => false,
        // CURLOPT_SSL_VERIFYHOST => false,
    ]);

    $responseBody = curl_exec($ch);
    $errno        = curl_errno($ch);
    $error        = curl_error($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($errno) {
        error_log('WAHA CURL error: ' . $error);
        return [
            'success' => false,
            'httpCode' => $httpCode,
            'error' => $error,
            'raw' => $responseBody,
        ];
    }

    $json = json_decode($responseBody, true);

    return [
        'success'  => $httpCode >= 200 && $httpCode < 300,
        'httpCode' => $httpCode,
        'data'     => $json,
        'raw'      => $responseBody,
    ];
}

?>