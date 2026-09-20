# RAKI API Documentation

**Version:** 1.2.0  
**Base URL:** `https://your-domain.com`  
**Timezone:** All timestamps are in `Asia/Jakarta` (WIB, UTC+7)

---

## Table of Contents

1. [Authentication](#authentication)
2. [Account](#account)
3. [Menu](#menu)
4. [Session](#session)
5. [POS Transaction](#pos-transaction)
6. [Transaction](#transaction)
7. [Dashboard](#dashboard)
8. [Reward](#reward)
9. [Voucher](#voucher)
10. [Promo](#promo)
11. [Member](#member)
12. [Bonus Schema](#bonus-schema)
13. [Driver (Abang)](#driver-abang)
14. [Digital Products](#digital-products)
15. [Supply](#supply)
16. [Settings](#settings)
17. [Notification](#notification)
18. [Public Endpoints](#public-endpoints)
19. [Error Responses](#error-responses)

---

## Authentication

All protected endpoints require a Bearer JWT token in the `Authorization` header.

```
Authorization: Bearer <token>
```

**JWT Claims:**

| Claim | Type | Description |
|-------|------|-------------|
| `iat` | int | Issued at (Unix timestamp) |
| `exp` | int | Expiration (Unix timestamp) |
| `username` | string | User's username |
| `company_id` | string | Associated company ID |
| `role` | string | User role (`app_role_id`) |

**Token Expiry:**
- Login token: 8 hours
- OTP token: 30 days

---

## Account

### POST /account/login.php

Authenticate a user with username and password.

**Auth:** None

**Request Body:**

```json
{
  "username": "driver001",
  "password": "secret123"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `username` | string | Yes | User's username |
| `password` | string | Yes | User's password |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Login Success",
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "expires_in": 1750815600,
  "data": {
    "iat": 1750786800,
    "exp": 1750815600,
    "username": "driver001",
    "company_id": "company6abc123",
    "role": "driver"
  }
}
```

---

### POST /account/register.php

Register a new user account.

**Auth:** None

**Request Body:**

```json
{
  "username": "driver002",
  "password": "secret123",
  "app_id": "06660e87-37e7-491b-92c3-c772130eb57c",
  "app_role_id": "driver"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `username` | string | Yes | New username |
| `password` | string | Yes | Password |
| `app_id` | string | Yes | Application ID |
| `app_role_id` | string | Yes | Role to assign |

**Response 201:**

```json
{
  "status_code": 201,
  "status_message": "New user has been created successfully",
  "data": {
    "username": "driver002"
  }
}
```

---

### POST /account/otp.php — Request OTP

Send a one-time password to a phone number via WhatsApp.

**Auth:** None

**Rate limits:** 1 request per 3 minutes per phone number; account locked after 10 requests in 24 hours.

**Request Body:**

```json
{
  "phone_number": "628123456789"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `phone_number` | string | Yes | Phone number with country code |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "OTP sent",
  "data": {
    "phone_number": "628123456789",
    "expire_at": "2026-06-24 12:30:00",
    "wa_status": "sent"
  }
}
```

---

### PUT /account/otp.php — Verify OTP

Verify an OTP code and receive a JWT token.

**Auth:** None

**Request Body:**

```json
{
  "phone_number": "628123456789",
  "otp": "123456"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `phone_number` | string | Yes | Phone number used to request OTP |
| `otp` | string | Yes | 6-digit OTP code |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "OTP verified",
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "expires_in": 1753378800,
  "data": {
    "username": "628123456789",
    "company_id": "company6abc123",
    "role": "driver"
  }
}
```

---

### POST /account/forgot_password.php — Request Reset OTP

Send a one-time password via WhatsApp to the phone number linked to a username, to start a password reset.

**Auth:** None

**Rate limits:** 1 request per 3 minutes per username; account locked after 10 requests in 24 hours. Tracked separately from the login OTP rate limit.

**Request Body:**

```json
{
  "username": "driver001"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `username` | string | Yes | Username of the account to reset |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "If the account exists, an OTP has been sent"
}
```

Note: this response is returned whether or not the username exists, to avoid leaking which usernames are registered.

---

### PUT /account/forgot_password.php — Verify Reset OTP

Verify the OTP sent for a password reset and receive a short-lived reset token.

**Auth:** None

**Request Body:**

```json
{
  "username": "driver001",
  "otp": "123456"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `username` | string | Yes | Username used to request the OTP |
| `otp` | string | Yes | 6-digit OTP code |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "OTP verified",
  "data": {
    "reset_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "expires_in": 1753378800
  }
}
```

`reset_token` is a short-lived JWT (10 minutes) that must be passed to `PATCH /account/forgot_password.php` to actually set the new password. The OTP itself is single-use and is marked consumed as soon as it's verified.

---

### PATCH /account/forgot_password.php — Reset Password

Set a new password using the reset token obtained from the OTP verification step.

**Auth:** None (requires a valid `reset_token`)

**Request Body:**

```json
{
  "reset_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "new_password": "newSecret123"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `reset_token` | string | Yes | Token returned by the verify-OTP step |
| `new_password` | string | Yes | New password, minimum 6 characters |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Password has been reset, please log in with your new password"
}
```

Note: no JWT is issued here — the user must log in again with the new password via `POST /account/login.php`.

---

### GET /account/profile.php

Retrieve the authenticated user's profile.

**Auth:** Bearer Token

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "User profile",
  "data": {
    "user_id": "usr_abc123",
    "username": "driver001",
    "account_status": "active",
    "app_id": "06660e87-37e7-491b-92c3-c772130eb57c",
    "app_role_id": "driver",
    "company_id": "company6abc123",
    "phone_number": "628123456789",
    "first_name": "Budi",
    "language": "id",
    "email": "budi@example.com",
    "created_at": "2025-01-01 08:00:00",
    "updated_at": "2026-06-01 10:00:00"
  }
}
```

---

### GET /account/account.php

Get the authenticated driver's current bonus progress for the week.

**Auth:** Bearer Token

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Driver next bonus target",
  "data": {
    "current_total_item": 50,
    "current_bonus": {
      "schema_id": "schema001",
      "schema_name": "Tier 1",
      "achieved_qty": 50,
      "bonus_nominal": 100000
    },
    "next_target": {
      "schema_id": "schema002",
      "schema_name": "Tier 2",
      "target_qty": 100,
      "bonus_nominal": 200000,
      "remaining_item": 50,
      "progress_percentage": 50
    },
    "period": {
      "start": "2026-06-22",
      "end": "2026-06-28"
    }
  }
}
```

---

### GET /account/company.php

Get company information.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `company_id` | string | Yes | Company ID to look up |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Company found",
  "data": {
    "data": [
      {
        "company_id": "company6abc123",
        "company_name": "Raki Coffee Sudirman",
        "pic_contact": "628111222333"
      }
    ],
    "pagination": {
      "total": 1,
      "page": 1,
      "limit": 1,
      "total_pages": 1
    }
  }
}
```

---

## Menu

### GET /menu/menu.php

Get all menus or a single menu by ID.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `menu_id` | string | No | — | Return a single menu by ID |
| `params` | string | No | — | Search keyword |
| `page` | int | No | 1 | Page number |
| `limit` | int | No | 50 | Items per page |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Menu found",
  "data": {
    "data": [
      {
        "menu_id": "menu6abc123",
        "menu_name": "Kopi Susu",
        "category_id": "cat001",
        "category_name": "Coffee",
        "price": 15000,
        "image_url": "https://your-domain.com/uploads/kopi-susu.jpg",
        "thumb_url": "https://your-domain.com/uploads/thumbs/kopi-susu.jpg",
        "is_active": 1,
        "created_at": "2025-01-01 08:00:00"
      }
    ],
    "pagination": {
      "total": 10,
      "page": 1,
      "limit": 50,
      "total_pages": 1
    }
  }
}
```

---

### POST /menu/menu.php

Create a new menu item. Supports `multipart/form-data` for image upload.

**Auth:** Bearer Token

**Request Body (`multipart/form-data` or `application/json`):**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `menu_name` | string | Yes | Menu item name |
| `category_id` | string | Yes | Category ID |
| `price` | int | No | Price in IDR |
| `image` | file | No | Menu image (JPEG/PNG) |

**Response 201:**

```json
{
  "status_code": 201,
  "status_message": "New menu has been created successfully",
  "data": {
    "menu": "Kopi Susu"
  }
}
```

---

### PUT /menu/menu.php

Update an existing menu item.

**Auth:** Bearer Token

**Request Body (`application/json` or `multipart/form-data`):**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `menu_id` | string | Yes | Menu ID to update |
| `menu_name` | string | No | New name |
| `category_id` | string | No | New category ID |
| `price` | int | No | New price |
| `image` | file | No | New image |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Menu updated successfully"
}
```

---

### DELETE /menu/menu.php

**Auth:** Bearer Token | **Query Param:** `?menu_id=menu6abc123` | **Response 200**

---

### GET /menu/category.php

Get all menu categories or a single category.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `category_id` | string | No | — | Return single category |
| `params` | string | No | — | Search keyword |
| `page` | int | No | 1 | Page number |
| `limit` | int | No | 10 | Items per page |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Category found",
  "data": {
    "data": [
      { "category_id": "cat001", "category_name": "Coffee" }
    ],
    "pagination": { "total": 3, "page": 1, "limit": 10, "total_pages": 1 }
  }
}
```

**POST** — `{ "category_name": "Coffee" }` → 201  
**PUT** — `{ "category_id": "cat001", "category_name": "Hot Coffee" }` → 200  
**DELETE** — `?category_id=cat001` → 200

---

### GET /menu/package.php

Get all menu packages or a single package.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `package_id` | string | No | — | Return single package |
| `params` | string | No | — | Search keyword |
| `page` | int | No | 1 | Page number |
| `limit` | int | No | 20 | Items per page |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Package found",
  "data": {
    "data": [
      {
        "package_id": "pkg001",
        "package_name": "Bundle Hemat",
        "package_price": 25000,
        "menus": [
          { "menu_id": "menu001", "menu_name": "Kopi Susu" },
          { "menu_id": "menu002", "menu_name": "Snack" }
        ]
      }
    ],
    "pagination": { "total": 2, "page": 1, "limit": 20, "total_pages": 1 }
  }
}
```

**POST Request Body:**

```json
{
  "package_name": "Bundle Hemat",
  "package_price": 25000,
  "menu_ids": ["menu001", "menu002"]
}
```

**PUT** — `{ "package_id": "pkg001", "package_name": "...", "package_price": 0, "menu_ids": [] }` → 200  
**DELETE** — `?package_id=pkg001` → 200

---

### GET /menu/ingredient.php

Get all ingredients or a single ingredient.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `ingredient_id` | string | No | — | Return single ingredient |
| `params` | string | No | — | Search keyword |
| `page` | int | No | 1 | Page |
| `limit` | int | No | 10 | Items per page |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Ingredient found",
  "data": {
    "data": [
      {
        "ingredient_id": "ingredients6abc",
        "ingredient_name": "Susu Full Cream",
        "uom_name": "Liter",
        "category_name": "Dairy",
        "price": 18000
      }
    ],
    "pagination": { "total": 5, "page": 1, "limit": 10, "total_pages": 1 }
  }
}
```

**POST Request Body:**

```json
{
  "ingredient_name": "Susu Full Cream",
  "ingredient_category": "category6abc",
  "uom": "uom6abc",
  "sku": "SKU-001",
  "company_id": "company6abc123",
  "is_active": true,
  "price": 18000
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `ingredient_name` | string | Yes | Ingredient name |
| `ingredient_category` | string | Yes | Ingredient category ID |
| `uom` | string | Yes | Unit of Measurement ID |
| `sku` | string | No | Stock Keeping Unit code |
| `company_id` | string | Yes | Company ID |
| `is_active` | bool | No | Active status |
| `price` | int | Yes | Price per UOM |

**PUT** — `{ "ingredient_id": "...", ...fields }` → 200  
**DELETE** — `?ingredient_id=...` → 200

---

### /menu/ingredient_category.php

Full CRUD for ingredient categories.

**POST** — `{ "category_name": "Dairy" }` → 201  
**PUT** — `{ "category_id": "...", "category_name": "..." }` → 200  
**DELETE** — `?category_id=...` → 200  
**GET** — `?category_id=...` or `?params=...&page=1&limit=10` → 200

---

## Session

### POST /session/start.php

Start a new work session for a driver.

**Auth:** Bearer Token

**Request Body:**

```json
{
  "company_id": "company6abc123",
  "cash_start": 100000,
  "stock": [
    { "menu_id": "menu001", "qty_start": 50 },
    { "menu_id": "menu002", "qty_start": 30 }
  ]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `company_id` | string | Yes | Company ID |
| `cash_start` | int | Yes | Starting cash amount (IDR) |
| `stock` | array | Yes | Array of `{ menu_id, qty_start }` |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Session started",
  "data": {
    "session_id": "ses_6abc123",
    "company_id": "company6abc123",
    "user_id": "driver001",
    "started_at": "2026-06-24 08:00:00",
    "ended_at": null,
    "cash_start": 100000,
    "cash_end": null,
    "status": "active",
    "stock": [
      { "menu_id": "menu001", "menu_name": "Kopi Susu", "qty_start": 50, "qty_end": null }
    ]
  }
}
```

---

### POST /session/end.php

Close the current active session.

**Auth:** Bearer Token

**Request Body:**

```json
{
  "cash_end": 350000
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `cash_end` | int | Yes | Closing cash amount (IDR) |

`total_cash` includes both `cash` and `edc_flazz` payments (EDC/Flazz settles like cash for drawer reconciliation — see `EDC_FLAZZ_PAYMENT_METHOD.md`). `total_qris` is `qris` only. `total_sales = total_cash + total_qris`.

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Session closed successfully",
  "data": {
    "session_id": "ses_6abc123",
    "started_at": "2026-06-24 08:00:00",
    "ended_at": "2026-06-24 18:30:00",
    "duration_minutes": 630,
    "total_cup_start": 80,
    "total_cup_sold": 65,
    "total_transaction": 42,
    "total_cash": 500000,
    "total_qris": 300000,
    "total_sales": 800000,
    "cash_start": 100000,
    "cash_end": 350000
  }
}
```

---

### GET /session/active.php

Get the active session for a specific driver.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `company_id` | string | Yes | Company ID |
| `username` | string | Yes | Driver username |

**Response 200 (session found):**

```json
{
  "status_code": 200,
  "status_message": "Active session found",
  "data": {
    "session_id": "ses_6abc123",
    "company_id": "company6abc123",
    "user_id": "driver001",
    "started_at": "2026-06-24 08:00:00",
    "status": "active",
    "payment_summary": {
      "cash_amount": 500000,
      "qris_amount": 300000,
      "transfer_amount": 0,
      "qris_midtrans_amount": 0,
      "total_transactions": 10,
      "grand_total_amount": 800000
    },
    "stock": [
      {
        "menu_id": "menu001",
        "menu_name": "Kopi Susu",
        "image_url": "https://your-domain.com/uploads/kopi-susu.jpg",
        "price": 15000,
        "qty_start": 50,
        "qty_end": null,
        "qty_sold": 25,
        "qty_left": 25
      }
    ]
  }
}
```

`payment_summary.cash_amount` sums both `cash` and `edc_flazz` payments (see `EDC_FLAZZ_PAYMENT_METHOD.md`).

**Response 200 (no active session):**

```json
{
  "status_code": 200,
  "status_message": "No active session",
  "data": null
}
```

---

### GET /session/active-drivers.php

Get all active drivers and their stock for the authenticated user's company.

**Auth:** Bearer Token

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Active drivers found",
  "data": [
    {
      "session_id": "ses_6abc123",
      "company_id": "company6abc123",
      "user_id": "driver001",
      "username": "driver001",
      "phone_number": "628123456789",
      "started_at": "2026-06-24 08:00:00",
      "ended_at": null,
      "cash_start": 100000,
      "cash_end": null,
      "status": "active",
      "menus": [
        {
          "menu_id": "menu001",
          "menu_name": "Kopi Susu",
          "category_name": "Coffee",
          "image_url": "...",
          "qty_start": 50,
          "qty_sold": 25,
          "qty_left": 25,
          "price": 15000
        }
      ],
      "payments": [
        { "payment_method": "cash", "total_amount": 375000 }
      ]
    }
  ]
}
```

---

### GET /session/driver-stats.php

Get weekly performance statistics for a driver.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `company_id` | string | No | Defaults to token's `company_id` |
| `username` | string | No | Defaults to token's `username` |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Driver stats found",
  "data": {
    "cup_sold": 85,
    "cup_target": 150,
    "cup_sold_today": 12,
    "revenue_today": 180000,
    "week_range": "22 – 28 Jun 2026",
    "period": { "start": "2026-06-22", "end": "2026-06-28" },
    "weekly_data": [
      { "date": "2026-06-22", "day": "Mon", "cups": 15 },
      { "date": "2026-06-23", "day": "Tue", "cups": 20 }
    ],
    "tier_info": {
      "current_tier": {
        "schema_id": "schema001",
        "schema_name": "Tier 1",
        "target_qty": 50,
        "bonus_nominal": 100000
      },
      "next_tier": {
        "schema_id": "schema002",
        "schema_name": "Tier 2",
        "target_qty": 100,
        "bonus_nominal": 200000,
        "cups_to_next": 15,
        "progress_percentage": 85
      }
    },
    "top_product": {
      "menu_name": "Kopi Susu",
      "total_qty": 55,
      "total_revenue": 825000
    }
  }
}
```

---

### GET /session/nearby-abang.php

Get active drivers near a geographic coordinate. **No authentication required.**

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `lat` | float | Yes | — | Latitude |
| `lng` | float | Yes | — | Longitude |
| `radius` | float | No | 3.0 | Search radius in km (max 50) |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Nearby abang found",
  "data": [
    {
      "id": "ses_6abc123",
      "name": "Abang 6281****789",
      "lat": -6.2088,
      "lng": 106.8456,
      "distance_km": 1.24,
      "stocks": [
        {
          "menu_id": "menu001",
          "menu_name": "Kopi Susu",
          "thumb_url": "...",
          "price": 15000,
          "qty_left": 25
        }
      ]
    }
  ]
}
```

---

## POS Transaction

### POST /pos/transaction.php

Create a POS transaction linked to the active session.

**Auth:** Bearer Token

**Request Body:**

```json
{
  "items": [
    { "menu_id": "menu001", "quantity": 2, "unit_price": 15000 }
  ],
  "payments": [
    { "payment_method": "cash", "amount": 30000 }
  ],
  "latitude": -6.2088,
  "longitude": 106.8456
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `items` | array | Yes | Array of `{ menu_id, quantity, unit_price }` |
| `payments` | array | Yes | Array of `{ payment_method, amount }`. Methods: `cash`, `qris` |
| `latitude` | float | No | GPS latitude |
| `longitude` | float | No | GPS longitude |

**Response 201:**

```json
{
  "status_code": 201,
  "status_message": "POS transaction created",
  "data": {
    "transaction_id": "trx6abc123",
    "session_id": "ses_6abc123",
    "company_id": "company6abc123",
    "transaction_date": "2026-06-24 10:30:00",
    "total_amount": 30000,
    "latitude": -6.2088,
    "longitude": 106.8456,
    "total_item": 2,
    "items": [
      {
        "detail_id": "trd6abc",
        "menu_id": "menu001",
        "quantity": 2,
        "unit_price": 15000,
        "subtotal": 30000
      }
    ],
    "payments": [
      { "payment_method": "cash", "amount": 30000 }
    ]
  }
}
```

---

### GET /pos/qris-static.php

Retrieve the static QRIS code for a company.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `company_id` | string | Yes | Company ID |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Static QRIS found",
  "data": [
    {
      "payment_id": "pay6abc",
      "company_id": "company6abc123",
      "qris_string": "00020101...",
      "qris_image_url": "https://your-domain.com/qris/company6abc123.png"
    }
  ]
}
```

---

## Transaction

### POST /transaction/index.php

Create a sale transaction. Supports single menus or packages. The sum of all `payments[].amount` **must equal** the computed total of all items.

**Auth:** Bearer Token

**Request Body:**

```json
{
  "company_id": "company6abc123",
  "items": [
    { "menu_id": "menu001", "quantity": 2, "unit_price": 15000 },
    { "package_id": "pkg001", "quantity": 1, "unit_price": 25000 }
  ],
  "payments": [
    { "payment_method": "cash", "amount": 40000 },
    { "payment_method": "qris", "amount": 15000 }
  ],
  "transaction_date": "2026-06-24 10:30:00"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `company_id` | string | Yes | Company ID |
| `items` | array | Yes | Each item needs either `menu_id` or `package_id`, plus `quantity` and `unit_price` |
| `payments` | array | Yes | Methods: `cash`, `qris`, `edc_flazz`. Sum must equal total |
| `transaction_date` | datetime | No | Defaults to current Jakarta datetime. Only the date part is stored |
| `apply_promo` | bool | No | Opt in to the server-side [promo](#promo) discount. See below |

**Response 201:**

```json
{
  "status_code": 201,
  "status_message": "Transaction created",
  "data": {
    "transaction_id": "trx6abc123",
    "company_id": "company6abc123",
    "transaction_date": "2026-06-24 10:30:00",
    "total_amount": 55000,
    "items": [
      { "detail_id": "trd001", "menu_id": "menu001", "quantity": 2, "subtotal": 30000 },
      { "detail_id": "trd002", "menu_id": "menu003", "quantity": 1, "subtotal": 25000 }
    ]
  }
}
```

#### Promo discount (`apply_promo`)

Without `apply_promo` the endpoint behaves exactly as described above. With `"apply_promo": true` the server re-evaluates the company's active promos (the same evaluation as [`POST /promo/check.php`](#post-promocheckphp)) and never trusts a client-side discount:

- `payments[].amount` must add up to the **discounted** total, not the gross one. If they don't, the response is `400` and `data` carries `gross_amount`, `discount_amount`, `total_amount` and `promo` so the POS can refresh.
- A discounted sale is stored **net**: `transaction.total_amount` and each `transaction_detail.subtotal` are after discount (so dashboards and cash reconciliation stay consistent). The total discount is in `transaction.discount_amount`, each line's share in `transaction_detail.discount_amount` (gross line value = `subtotal + discount_amount`), and `transaction_promo` records which promo applied.
- If no promo applies, the sale is saved at full price, and the response still includes `promo: null`.
- `apply_promo` requires `promo/promo_migration.sql` to have been applied; requests without it never touch the new objects.

Extra fields in the `201` response when `apply_promo` was sent:

```json
{
  "data": {
    "total_amount": 28000,
    "gross_amount": 31000,
    "discount_amount": 3000,
    "promo": { "promo_id": "promo6abc", "promo_name": "Monday Bestie Day", "promo_type": "buy_n_nominal_off", "applications": 1, "cap_reached": false, "discount_amount": 3000, "next_reward": { "items_needed": 2, "discount_amount": 3000 } },
    "items": [
      { "detail_id": "trd001", "menu_id": "menu001", "quantity": 1, "subtotal": 14451, "discount_amount": 1549 }
    ]
  }
}
```

---

### GET /transaction/index.php

Get all transactions (paginated) or a single transaction by ID.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `trx_id` | string | No | — | Return single transaction detail |
| `company_id` | string | No | — | Filter by company |
| `username` | string | No | — | Filter by creator |
| `page` | int | No | 1 | Page number |
| `limit` | int | No | 10 | Items per page |

List results are always sorted by `transaction_date DESC, created_at DESC` (newest first) — safe to rely on this for "recent activity" widgets without extra client-side sorting.

**Response 200 (list):**

```json
{
  "status_code": 200,
  "status_message": "Success",
  "data": {
    "transactions": [
      {
        "transaction_id": "trx6abc123",
        "company_id": "company6abc123",
        "company_name": "Raki Coffee Sudirman",
        "transaction_date": "2026-06-24",
        "total_amount": 55000,
        "total_item": 3,
        "created_by": "driver001",
        "created_at": "2026-06-24 10:30:00"
      }
    ],
    "pagination": {
      "total": 100,
      "page": 1,
      "limit": 10,
      "total_pages": 10
    }
  }
}
```

**Response 200 (single, with `trx_id`):**

```json
{
  "status_code": 200,
  "status_message": "Transaction detail fetched",
  "data": {
    "transaction": {
      "transaction_id": "trx6abc123",
      "company_id": "company6abc123",
      "transaction_date": "2026-06-24 10:30:00",
      "total_item": 3,
      "total_amount": 55000,
      "created_by": "driver001",
      "created_at": "2026-06-24 10:30:00",
      "updated_at": "2026-06-24 10:30:00",
      "updated_by": "driver001"
    },
    "items": [
      { "detail_id": "trd001", "menu_id": "menu001", "menu_name": "Kopi Susu", "quantity": 2, "subtotal": 30000 }
    ],
    "payments": [
      { "payment_method": "cash", "amount": 55000 }
    ]
  }
}
```

---

### DELETE /transaction/index.php

Delete a transaction and all its line items.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `transaction_id` | string | Yes | Transaction ID to delete |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Transaction deleted successfully"
}
```

---

## Dashboard

### GET /dashboard/index.php

Get the monthly + today dashboard overview for a company, a payment method breakdown for a custom date range, or a bulk snapshot of every outlet a multi-outlet owner can see.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `company_id` | string | Yes (default dashboard only) | Company ID |
| `action` | string | No | `payment_method_summary` for payment breakdown, or `all_outlets_summary` for the multi-outlet grid |
| `start_date` | date | Conditional | Required when `action=payment_method_summary` (`YYYY-MM-DD`) |
| `end_date` | date | Conditional | Required when `action=payment_method_summary` (`YYYY-MM-DD`) |

**Response 200 (default dashboard — current month + today):**

```json
{
  "status_code": 200,
  "status_message": "Dashboard fetched",
  "data": {
    "period": { "start": "2026-06-01", "end_exclusive": "2026-07-01" },
    "revenue_this_month": 8500000,
    "cups_this_month": 420,
    "avg_daily_revenue": 283333.33,
    "revenue_today": 320000,
    "cups_today": 18,
    "transactions_today": 9,
    "active_drivers_today": 3,
    "top_menus": [
      { "menu_id": "menu001", "menu_name": "Kopi Susu", "total_cups": 180, "total_revenue": 2700000 }
    ],
    "menu_performance": [
      { "menu_id": "menu001", "menu_name": "Kopi Susu", "total_cups": 180, "total_revenue": 2700000 }
    ]
  }
}
```

`revenue_today` / `cups_today` / `transactions_today` are scoped to the current calendar day (`Asia/Jakarta`), separate from the monthly rollup. `active_drivers_today` counts distinct drivers with a `work_session` whose `status = 'active'` right now.

**Response 200 (`action=payment_method_summary`):**

```json
{
  "status_code": 200,
  "status_message": "Payment method summary fetched",
  "data": {
    "period": { "start_date": "2026-06-01", "end_date": "2026-06-24" },
    "summary": [
      { "payment_method": "cash", "total_amount": 5200000 },
      { "payment_method": "qris", "total_amount": 3300000 }
    ]
  }
}
```

**Response 200 (`action=all_outlets_summary`):**

```json
{
  "status_code": 200,
  "status_message": "All outlets summary fetched",
  "data": [
    {
      "company_id": "company6902bb9927d0a",
      "company_name": "Raki Tangerang",
      "revenue_this_month": 8500000,
      "cups_this_month": 420,
      "driver_count": 3,
      "active_drivers_today": 1
    },
    {
      "company_id": "company69610d4cbf6a1",
      "company_name": "Raki Tangerang 2",
      "revenue_this_month": 4100000,
      "cups_this_month": 210,
      "driver_count": 12,
      "active_drivers_today": 2
    }
  ]
}
```

`company_id`/`start_date`/`end_date` are not used for this action. The requesting owner's outlets are resolved server-side: multi-outlet owners exist as one `app_user` row per outlet sharing the same `email`, so the endpoint finds every `company_id` tied to accounts with that email and the same role as the caller. If the caller's email is blank or has no siblings, the array falls back to a single entry for the caller's own `company_id` (from the JWT).

---

### GET /dashboard/statistic.php

Get advanced analytics for a date range.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `company_id` | string | No | all companies | Filter by company |
| `start_date` | date | No | 30 days ago | Start of range (`YYYY-MM-DD`) |
| `end_date` | date | No | today | End of range (`YYYY-MM-DD`) |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Statistic fetched",
  "data": {
    "filter": {
      "company_id": "company6abc123",
      "start_date": "2026-05-25",
      "end_date": "2026-06-24",
      "mode": "single_company"
    },
    "summary": {
      "avg_order_value": 31250.5,
      "total_trx": 320,
      "total_revenue": 10000000
    },
    "payment_method": [
      { "payment_method": "cash", "total_trx": 6000000, "percentage": 60.0 },
      { "payment_method": "qris", "total_trx": 4000000, "percentage": 40.0 }
    ],
    "menu_revenue": [
      { "menu_name": "Kopi Susu", "total_trx": 2700000, "total_qty": 180 }
    ],
    "revenue_by_creator": [
      { "created_by": "driver001", "total_trx": 5000000 }
    ],
    "revenue_by_date": [
      { "trx_date": "2026-06-24", "total_trx": 450000 }
    ],
    "trx_count_by_date": [
      { "trx_date": "2026-06-24", "trx_count": 18 }
    ],
    "cashier_performance": [
      { "created_by": "driver001", "trx_count": 180, "total_trx": 5000000 }
    ],
    "ingredient_purchase": [
      { "ingredient_name": "Susu Full Cream", "company_name": "Raki Coffee Sudirman", "total_trx": 500000 }
    ],
    "purchase_by_date": [
      { "order_date": "2026-06-20", "total_purchase": 800000 }
    ]
  }
}
```

---

## Reward

### GET /reward/catalog.php

Get the reward catalog. **No authentication required.**

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `company_id` | string | Yes | — | Company ID |
| `reward_id` | string | No | — | Return single reward |
| `search` | string | No | — | Search keyword |
| `page` | int | No | 1 | Page number |
| `limit` | int | No | 10 | Items per page |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Reward catalog",
  "data": {
    "data": [
      {
        "reward_id": "reward6abc",
        "reward_name": "Free Coffee",
        "point_cost": 500,
        "description": "Redeem for 1 free Kopi Susu",
        "image_url": "https://your-domain.com/uploads/free-coffee.jpg",
        "stock": 10,
        "is_active": 1,
        "valid_from": "2026-01-01",
        "valid_until": "2026-12-31"
      }
    ],
    "pagination": { "current_page": 1, "limit": 10, "total_data": 5, "total_page": 1 }
  }
}
```

---

### POST /reward/catalog.php

**Auth:** Bearer Token

**Request Body:**

```json
{
  "reward_name": "Free Coffee",
  "point_cost": 500,
  "description": "Redeem for 1 free Kopi Susu",
  "image_url": "https://...",
  "stock": 10,
  "is_active": 1,
  "valid_from": "2026-01-01",
  "valid_until": "2026-12-31"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `reward_name` | string | Yes | Reward name |
| `point_cost` | int | Yes | Points required (> 0) |
| `description` | string | No | Description |
| `image_url` | string | No | Image URL |
| `stock` | int | No | Available stock |
| `is_active` | int | No | `0` or `1` (default `1`) |
| `valid_from` | date | No | Validity start (`YYYY-MM-DD`) |
| `valid_until` | date | No | Validity end (`YYYY-MM-DD`) |

**Response 201**

**PUT** — `{ "reward_id": "...", ...fields }` → 200  
**DELETE** — `?reward_id=...` → 200

---

### GET /reward/redeem.php

Get the authenticated member's redemption history.

**Auth:** Bearer Token

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Redemption history",
  "data": [
    {
      "redemption_id": "rdm6abc",
      "reward_id": "reward6abc",
      "reward_name": "Free Coffee",
      "point_cost": 500,
      "image_url": "...",
      "points_used": 500,
      "status": "pending",
      "redemption_code": "ABC12345",
      "claimed_at": null,
      "expired_at": null,
      "notes": "Coupon valid today",
      "created_at": "2026-06-24 10:30:00"
    }
  ]
}
```

---

### POST /reward/redeem.php

Redeem a reward using member points.

**Auth:** Bearer Token

**Request Body:**

```json
{
  "reward_id": "reward6abc",
  "notes": "Coupon valid today"
}
```

**Response 201:**

```json
{
  "status_code": 201,
  "status_message": "Redemption created successfully",
  "data": {
    "redemption_id": "rdm6abc",
    "redemption_code": "ABC12345",
    "reward_name": "Free Coffee",
    "points_used": 500,
    "new_balance": 1500,
    "status": "pending"
  }
}
```

---

### PUT /reward/redeem.php

Update redemption status.

**Auth:** Bearer Token

**Request Body:**

```json
{
  "redemption_id": "rdm6abc",
  "status": "claimed"
}
```

| `status` value | Description |
|----------------|-------------|
| `approved` | Admin approved the redemption |
| `rejected` | Admin rejected the redemption |
| `claimed` | Member used the reward |
| `expired` | Redemption expired |

**Response 200**

---

### GET /reward/points.php

Get the authenticated user's point balance and recent ledger.

**Auth:** Bearer Token

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "User points",
  "data": {
    "user_id": "usr_abc123",
    "company_id": "company6abc123",
    "balance": 2000,
    "ledger": [
      {
        "ledger_id": "ledger6abc",
        "type": "adjustment",
        "points": 500,
        "reference_type": "manual",
        "reference_id": null,
        "notes": "Bonus points from admin",
        "expired_at": null,
        "created_at": "2026-06-20 09:00:00"
      }
    ]
  }
}
```

---

### POST /reward/points.php

Manually add or deduct points for a user.

**Auth:** Bearer Token

**Request Body:**

```json
{
  "user_id": "usr_abc123",
  "company_id": "company6abc123",
  "points": 500,
  "notes": "Bonus points for top customer"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `user_id` | string | Yes | Target user's ID |
| `company_id` | string | Yes | Company ID |
| `points` | int | Yes | Points to add (positive) or deduct (negative); cannot be zero |
| `notes` | string | Yes | Reason for adjustment |

**Response 201:**

```json
{
  "status_code": 201,
  "status_message": "Points added successfully",
  "data": {
    "ledger_id": "ledger6abc",
    "user_id": "usr_abc123",
    "points_added": 500,
    "new_balance": 2500
  }
}
```

---

## Voucher

### GET /voucher/voucher.php

Get all vouchers or a single voucher. **No authentication required.**

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `company_id` | string | No | — | Filter by company |
| `voucher_id` | string | No | — | Return single voucher |
| `search` | string | No | — | Search by code or name |
| `page` | int | No | 1 | Page |
| `limit` | int | No | 10 | Items per page |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Voucher list retrieved successfully",
  "data": {
    "data": [
      {
        "voucher_id": "voucher6abc",
        "voucher_code": "DISKON20",
        "voucher_name": "Diskon 20%",
        "discount_type": "percentage",
        "discount_value": 20,
        "min_transaction": 50000,
        "max_discount": 20000,
        "usage_type": "multi_use",
        "max_total_usage": 100,
        "start_date": "2026-06-01",
        "end_date": "2026-06-30",
        "is_active": 1,
        "company_id": "company6abc123",
        "created_by": "admin001",
        "created_at": "2026-05-30 09:00:00"
      }
    ],
    "pagination": { "current_page": 1, "limit": 10, "total_data": 3, "total_page": 1 }
  }
}
```

---

### POST /voucher/voucher.php

**Auth:** Bearer Token

**Request Body:**

```json
{
  "voucher_code": "DISKON20",
  "voucher_name": "Diskon 20%",
  "discount_type": "percentage",
  "discount_value": 20,
  "min_transaction": 50000,
  "max_discount": 20000,
  "usage_type": "multi_use",
  "max_total_usage": 100,
  "start_date": "2026-06-01",
  "end_date": "2026-06-30",
  "is_active": 1
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `voucher_code` | string | Yes | Unique voucher code |
| `voucher_name` | string | Yes | Display name |
| `discount_type` | string | Yes | `nominal` or `percentage` |
| `discount_value` | int | Yes | Discount amount; max 100 if `percentage` |
| `min_transaction` | int | No | Minimum transaction to apply voucher |
| `max_discount` | int | No | Maximum discount cap (for percentage type) |
| `usage_type` | string | Yes | `one_time` or `multi_use` |
| `max_total_usage` | int | No | Total usage limit |
| `start_date` | date | Yes | `YYYY-MM-DD` |
| `end_date` | date | Yes | `YYYY-MM-DD` |
| `is_active` | int | Yes | `0` or `1` |

**Response 201:**

```json
{
  "status_code": 201,
  "status_message": "Voucher created successfully",
  "data": { "voucher_id": "voucher6abc" }
}
```

**PUT** — `{ "voucher_id": "...", ...updatable fields }` → 200  
**DELETE** — `?voucher_id=...` → 200

---

## Promo

Configurable, automatic discounts. A promo is a per-company rule of the form **"every `buy_quantity` eligible items earn `discount_amount` rupiah off"**, limited by day, menu/category, price and outlet type. Apply it from the POS with `apply_promo` on [`POST /transaction/index.php`](#post-transactionindexphp); preview it live with [`POST /promo/check.php`](#post-promocheckphp).

**Setup:** run `promo/promo_migration.sql` once per schema (`raki_dev` first, then `raki`).

### How a promo is evaluated

| Dimension | Field | Meaning when empty |
|-----------|-------|--------------------|
| Day | `days_of_week` (1 = Monday … 7 = Sunday) | any day |
| Outlet type | `outlet_types` (the cashier's role name, e.g. `Outlet`) | any role |
| Menu | `category_ids` **or** `menu_ids` (an item in either qualifies) | every single menu |
| Exclusion | `exclude_menu_ids` (never eligible, wins over the above) | none |
| Item price | `min_item_price` / `max_item_price` (inclusive, on the line's `unit_price`) | not checked |
| Cart value | `min_eligible_subtotal` (value of the eligible lines) | not checked |
| Cap | `max_applications` (max times the rule fires per transaction) | unlimited |

- Applications = `floor(eligible quantity / buy_quantity)`, capped by `max_applications`. Discount = applications × `discount_amount`, never more than the eligible items' value. The discount is spread over the eligible lines in proportion to their value.
- Package (`package_id`) lines are never eligible and never discounted.
- Days are judged on the sale's **Jakarta date** (`transaction_date`, default today). The promo applies **only if that date is today** (Jakarta), so a sale can't be back-dated or future-dated onto a promo day; otherwise the sale saves at full price.
- If several promos match, only the one with the largest discount applies (no stacking).
- Outlet type is the cashier's `app_role.role_name` from the JWT (`Outlet`, `Abang`, `Owner`, …).

### GET /promo/promo.php

List (`page`, `limit` ≤ 100, `is_active`, `search`) or fetch one (`?promo_id=`). Any authenticated user; always scoped to the company in the token.

**Response 200 (detail):**

```json
{
  "status_code": 200,
  "status_message": "Promo detail retrieved successfully",
  "data": {
    "promo_id": "promo6abc",
    "company_id": "company6abc123",
    "promo_name": "Monday Bestie Day",
    "promo_type": "buy_n_nominal_off",
    "buy_quantity": 2,
    "discount_amount": 3000,
    "max_applications": null,
    "min_item_price": null,
    "max_item_price": null,
    "min_eligible_subtotal": null,
    "is_active": 1,
    "days_of_week": [1],
    "category_ids": ["category6907fb386e005"],
    "menu_ids": [],
    "exclude_menu_ids": [],
    "outlet_types": ["Outlet"],
    "created_by": "owner1",
    "created_at": "2026-09-21 09:00:00",
    "updated_by": null,
    "updated_at": null
  }
}
```

The list response is `{ "data": [ ...promos ], "pagination": { "total", "page", "limit", "total_pages" } }`.

### POST /promo/promo.php

**Auth:** Bearer Token, role `Owner` (own company only; other roles get `403`).

Monday Bestie Day — buy 2 non-coffee drinks, Rp 3.000 off, Mondays, Outlet cashiers only:

```json
{
  "promo_name": "Monday Bestie Day",
  "buy_quantity": 2,
  "discount_amount": 3000,
  "days_of_week": [1],
  "category_ids": ["category6907fb386e005"],
  "outlet_types": ["Outlet"]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `promo_name` | string | Yes | Max 255 chars |
| `buy_quantity` | int | Yes | ≥ 1 |
| `discount_amount` | int | Yes | Rupiah off per application, ≥ 1 |
| `max_applications` | int\|null | No | ≥ 1; omit/null = unlimited. Set it to protect against several customers' cups being entered as one transaction |
| `min_item_price`, `max_item_price`, `min_eligible_subtotal` | int\|null | No | ≥ 0; `min_item_price` ≤ `max_item_price` |
| `is_active` | 0/1 | No | Default 1 |
| `days_of_week`, `category_ids`, `menu_ids`, `exclude_menu_ids`, `outlet_types` | string[] | No | Ids must exist; `outlet_types` are RAKI role names (case-insensitive, stored as the exact role name). An unknown value lists the valid ones in the error |

**Response 201:** the created promo (same shape as the detail above).

### PUT /promo/promo.php

**Auth:** Owner. Send `promo_id` plus any fields to change. A `null` clears a nullable field; a condition array that is present **replaces** that dimension (`[]` clears it); absent fields are untouched. Returns the updated promo.

### DELETE /promo/promo.php?promo_id=…

**Auth:** Owner. Removes the promo. Sales it already discounted keep their `transaction_promo` history (with a name snapshot). Use `is_active: 0` to switch a promo off instead.

### POST /promo/check.php

Stateless, read-only preview for the POS. Call it whenever the cart changes: the discount is calculated automatically, and it tells the cashier how many more items unlock it. It runs the same evaluation as `POST /transaction/index.php` with `apply_promo`, so the numbers match what gets saved.

**Auth:** Bearer Token (any role; `company_id` from the body, else the token — same rule as creating a transaction).

**Request Body:**

```json
{
  "company_id": "company6abc123",
  "transaction_date": "2026-09-21",
  "items": [
    { "menu_id": "menu6a541a7dd8d63", "quantity": 1, "unit_price": 16000 },
    { "menu_id": "menu6a157d58bfa89", "quantity": 1, "unit_price": 15000 }
  ]
}
```

`transaction_date` is optional (default today).

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Promo check",
  "data": {
    "eligible": true,
    "sale_date": "2026-09-21",
    "subtotal": 31000,
    "discount_amount": 3000,
    "total": 28000,
    "applied": {
      "promo_id": "promo6abc",
      "promo_name": "Monday Bestie Day",
      "promo_type": "buy_n_nominal_off",
      "applications": 1,
      "cap_reached": false,
      "discount_amount": 3000,
      "next_reward": { "items_needed": 2, "discount_amount": 3000 }
    },
    "lines": [
      { "index": 0, "menu_id": "menu6a541a7dd8d63", "package_id": null, "quantity": 1, "unit_price": 16000, "subtotal": 16000, "discount_amount": 1549, "net_subtotal": 14451 },
      { "index": 1, "menu_id": "menu6a157d58bfa89", "package_id": null, "quantity": 1, "unit_price": 15000, "subtotal": 15000, "discount_amount": 1451, "net_subtotal": 13549 }
    ],
    "evaluations": [
      { "promo_id": "promo6abc", "promo_name": "Monday Bestie Day", "applicable": true, "reason": null, "message": null, "eligible_quantity": 2, "applications": 1, "cap_reached": false, "discount_amount": 3000, "next_reward": { "items_needed": 2, "discount_amount": 3000 } }
    ]
  }
}
```

`evaluations` has one entry per active promo. When one doesn't apply, `reason` says why and `next_reward` (when relevant) says what is missing, e.g. one eligible drink in the cart gives `"reason": "need_more_items"` with `"next_reward": { "items_needed": 1, "discount_amount": 3000 }`.

| `reason` | Meaning |
|----------|---------|
| `sale_date_not_today` | `transaction_date` is not today (Jakarta) or is not a valid date |
| `day_not_allowed` | Not one of the promo's `days_of_week` |
| `outlet_type_not_allowed` | The cashier's role is not in `outlet_types` |
| `no_eligible_items` | Nothing in the cart matches the promo's menu/price rules |
| `need_more_items` | Fewer than `buy_quantity` eligible items |
| `below_min_subtotal` | Eligible items are worth less than `min_eligible_subtotal` |
| `nothing_to_discount` | The eligible items have no value to discount |

Always `200` for a valid cart, whether or not a promo applies. `400` for an invalid cart (empty, missing `quantity`/`unit_price`, non-positive quantity).

---

## Member

### GET /member/profile.php

**Auth:** Bearer Token

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Member profile",
  "data": {
    "username": "628123456789",
    "display_name": "Budi Santoso",
    "phone_number": "628123456789",
    "email": "budi@example.com",
    "language": "id",
    "profile_completion_pct": 75,
    "support_wa_number": "6285121951466"
  }
}
```

---

### GET /member/card.php

**Auth:** Bearer Token

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Member card",
  "data": {
    "member_id": "mbr_abc123",
    "display_name": "Budi Santoso",
    "points": 2000,
    "valid_until": "2027-06-24",
    "recent_points_delta": 50,
    "redeemable_count": 3
  }
}
```

---

### GET /member/points-history.php

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `page` | int | No | 1 | Page |
| `limit` | int | No | 20 | Items per page (max 100) |
| `type` | string | No | — | `earn`, `redeem`, or `adjustment` |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Points history",
  "data": {
    "data": [
      {
        "id": "ledger6abc",
        "description": "Pembelian Kopi Susu",
        "points": 50,
        "type": "earn",
        "created_at": "2026-06-24 10:30:00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "limit": 20,
      "total_data": 45,
      "total_page": 3
    }
  }
}
```

---

## Bonus Schema

### GET /bonus/schema.php

**Auth:** Bearer Token

**Query Parameters:** `schema_id`, `params`, `page` (default 1), `limit` (default 10)

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Schema found",
  "data": {
    "data": [
      {
        "schema_id": "schema001",
        "schema_name": "Tier 1",
        "frequency": "weekly",
        "qty": 50,
        "bonus_nominal": 100000,
        "is_active": 1
      }
    ],
    "pagination": { "total": 3, "page": 1, "limit": 10, "total_pages": 1 }
  }
}
```

---

### POST /bonus/schema.php

**Auth:** Bearer Token

**Request Body:**

```json
{
  "schema_name": "Tier 1",
  "frequency": "weekly",
  "qty": 50,
  "bonus_nominal": 100000
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `schema_name` | string | Yes | Schema display name |
| `frequency` | string | Yes | e.g. `weekly` |
| `qty` | int | Yes | Minimum cup quantity (≥ 1) |
| `bonus_nominal` | int | Yes | Bonus amount in IDR (≥ 1) |

**Response 201**

**PUT** — `{ "schema_id": "...", ...fields }` → 200  
**DELETE** — `?schema_id=...` → 200

---

## Driver (Abang)

### GET /abang/bonus.php

Get the weekly bonus summary for all drivers under a company.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `company_id` | string | No | from token | Override company |
| `start` | date | No | this Monday | Week start (`YYYY-MM-DD`) |
| `end` | date | No | this Sunday | Week end (`YYYY-MM-DD`) |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Driver bonus summary",
  "data": {
    "period": { "start": "2026-06-22", "end": "2026-06-28" },
    "total_drivers": 3,
    "drivers": [
      {
        "username": "driver001",
        "first_name": "Budi",
        "current_total_item": 100,
        "total_bonus_nominal": 200000,
        "current_bonus": {
          "schema_id": "schema002",
          "schema_name": "Tier 2",
          "achieved_qty": 100,
          "bonus_nominal": 200000
        },
        "next_target": {
          "schema_id": "schema003",
          "schema_name": "Tier 3",
          "target_qty": 150,
          "bonus_nominal": 300000,
          "remaining_item": 50,
          "progress_percentage": 66
        }
      }
    ]
  }
}
```

---

### GET /abang/status.php

Get online/offline status + last-seen for every driver belonging to the requesting owner's company. "Online" means the driver currently has a `work_session` with `status = 'active'`.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `company_id` | string | No | from token | Override company |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Driver status fetched",
  "data": {
    "company_id": "company6abc123",
    "total_drivers": 5,
    "online_count": 3,
    "drivers": [
      {
        "username": "driver001",
        "first_name": "Budi",
        "phone_number": "628123456789",
        "status": "online",
        "session_id": "ses_6abc123",
        "last_seen": "2026-06-24 08:00:00"
      },
      {
        "username": "driver002",
        "first_name": "Andi",
        "phone_number": "628987654321",
        "status": "offline",
        "session_id": "ses_6abc456",
        "last_seen": "2026-06-23 17:45:00"
      }
    ]
  }
}
```

For an online driver, `last_seen` is when their current session started. For an offline driver, it's when their most recent session ended (or started, if it was never closed). Drivers with no session history yet have `session_id`/`last_seen` as `null`. Results are sorted online-first.

For richer session detail (stock levels, payments taken so far) for currently-active drivers only, see `GET /session/active-drivers.php` in the [Session](#session) section.

---

## Digital Products

### GET /digital/get_products.php

Fetch available digital products from the GrosirVoucher provider.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `category` | string | No | Filter by product category (e.g. `pulsa`, `data`) |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Products retrieved",
  "data": [
    {
      "product_code": "TSEL5K",
      "product_name": "Telkomsel 5.000",
      "category": "pulsa",
      "price": 5500,
      "description": "Pulsa Telkomsel Rp 5.000"
    }
  ]
}
```

---

### POST /digital/purchase.php

Purchase a digital product (mobile top-up, data plan, etc.).

**Auth:** Bearer Token

**Request Body:**

```json
{
  "product_code": "TSEL5K",
  "msisdn": "08123456789",
  "company_id": "company6abc123"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `product_code` | string | Yes | Product code from the catalog |
| `msisdn` | string | Yes | Destination phone number |
| `company_id` | string | Yes | Company ID |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Purchase initiated",
  "data": {
    "client_trx_id": "MVR-A1B2C3D4E5F6G7H8-1750786800",
    "status": "PENDING",
    "gv_response": {}
  }
}
```

**Status values:** `SUCCESS`, `PENDING`, `FAILED`

---

### GET /digital/check_status.php

Check the status of a digital purchase.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `client_trx_id` | string | Yes | ID returned from `/digital/purchase.php` |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Transaction status retrieved",
  "data": {
    "client_transaction_id": "MVR-A1B2C3D4E5F6G7H8-1750786800",
    "product_code": "TSEL5K",
    "msisdn": "08123456789",
    "status": "SUCCESS",
    "server_transaction_id": "GV-999123",
    "status_code": "00",
    "sn": "1234567890",
    "message": "Transaction success",
    "created_at": "2026-06-24 10:30:00",
    "updated_at": "2026-06-24 10:31:00"
  }
}
```

---

### GET /digital/check_balance.php

Check the GrosirVoucher provider deposit balance. **Admin role only.**

**Auth:** Bearer Token (role must be `admin`)

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Balance retrieved",
  "data": {
    "balance": 5000000,
    "currency": "IDR"
  }
}
```

---

## Supply

### GET /supply/supplier.php

**Auth:** Bearer Token

**Query Parameters:** `supplier_id`, `params`, `page` (default 1), `limit` (default 10)

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Supplier found",
  "data": {
    "data": [
      {
        "supplier_id": "sup6abc",
        "supplier_name": "PT Susu Segar",
        "contact_person": "Andi",
        "company_id": "company6abc123",
        "phone": "628111222333",
        "email": "andi@sususegar.com",
        "address": "Jl. Raya No.1, Jakarta",
        "is_active": 1
      }
    ],
    "pagination": { "total": 5, "page": 1, "limit": 10, "total_pages": 1 }
  }
}
```

**POST Request Body:**

```json
{
  "supplier_name": "PT Susu Segar",
  "contact_person": "Andi",
  "company_id": "company6abc123",
  "phone": "628111222333",
  "email": "andi@sususegar.com",
  "address": "Jl. Raya No.1, Jakarta",
  "is_active": true
}
```

**PUT** — `{ "supplier_id": "...", ...fields }` → 200  
**DELETE** — `?supplier_id=...` → 200

---

### GET /supply/order.php

Get supply orders, optionally filtered.

**Auth:** Bearer Token

**Query Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `supply_order_id` | string | No | — | Return single order with items |
| `from_company_id` | string | No | — | Filter by requesting company |
| `to_company_id` | string | No | — | Filter by receiving company |
| `status` | string | No | — | Filter by status |
| `company_id` | string | No | — | Alias for `to_company_id` |
| `page` | int | No | 1 | Page |
| `limit` | int | No | 10 | Items per page |

**Response 200 (list):**

```json
{
  "status_code": 200,
  "status_message": "Success",
  "data": {
    "data": [
      {
        "supply_order_id": "so_6abc",
        "order_code": "SO20260624143012001",
        "from_company_id": "company6abc",
        "company_name": "Raki Coffee Sudirman",
        "to_company_id": "company_pusat",
        "status": "pending",
        "notes": "Urgent order",
        "requested_at": "2026-06-24 14:30:12",
        "approved_at": null,
        "completed_at": null,
        "total_amount": 500000
      }
    ],
    "pagination": { "total": 12, "page": 1, "limit": 10, "total_pages": 2 }
  }
}
```

**Response 200 (single with `supply_order_id`):**

```json
{
  "status_code": 200,
  "status_message": "Success",
  "data": {
    "order": { "supply_order_id": "so_6abc", "status": "pending", "total_amount": 500000 },
    "items": [
      {
        "ingredient_id": "ingredients6abc",
        "ingredient_name": "Susu Full Cream",
        "category_name": "Dairy",
        "qty": 10,
        "unit_price": 50000,
        "subtotal": 500000
      }
    ]
  }
}
```

---

### POST /supply/order.php

Create a new supply order. Triggers a WhatsApp notification to the receiving company's PIC.

**Auth:** Bearer Token

**Request Body:**

```json
{
  "from_company_id": "company6abc123",
  "to_company_id": "company_pusat",
  "total_amount": 500000,
  "notes": "Urgent order",
  "items": [
    { "ingredient_id": "ingredients6abc", "qty": 10, "unit_price": 50000 }
  ]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `from_company_id` | string | Yes | Requesting company ID |
| `to_company_id` | string | Yes | Receiving company ID |
| `total_amount` | int | Yes | Total order value in IDR |
| `items` | array | Yes | Array of `{ ingredient_id, qty, unit_price }` |
| `notes` | string | No | Notes for the order |

**Response 201:**

```json
{
  "status_code": 201,
  "status_message": "Supply order created successfully",
  "data": {
    "supply_order_id": "so_6abc",
    "order_code": "SO20260624143012001"
  }
}
```

---

### PUT /supply/order.php

Update supply order status and optionally add shipping information.

**Auth:** Bearer Token

**Request Body:**

```json
{
  "supply_order_id": "so_6abc",
  "status": "shipped",
  "shipping_cost": 50000,
  "shipping_awb": "JNE001234567",
  "shipping": "JNE"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `supply_order_id` | string | Yes | Order ID |
| `status` | string | Yes | `pending`, `approved`, `rejected`, `processing`, `shipped`, `completed`, `cancelled` |
| `shipping_cost` | int | Conditional | Required when `status=shipped` |
| `shipping_awb` | string | No | Airway bill number |
| `shipping` | string | No | Shipping company name |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Status updated successfully",
  "data": {
    "supply_order_id": "so_6abc",
    "status": "shipped",
    "shipping_cost": 50000,
    "shipping_awb": "JNE001234567",
    "shipping": "JNE"
  }
}
```

---

## Settings

### /settings/uom.php

Manage units of measurement (UOM).

**Auth:** Bearer Token (all methods)

**GET Query Parameters:** `uom_id`, `params`, `page` (default 1), `limit` (default 10)

**GET Response 200:**

```json
{
  "status_code": 200,
  "status_message": "UOM found",
  "data": {
    "data": [
      { "uom_id": "uom6abc", "uom_name": "Liter" }
    ],
    "pagination": { "total": 5, "page": 1, "limit": 10, "total_pages": 1 }
  }
}
```

**POST** — `{ "uom_name": "Liter" }` → 201  
**PUT** — `{ "uom_id": "...", "uom_name": "..." }` → 200  
**DELETE** — `?uom_id=...` → 200

---

## Notification

### GET /notification/notification.php

Trigger the weekly WhatsApp recap. Normally invoked by a cron job every Saturday at 21:00 WIB. Pass `?debug=1` to bypass the time guard during development.

**Auth:** None

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `company_id` | string | No | Send only to this company |
| `debug` | int | No | `1` to bypass time restriction |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "WhatsApp recap processed. Check per-company results.",
  "results": [
    {
      "company_id": "company6abc123",
      "company_name": "Raki Coffee Sudirman",
      "pic_contact": "628111222333",
      "success": true,
      "whatsapp": { "success": true, "httpCode": 200 }
    }
  ]
}
```

---

### GET /notification/settings.php

Get the notification preferences for the authenticated user.

**Auth:** Bearer Token

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Notification settings",
  "data": { "general_notif": true }
}
```

---

### POST /notification/settings.php

Update notification preferences.

**Auth:** Bearer Token

**Request Body:**

```json
{
  "general_notif": false
}
```

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Notification settings updated",
  "data": { "general_notif": false }
}
```

---

## Public Endpoints

### GET /public/active-driver-stocks.php

Get all active drivers and their remaining stock. Driver phone numbers are partially masked. **No authentication required.**

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `company_id` | string | No | Filter by company |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Active drivers + stock found",
  "data": [
    {
      "session_id": "ses_6abc123",
      "started_at": "2026-06-24 08:00:00",
      "driver_display": "Abang 6281****789",
      "menus": [
        {
          "menu_id": "menu001",
          "menu_name": "Kopi Susu",
          "category_name": "Coffee",
          "thumb_url": "https://your-domain.com/uploads/thumbs/kopi-susu.jpg",
          "qty_left": 25
        }
      ]
    }
  ]
}
```

---

## Error Responses

All endpoints use a consistent error envelope:

```json
{
  "status_code": 400,
  "status_message": "Human-readable error description"
}
```

| Status Code | Meaning |
|-------------|---------|
| `400` | Bad Request — missing or invalid parameters |
| `401` | Unauthorized — missing or invalid JWT token |
| `403` | Forbidden — insufficient role |
| `404` | Not Found — resource does not exist |
| `405` | Method Not Allowed |
| `500` | Internal Server Error |

---

*Generated from source code. Last updated: 2026-06-24.*
