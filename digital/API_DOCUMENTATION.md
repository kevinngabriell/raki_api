# Digital Products API — Integration Guide

> **For:** Frontend (Flutter) Team  
> **Base URL:** `https://getmovira.com/raki-api`  
> **Auth:** All endpoints require `Authorization: Bearer <JWT_TOKEN>` (same token used across all Raki APIs)

---

## Standard Response Envelope

Every endpoint returns the same wrapper:

```json
{
  "status_code": 200,
  "status_message": "Human-readable message",
  "data": { ... }
}
```

Use `status_code` (not HTTP status code) for branching in your Flutter code.

---

## Endpoints

### 1. Get Product List

Fetch available digital products (pulsa, data, e-money, etc.) from the catalog.

```
GET /digital/get_products.php
```

**Headers**
```
Authorization: Bearer <token>
```

**Query Parameters**

| Parameter  | Type   | Required | Description |
|------------|--------|----------|-------------|
| `category` | string | No       | Filter by category (e.g. `PULSA`, `DATA`, `EMONEY`). Omit to get all. |

**Request Example**
```
GET /digital/get_products.php?category=PULSA
Authorization: Bearer eyJ...
```

**Success Response `200`**
```json
{
  "status_code": 200,
  "status_message": "Products retrieved",
  "data": {
    "status": "SUCCESS",
    "products": [
      {
        "productCode": "TSEL5",
        "productName": "Telkomsel 5.000",
        "category": "PULSA",
        "price": 6500,
        "sellingPrice": 7000
      }
    ]
  }
}
```

**Error Responses**

| HTTP | `status_code` | `status_message`               | Cause                         |
|------|---------------|--------------------------------|-------------------------------|
| 401  | 401           | `Authorization header not found` | Missing `Authorization` header |
| 401  | 401           | `Unauthorized`                 | JWT expired or invalid        |
| 405  | 405           | `Method Not Allowed`           | Called with non-GET method    |
| 500  | 500           | `Failed to fetch products`     | GrosirVoucher API unreachable |

---

### 2. Purchase a Digital Product

Submit a purchase order (pulsa topup, data package, e-money, etc.).

```
POST /digital/purchase.php
```

**Headers**
```
Authorization: Bearer <token>
Content-Type: application/json
```

**Request Body**

| Field          | Type   | Required | Description                                   |
|----------------|--------|----------|-----------------------------------------------|
| `product_code` | string | **Yes**  | Product code from catalog (e.g. `TSEL5`)      |
| `msisdn`       | string | **Yes**  | Destination phone number or wallet ID         |
| `company_id`   | string | **Yes**  | Company ID from the user's JWT token context  |

**Request Example**
```json
{
  "product_code": "TSEL5",
  "msisdn": "08123456789",
  "company_id": "COMP_001"
}
```

**Success Response `200`**
```json
{
  "status_code": 200,
  "status_message": "Purchase initiated",
  "data": {
    "client_trx_id": "MVR-A3F4B2C1D5E6F7A8-1719000000",
    "status": "PENDING",
    "gv_response": { }
  }
}
```

> ⚠️ **`PENDING` is normal.** The transaction is processed asynchronously.  
> Save `client_trx_id` — you'll need it to check status.

**Transaction Statuses**

| `status`  | Meaning |
|-----------|---------|
| `SUCCESS` | Completed immediately |
| `PENDING` | Processing — poll `/check_status` or wait |
| `FAILED`  | Rejected by the provider |
| `ERROR`   | Internal error (GV unreachable, etc.) |

**Error Responses**

| HTTP | `status_code` | `status_message`               | Cause                              |
|------|---------------|--------------------------------|------------------------------------|
| 400  | 400           | `product_code is required`     | Missing field                      |
| 400  | 400           | `msisdn is required`           | Missing field                      |
| 400  | 400           | `company_id is required`       | Missing field                      |
| 400  | 400           | `Invalid JSON body`            | Malformed request body             |
| 401  | 401           | `Authorization header not found` | Missing header                   |
| 401  | 401           | `Unauthorized`                 | Invalid JWT                        |
| 500  | 500           | `Purchase failed`              | GV call failed; `client_trx_id` is still returned so you can check status later |

---

### 3. Check Transaction Status

Get the current status of a transaction.

- If status is **`PENDING`** → re-queries GrosirVoucher live and updates DB.
- If status is **`SUCCESS` / `FAILED` / `ERROR`** → returns cached DB value immediately (no GV call).

```
GET /digital/check_status.php
```

**Headers**
```
Authorization: Bearer <token>
```

**Query Parameters**

| Parameter       | Type   | Required | Description                                           |
|-----------------|--------|----------|-------------------------------------------------------|
| `client_trx_id` | string | **Yes**  | The `client_trx_id` from the purchase response        |

**Request Example**
```
GET /digital/check_status.php?client_trx_id=MVR-A3F4B2C1D5E6F7A8-1719000000
Authorization: Bearer eyJ...
```

**Success Response `200`**
```json
{
  "status_code": 200,
  "status_message": "Transaction status retrieved",
  "data": {
    "id": "42",
    "client_transaction_id": "MVR-A3F4B2C1D5E6F7A8-1719000000",
    "server_transaction_id": "GV-9988776655",
    "product_code": "TSEL5",
    "msisdn": "08123456789",
    "status": "SUCCESS",
    "status_code": "00",
    "sn": "1234567890",
    "message": "Sukses",
    "created_at": "2026-06-22 10:00:00",
    "updated_at": "2026-06-22 10:00:45"
  }
}
```

**Error Responses**

| HTTP | `status_code` | `status_message`               | Cause                  |
|------|---------------|--------------------------------|------------------------|
| 400  | 400           | `client_trx_id is required`    | Missing query param    |
| 401  | 401           | `Authorization header not found` | Missing header       |
| 401  | 401           | `Unauthorized`                 | Invalid JWT            |
| 404  | 404           | `Transaction not found`        | Unknown `client_trx_id` |

---

### 4. Check GV Balance *(Admin Only)*

Returns the current credit balance on the GrosirVoucher reseller account.  
Only accessible to users with `role = admin` in their JWT.

```
GET /digital/check_balance.php
```

**Headers**
```
Authorization: Bearer <admin_token>
```

**Success Response `200`**
```json
{
  "status_code": 200,
  "status_message": "Balance retrieved",
  "data": {
    "status": "SUCCESS",
    "balance": 1500000
  }
}
```

**Error Responses**

| HTTP | `status_code` | `status_message`               | Cause                         |
|------|---------------|--------------------------------|-------------------------------|
| 401  | 401           | `Authorization header not found` | Missing header              |
| 401  | 401           | `Unauthorized`                 | Invalid JWT                   |
| 403  | 403           | `Forbidden: admin access required` | Caller does not have `admin` role |
| 500  | 500           | `Failed to fetch balance`      | GV unreachable                |

---

## Recommended Flutter Flow

```
1. GET /digital/get_products.php?category=PULSA
   └── Display product list to user

2. User selects product + enters MSISDN
   └── POST /digital/purchase.php
       └── Store client_trx_id locally

3. If response data.status == "PENDING":
   └── Poll GET /digital/check_status.php?client_trx_id=xxx
       every 5 seconds, up to ~60 seconds
       └── Stop when status is SUCCESS / FAILED / ERROR

4. Show final result to user
```

---

## Quick Reference

| Endpoint                    | Method | Auth    | Description            |
|-----------------------------|--------|---------|------------------------|
| `/digital/get_products.php` | GET    | Bearer  | List digital products  |
| `/digital/purchase.php`     | POST   | Bearer  | Buy a digital product  |
| `/digital/check_status.php` | GET    | Bearer  | Check transaction status |
| `/digital/check_balance.php`| GET    | Admin   | GV reseller balance    |
