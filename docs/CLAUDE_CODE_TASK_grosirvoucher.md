# Claude Code Task: GrosirVoucher Digital Product Integration
## Project: `raki_api` — PHP Backend

---

## 🎯 Objective

Integrate the **GrosirVoucher (JEMPOLKIOS)** third-party digital product API (pulsa, data, e-money topup, etc.) into `raki_api` as a **proxied backend feature**.

The mobile Flutter app **must NOT call GrosirVoucher directly** — all calls must go through `raki_api` because:
- GrosirVoucher whitelists only the VPS static IP (`43.134.179.107`)
- Credentials must never be exposed in the APK
- Transactions must be logged server-side in `RakiMaster.payment_transaction`

---

## 📁 Files to Create

```
digital/
  get_products.php        ← GET  /digital/get_products.php
  purchase.php            ← POST /digital/purchase.php
  check_status.php        ← GET  /digital/check_status.php?client_trx_id=xxx
  check_balance.php       ← GET  /digital/check_balance.php  (admin/debug only)
connection/
  grosirvoucher.php       ← GrosirVoucher HTTP client (cURL wrapper class)
dev/callback/
  payment.php             ← already exists — UPDATE this file to handle GV callbacks
```

---

## 🔑 GrosirVoucher Credentials

Add these to `.env`:

```
GV_BASE_URL=http://envdev.grosirvoucher.com:2011/api/h2h
GV_ID=OK0042
GV_PIN=EHFDI7
GV_USERNAME=934883
GV_PASSWORD=0F210D
GV_CALLBACK_URL=https://getmovira.com/raki-api/dev/callback/payment.php
```

Then expose them in `config.php` using the existing `loadEnv()` pattern:

```php
define('GV_BASE_URL',     $_ENV['GV_BASE_URL']     ?? '');
define('GV_ID',           $_ENV['GV_ID']           ?? '');
define('GV_PIN',          $_ENV['GV_PIN']           ?? '');
define('GV_USERNAME',     $_ENV['GV_USERNAME']      ?? '');
define('GV_PASSWORD',     $_ENV['GV_PASSWORD']      ?? '');
define('GV_CALLBACK_URL', $_ENV['GV_CALLBACK_URL']  ?? '');
```

---

## 🗄️ Database

Use the **existing** `payment_transaction` table in `RakiMaster`:

```sql
CREATE TABLE `payment_transaction` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_transaction_id` varchar(100) NOT NULL,
  `server_transaction_id` varchar(100) DEFAULT NULL,
  `product_code` varchar(50) DEFAULT NULL,
  `msisdn` varchar(50) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'PENDING',
  `status_code` varchar(10) DEFAULT NULL,
  `sn` varchar(100) DEFAULT NULL,
  `message` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_client_trx` (`client_transaction_id`),
  KEY `idx_status` (`status`),
  KEY `idx_server_trx` (`server_transaction_id`)
)
```

Also use the existing `payment_callback_log` table to log all raw inbound callbacks:

```sql
CREATE TABLE `payment_callback_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `endpoint` varchar(255) NOT NULL,
  `payload` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
)
```

---

## 📐 Code Style Rules

**Match the existing codebase exactly.** Key patterns observed in `general.php` and `config.php`:

1. **`jsonResponse($code, $message, $data)`** — always use this for all responses.
2. **`cleanInput($conn, $value)`** — always sanitize user input through this helper.
3. **`getCurrentDateTimeJakarta()`** — use for any datetime insert/comparison.
4. **`$isCli = php_sapi_name() === 'cli'`** — guard at top of every file that uses `$_SERVER`.
5. **Auth check** — look at how existing endpoints validate JWT/Bearer token from `Authorization` header. Match that exact pattern (do not invent a new auth mechanism).
6. **DB connection** — use the existing `connection/db.php` include. Do not hardcode credentials.
7. **Error reporting** — match `ini_set` block from `general.php`.
8. **`require_once`** paths — use `__DIR__` relative paths, matching existing files.

---

## 📄 File Specs

---

### `connection/grosirvoucher.php`

A plain PHP class `GrosirVoucher` that wraps cURL calls to the GV API.

```
class GrosirVoucher {
  private $baseUrl, $id, $pin, $username, $password;

  public function __construct()
    → reads from GV_* constants (config.php)

  private function request(array $params): array
    → builds POST body as JSON
    → uses cURL (not file_get_contents)
    → sets timeout: connect=5s, total=30s
    → returns decoded JSON array or throws RuntimeException on cURL failure

  public function checkBalance(): array
    → calls cmd=checkBalance

  public function getProducts(?string $category = null): array
    → calls cmd=pricelist
    → optionally filter by category

  public function purchase(string $productCode, string $msisdn, string $clientTrxId): array
    → calls cmd=topup
    → params: productCode, msisdn, clientTrxId, callbackUrl=GV_CALLBACK_URL

  public function checkStatus(string $clientTrxId): array
    → calls cmd=checkStatus
    → param: clientTrxId
}
```

All methods return the raw decoded GV response array. Let callers decide how to handle.

---

### `digital/get_products.php`

- **Method**: `GET`
- **Auth**: Required (Bearer token — match existing auth pattern)
- **Query params**: `category` (optional string filter)
- **Logic**:
  1. Include `general.php`, `config.php`, `connection/db.php`
  2. Validate auth token
  3. Instantiate `GrosirVoucher`, call `getProducts($category)`
  4. Return `jsonResponse(200, 'Products retrieved', $products)`
  5. On exception: `jsonResponse(500, 'Failed to fetch products', ['error' => $e->getMessage()])`
- **No DB write** — read-only proxy

---

### `digital/purchase.php`

- **Method**: `POST`
- **Auth**: Required (Bearer token)
- **Body (JSON)**:
  ```json
  {
    "product_code": "TSEL5",
    "msisdn": "08123456789",
    "company_id": "COMP_001"
  }
  ```
- **Logic**:
  1. Include `general.php`, `config.php`, `connection/db.php`
  2. Validate auth
  3. Parse JSON body with `json_decode(file_get_contents('php://input'), true)`
  4. Validate required fields: `product_code`, `msisdn`, `company_id`
  5. Sanitize inputs with `cleanInput()`
  6. Generate `client_transaction_id` = `'MVR-' . strtoupper(bin2hex(random_bytes(8))) . '-' . time()`
  7. **Insert** row to `payment_transaction` with `status = 'PENDING'` BEFORE calling GV
  8. Call `GrosirVoucher->purchase($productCode, $msisdn, $clientTrxId)`
  9. **Update** `payment_transaction` row with `server_transaction_id`, `status_code`, `message` from GV response
  10. If GV returns success status: set `status = 'SUCCESS'`; if failed: set `status = 'FAILED'`
  11. Return `jsonResponse(200, 'Purchase initiated', ['client_trx_id' => $clientTrxId, 'status' => $status])`
  12. Wrap steps 7–11 in try/catch; on exception update status to `'ERROR'` and return 500

---

### `digital/check_status.php`

- **Method**: `GET`
- **Auth**: Required
- **Query param**: `client_trx_id` (required)
- **Logic**:
  1. Validate auth
  2. Query `payment_transaction` WHERE `client_transaction_id = ?`
  3. If not found: `jsonResponse(404, 'Transaction not found')`
  4. If found and `status` is still `PENDING`: also call `GrosirVoucher->checkStatus()`, update DB row, return updated status
  5. If found and `status` is terminal (`SUCCESS`/`FAILED`/`ERROR`): return DB row directly without re-calling GV
  6. Return full row data

---

### `digital/check_balance.php`

- **Method**: `GET`
- **Auth**: Required (ideally restrict to admin role — check role from token, same pattern as existing admin endpoints)
- **Logic**:
  1. Validate auth + role
  2. Call `GrosirVoucher->checkBalance()`
  3. Return raw response

---

### `dev/callback/payment.php` — UPDATE existing file

GrosirVoucher will POST a callback to this URL when a transaction completes.

- **Method**: `POST`
- **No auth header** (GV callbacks are unauthenticated — verify by matching `client_trx_id` in DB instead)
- **Logic**:
  1. `$isCli` guard at top
  2. Log ALL inbound requests to `payment_callback_log` table (raw payload + IP + user_agent) — do this first, before any validation
  3. Parse JSON body
  4. Extract `client_trx_id`, `status`, `server_trx_id`, `sn`, `status_code`, `message` from payload
  5. Find matching row in `payment_transaction` WHERE `client_transaction_id = $clientTrxId`
  6. If not found: log warning, return `http_response_code(200)` anyway (GV retries on non-200)
  7. If found: update `status`, `server_transaction_id`, `sn`, `status_code`, `message`, `updated_at`
  8. Map GV status codes to internal statuses:
     - GV `00` or `SUCCESS` → `SUCCESS`
     - GV `PENDING` or `PROCESS` → `PENDING`
     - anything else → `FAILED`
  9. Always return HTTP 200 with `{"rc": "00"}` so GV stops retrying

> **Important**: `payment.php` likely already handles other payment callbacks (e.g. Midtrans). Add GV handling as an additional branch — do NOT break existing logic. Use `source` field or endpoint path to differentiate if needed.

---

## ✅ Checklist for Claude Code

- [ ] Read `general.php` fully before writing any file — match `jsonResponse`, `cleanInput`, `getCurrentDateTimeJakarta`, `$isCli` guard
- [ ] Read `config.php` — match `define()` constant pattern, add GV constants there
- [ ] Read `connection/db.php` — use the same `$conn` connection pattern
- [ ] Read at least one existing endpoint (e.g. `voucher/` or `transaction/`) — match include structure, auth check, method guard, and response format exactly
- [ ] Never call `$_SERVER['REQUEST_METHOD']` without the `$isCli` guard above it
- [ ] Never hardcode GV credentials — always read from constants defined in `config.php`
- [ ] The `/digital/` URL prefix must be used for ALL GrosirVoucher endpoints — `/voucher/` is reserved for RAKI's discount voucher system
- [ ] Use `bin2hex(random_bytes(16))` for ID generation (existing pattern from `handle_menu_image_upload`)
- [ ] All DB queries must use `mysqli_real_escape_string` or `cleanInput` — no raw user input in queries
- [ ] `purchase.php` MUST insert to DB before calling GV — never lose a transaction record even if GV call fails
- [ ] `callback/payment.php` MUST log to `payment_callback_log` before doing anything else
- [ ] Test that `check_status.php` does NOT re-call GV when status is already terminal

---

## 🗂️ Summary of Files

| File | Action | Method |
|------|--------|--------|
| `connection/grosirvoucher.php` | CREATE | — |
| `digital/get_products.php` | CREATE | GET |
| `digital/purchase.php` | CREATE | POST |
| `digital/check_status.php` | CREATE | GET |
| `digital/check_balance.php` | CREATE | GET |
| `dev/callback/payment.php` | UPDATE | POST |
| `.env` | UPDATE | — |
| `config.php` | UPDATE | — |
