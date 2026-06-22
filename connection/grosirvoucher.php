<?php

class GrosirVoucher {

    private string $baseUrl;
    private string $id;
    private string $pin;
    private string $username;
    private string $password;

    public function __construct() {
        $this->baseUrl  = GV_BASE_URL;
        $this->id       = GV_ID;
        $this->pin      = GV_PIN;
        $this->username = GV_USERNAME;
        $this->password = GV_PASSWORD;
    }

    private function request(array $params): array {
        $body = array_merge([
            'id'       => $this->id,
            'pin'      => $this->pin,
            'username' => $this->username,
            'password' => $this->password,
        ], $params);

        $ch = curl_init($this->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException("GrosirVoucher cURL error ($errno): $error");
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GrosirVoucher returned invalid JSON: ' . $raw);
        }

        return $decoded;
    }

    public function checkBalance(): array {
        return $this->request(['cmd' => 'checkBalance']);
    }

    public function getProducts(?string $category = null): array {
        $params = ['cmd' => 'pricelist'];
        if ($category !== null && $category !== '') {
            $params['category'] = $category;
        }
        return $this->request($params);
    }

    public function purchase(string $productCode, string $msisdn, string $clientTrxId): array {
        return $this->request([
            'cmd'         => 'topup',
            'productCode' => $productCode,
            'msisdn'      => $msisdn,
            'clientTrxId' => $clientTrxId,
            'callbackUrl' => GV_CALLBACK_URL,
        ]);
    }

    public function checkStatus(string $clientTrxId): array {
        return $this->request([
            'cmd'         => 'checkStatus',
            'clientTrxId' => $clientTrxId,
        ]);
    }
}
