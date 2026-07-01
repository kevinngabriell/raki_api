# WhatsApp Notification API using WAHA

This document explains how this project sends WhatsApp notifications through
**WAHA (WhatsApp HTTP API)**, so the same pattern can be cloned into other
projects. It covers the WAHA setup, the reusable PHP helper, how it's wired
into existing endpoints, and how to adapt it to a different stack.

---

## 1. What is WAHA

[WAHA](https://waha.devlike.pro/) is a self-hosted HTTP API that wraps a real
WhatsApp Web session. You run a WAHA server (Docker container), scan a QR
code once to link a WhatsApp number, and afterwards you send messages by
making plain HTTP `POST` requests to that server — no official WhatsApp
Business API approval needed.

Flow used in this project:

```
Your API (PHP)  --HTTP POST-->  WAHA server  --WhatsApp Web session-->  Recipient
```

## 2. Prerequisites

1. A running WAHA instance reachable over HTTPS (e.g. deployed via Docker on
   your own server/VPS).
2. A **session** created and authenticated (QR-scanned) on that WAHA
   instance — sessions are identified by name (e.g. `session_movira_default`).
3. (Optional but recommended) An API key configured on the WAHA server so
   the endpoint isn't publicly open.

WAHA session status can be checked at:
`GET {WAHA_BASE_URL}/api/sessions/{session}`

## 3. Configuration

Define WAHA connection settings as constants (or env vars) so they can be
reused across every file that sends WhatsApp messages. In this project
they live in `general.php`:

```php
// general.php
define('WAHA_BASE_URL', 'https://your-waha-instance.example.com');
define('WAHA_SESSION',  'session_movira_default');
define('WAHA_API_KEY',  'your-waha-api-key'); // leave empty string if not used
```

> When cloning to a new project, prefer sourcing these from `.env` (this
> repo already has a `loadEnv()`/`vlucas/phpdotenv` pattern in `config.php`)
> instead of hardcoding, e.g.:
> ```php
> define('WAHA_BASE_URL', $_ENV['WAHA_BASE_URL'] ?? '');
> define('WAHA_SESSION',  $_ENV['WAHA_SESSION']  ?? '');
> define('WAHA_API_KEY',  $_ENV['WAHA_API_KEY']  ?? '');
> ```

## 4. The reusable helper function

This is the core piece to clone. It lives at the top of
`notification/notification.php` and is `require_once`'d by any endpoint that
needs to send a WhatsApp message.

```php
function sendWhatsAppText($chatId, $text, $session = WAHA_SESSION) {
    if (!function_exists('curl_init')) {
        return [
            'error'   => true,
            'message' => 'curl_init MISSING',
            'php'     => PHP_VERSION,
            'sapi'    => php_sapi_name(),
        ];
    }

    $url = rtrim(WAHA_BASE_URL, '/') . '/api/sendText';

    $payload = [
        'chatId'  => $chatId,   // e.g. "6281234567890@c.us"
        'text'    => $text,
        'session' => $session,
    ];

    $ch = curl_init($url);

    $headers = ['Content-Type: application/json'];
    if (!empty(WAHA_API_KEY)) {
        $headers[] = 'X-Api-Key: ' . WAHA_API_KEY;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 10,
    ]);

    $responseBody = curl_exec($ch);
    $errno        = curl_errno($ch);
    $error        = curl_error($ch);
    $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        error_log('WAHA CURL error: ' . $error);
        return [
            'success'  => false,
            'httpCode' => $httpCode,
            'error'    => $error,
            'raw'      => $responseBody,
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
```

### Key design points worth keeping when you clone this

- **`chatId` format**: WAHA expects `"<countrycode+number>@c.us"` for
  individual chats (no `+`, no leading `0`, no spaces/dashes). Always sanitize
  the raw phone number before appending `@c.us`:
  ```php
  $ownerPhone = preg_replace('/[^0-9]/', '', $rawPhoneFromDb);
  $chatId     = $ownerPhone . '@c.us';
  ```
- **Never let a WhatsApp failure break the main request.** In every endpoint
  in this codebase, the WhatsApp send happens *after* the primary business
  logic (order/transaction/session already saved), and its result is not
  used to fail the HTTP response — it's just logged/returned informationally.
- **Timeout**: keep `CURLOPT_TIMEOUT` short (10s) so a slow/down WAHA
  instance doesn't hang the whole request.
- **Return a structured result** (`success`, `httpCode`, `data`/`error`,
  `raw`) instead of a boolean, so callers can log or surface the WAHA
  response if needed.

## 5. Wiring it into an endpoint

Pattern used across `session/end.php`, `transaction/index.php`,
`supply/order.php`, `account/otp.php`:

```php
require_once '../notification/notification.php'; // brings in sendWhatsAppText()

// ... after the main DB write succeeds ...

$stmtPhone = $conn->prepare("SELECT pic_contact FROM app_company WHERE company_id = ?");
$stmtPhone->bind_param('s', $company_id);
$stmtPhone->execute();
$rowPhone = $stmtPhone->get_result()->fetch_assoc();

if (!empty($rowPhone['pic_contact'])) {
    $ownerPhone = preg_replace('/[^0-9]/', '', $rowPhone['pic_contact']);
    $chatId     = $ownerPhone . '@c.us';

    $text = "Notification message here...";

    $waResult = sendWhatsAppText($chatId, $text);
    // optionally inspect $waResult['success'] for logging
}
```

> **Note on `require_once`:** `notification/notification.php` doubles as both
> the helper-function file *and* a standalone cron/HTTP endpoint (weekly
> recap). It guards its own execution with:
> ```php
> $isCli  = php_sapi_name() === 'cli';
> $isHttp = !$isCli && basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__);
> if (!$isCli && !$isHttp) {
>     return; // being require_once'd by another file — just expose the function
> }
> ```
> If you clone this pattern, either keep that guard, or — cleaner for a new
> project — split the helper into its own file (e.g. `whatsapp_client.php`)
> containing only `sendWhatsAppText()`, and keep cron/report logic in a
> separate file that requires it. This avoids the "is this file an include or
> an endpoint" ambiguity.

## 6. Standalone notification endpoint (scheduled / on-demand recap)

`notification/notification.php` also works as an HTTP+CLI endpoint for
broadcasting recap messages (e.g. weekly summary to every company's PIC).
Reusable shape:

- **CLI** (via cron): runs unconditionally, loops all companies for the
  configured `app_id`, sends one message per company.
- **HTTP GET**: restricted to a specific time window (e.g. Saturdays 21:00
  WIB) unless `?debug=1` is passed; supports `?company_id=` to target a
  single company instead of all.

```
GET /notification/notification.php?debug=1&company_id=<id>
```

Response:
```json
{
  "status_code": 200,
  "status_message": "WhatsApp recap processed. Check per-company results.",
  "results": [
    {
      "company_id": "company123",
      "company_name": "Outlet A",
      "pic_contact": "6281234567890",
      "success": true,
      "whatsapp": { "success": true, "httpCode": 200, "data": { "...": "..." } }
    }
  ]
}
```

Example cron entry (runs every hour; the script itself enforces the actual
send window):
```
0 * * * * php /path/to/project/notification/notification.php >> /var/log/wa_recap.log 2>&1
```

## 7. Testing directly against WAHA (no PHP involved)

Useful for verifying the WAHA server/session itself before debugging your
PHP code:

```bash
curl -X POST "https://your-waha-instance.example.com/api/sendText" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: your-waha-api-key" \
  -d '{
        "chatId": "6281234567890@c.us",
        "text": "Test message from WAHA",
        "session": "session_movira_default"
      }'
```

Check session is authenticated:
```bash
curl "https://your-waha-instance.example.com/api/sessions/session_movira_default" \
  -H "X-Api-Key: your-waha-api-key"
```

## 8. Cloning this into a different project / stack

The only things that are PHP/MySQL-specific are: pulling `pic_contact` from
the DB, and the `$conn`/`logApiError` calls. Everything else is a plain HTTP
POST and can be ported to any language almost verbatim:

1. Store `WAHA_BASE_URL`, `WAHA_SESSION`, `WAHA_API_KEY` as env vars/secrets.
2. Implement one function/method: `sendWhatsAppText(chatId, text, session)`
   that POSTs JSON `{chatId, text, session}` to `{WAHA_BASE_URL}/api/sendText`
   with header `X-Api-Key` (if set) and a short timeout.
3. Sanitize phone numbers to digits-only and append `@c.us` before calling it.
4. Call it after your core business logic succeeds; treat WhatsApp failures
   as non-fatal (log them, don't reject the request).
5. Return/propagate a structured result (`success`, `httpCode`, `data`,
   `error`) so failures are debuggable.

Example minimal Node.js equivalent for reference when porting:
```js
async function sendWhatsAppText(chatId, text, session = process.env.WAHA_SESSION) {
  const res = await fetch(`${process.env.WAHA_BASE_URL.replace(/\/$/, '')}/api/sendText`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(process.env.WAHA_API_KEY ? { 'X-Api-Key': process.env.WAHA_API_KEY } : {}),
    },
    body: JSON.stringify({ chatId, text, session }),
    signal: AbortSignal.timeout(10000),
  });
  const data = await res.json().catch(() => null);
  return { success: res.ok, httpCode: res.status, data };
}
```

## 9. Common pitfalls

| Symptom | Likely cause |
|---|---|
| `httpCode` 422/400 from WAHA | `chatId` not in `<digits>@c.us` format (stray `+`, spaces, or leading `0`) |
| `httpCode` 401/403 | Missing/incorrect `X-Api-Key`, or WAHA server requires auth you didn't send |
| `httpCode` 404 on session | Session name doesn't exist on the WAHA server — check `WAHA_SESSION` matches exactly |
| Message never arrives but response is `success: true` | WAHA session is logged out / QR expired — re-scan the session on the WAHA dashboard |
| Request hangs | WAHA server unreachable — verify `CURLOPT_TIMEOUT` is set (don't remove it) and check network/firewall |
