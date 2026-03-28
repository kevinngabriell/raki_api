# Voucher API Documentation

**Base URL:** `/voucher/voucher.php`
**Auth:** Bearer token required on all endpoints (`Authorization: Bearer <token>`)

---

## Endpoints

### 1. Create Voucher
`POST /voucher/voucher.php`

#### Required Fields
| Field | Type | Description |
|---|---|---|
| `voucher_code` | string | Unique code for the voucher (e.g. `SAVE10`) |
| `voucher_name` | string | Display name of the voucher |
| `discount_type` | string | `nominal` or `percentage` |
| `discount_value` | integer | Discount amount. If `percentage`, max is 100 |
| `usage_type` | string | `one_time` or `multi_use` |
| `start_date` | string | Valid date string (e.g. `2026-04-01`) |
| `end_date` | string | Valid date string, must be >= `start_date` |
| `is_active` | integer | `1` = active, `0` = inactive |

#### Optional Fields
| Field | Type | Description |
|---|---|---|
| `min_transaction` | integer | Minimum transaction amount to apply voucher. Defaults to `0` |
| `max_discount` | integer | Max discount cap (useful for `percentage` type). Defaults to `null` |
| `max_total_usage` | integer | Total number of times voucher can be used. Defaults to `null` (unlimited) |

#### Request Body (JSON)
```json
{
  "voucher_code": "SAVE10",
  "voucher_name": "Save 10%",
  "discount_type": "percentage",
  "discount_value": 10,
  "min_transaction": 50000,
  "max_discount": 30000,
  "usage_type": "multi_use",
  "max_total_usage": 100,
  "start_date": "2026-04-01",
  "end_date": "2026-04-30",
  "is_active": 1
}
```

#### Response — 201 Created
```json
{
  "status": 201,
  "message": "Voucher created successfully",
  "data": {
    "voucher_id": "voucher6613a4b2f3a1c"
  }
}
```

#### Error Responses
| Code | Message |
|---|---|
| 400 | `<field> is required` |
| 400 | `is_active must be 0 or 1` |
| 400 | `Invalid discount_type` |
| 400 | `Invalid usage_type` |
| 400 | `discount_value must be greater than 0` |
| 400 | `percentage discount cannot be more than 100` |
| 400 | `Invalid date format` |
| 400 | `start_date cannot be greater than end_date` |
| 400 | `Voucher has already exists in database !!` |
| 500 | `Failed to create voucher: <db error>` |

---

### 2. Get All Vouchers
`GET /voucher/voucher.php`

#### Query Parameters
| Param | Type | Required | Description |
|---|---|---|---|
| `page` | integer | No | Page number. Defaults to `1` |
| `limit` | integer | No | Items per page. Defaults to `10` |
| `search` | string | No | Search by `voucher_code` or `voucher_name` |

#### Example Request
```
GET /voucher/voucher.php?page=1&limit=10&search=SAVE
```

#### Response — 200 OK
```json
{
  "status": 200,
  "message": "Voucher list retrieved successfully",
  "data": {
    "data": [
      {
        "voucher_id": "voucher6613a4b2f3a1c",
        "voucher_code": "SAVE10",
        "voucher_name": "Save 10%",
        "discount_type": "percentage",
        "discount_value": "10",
        "min_transaction": "50000",
        "max_discount": "30000",
        "usage_type": "multi_use",
        "max_total_usage": "100",
        "start_date": "2026-04-01",
        "end_date": "2026-04-30",
        "is_active": "1",
        "company_id": "company123",
        "created_by": "admin",
        "created_at": "2026-03-28 10:00:00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "limit": 10,
      "total_data": 1,
      "total_page": 1
    }
  }
}
```

---

### 3. Get Voucher Detail
`GET /voucher/voucher.php?voucher_id=<id>`

#### Query Parameters
| Param | Type | Required | Description |
|---|---|---|---|
| `voucher_id` | string | Yes | The voucher ID |

#### Example Request
```
GET /voucher/voucher.php?voucher_id=voucher6613a4b2f3a1c
```

#### Response — 200 OK
```json
{
  "status": 200,
  "message": "Voucher detail retrieved successfully",
  "data": {
    "voucher_id": "voucher6613a4b2f3a1c",
    "voucher_code": "SAVE10",
    "voucher_name": "Save 10%",
    "discount_type": "percentage",
    "discount_value": "10",
    "min_transaction": "50000",
    "max_discount": "30000",
    "usage_type": "multi_use",
    "max_total_usage": "100",
    "start_date": "2026-04-01",
    "end_date": "2026-04-30",
    "is_active": "1",
    "company_id": "company123",
    "created_by": "admin",
    "created_at": "2026-03-28 10:00:00"
  }
}
```

#### Error Responses
| Code | Message |
|---|---|
| 400 | `voucher_id is required` |
| 404 | `Voucher not found` |

---

### 4. Update Voucher
`PUT /voucher/voucher.php`

All fields are optional except `voucher_id`. Only provided fields will be updated.

#### Request Body (JSON)
| Field | Type | Description |
|---|---|---|
| `voucher_id` | string | **Required.** ID of the voucher to update |
| `voucher_code` | string | New voucher code |
| `voucher_name` | string | New display name |
| `discount_type` | string | `nominal` or `percentage` |
| `discount_value` | integer | Must be > 0 |
| `min_transaction` | integer | New min transaction amount |
| `max_discount` | integer\|null | Set to `null` to remove cap |
| `usage_type` | string | `one_time` or `multi_use` |
| `max_total_usage` | integer\|null | Set to `null` for unlimited |
| `start_date` | string | Valid date string |
| `end_date` | string | Valid date string |
| `is_active` | integer | `0` or `1` |

#### Example Request Body
```json
{
  "voucher_id": "voucher6613a4b2f3a1c",
  "voucher_name": "Save 10% - Extended",
  "end_date": "2026-05-31",
  "is_active": 1
}
```

#### Response — 200 OK
```json
{
  "status": 200,
  "message": "Voucher updated successfully"
}
```

#### Error Responses
| Code | Message |
|---|---|
| 400 | `voucher_id is required` |
| 400 | `No fields provided for update` |
| 400 | `Invalid discount_type` |
| 400 | `Invalid usage_type` |
| 400 | `discount_value must be greater than 0` |
| 400 | `Invalid start_date` |
| 400 | `Invalid end_date` |
| 400 | `is_active must be 0 or 1` |
| 404 | `Voucher not found` |
| 500 | `Failed to update voucher: <db error>` |

---

### 5. Delete Voucher
`DELETE /voucher/voucher.php?voucher_id=<id>`

#### Query Parameters
| Param | Type | Required | Description |
|---|---|---|---|
| `voucher_id` | string | Yes | The voucher ID to delete |

#### Example Request
```
DELETE /voucher/voucher.php?voucher_id=voucher6613a4b2f3a1c
```

#### Response — 200 OK
```json
{
  "status": 200,
  "message": "Voucher deleted successfully"
}
```

#### Error Responses
| Code | Message |
|---|---|
| 400 | `voucher_id is required` |
| 404 | `Voucher not found` |
| 500 | `Failed to delete voucher: <db error>` |

---

## Common Auth Errors (All Endpoints)
| Code | Message |
|---|---|
| 401 | `Authorization header not found` |
| 500 | `Internal Server Error` |

---

## Test Use Cases (Dev Environment)

### TC-001 — Create percentage voucher with cap (Happy Path)
```json
POST /voucher/voucher.php
{
  "voucher_code": "RAKI10",
  "voucher_name": "Raki 10% Off",
  "discount_type": "percentage",
  "discount_value": 10,
  "min_transaction": 50000,
  "max_discount": 25000,
  "usage_type": "multi_use",
  "max_total_usage": 200,
  "start_date": "2026-04-01",
  "end_date": "2026-06-30",
  "is_active": 1
}
```
**Expected:** `201 Created` with `voucher_id`

---

### TC-002 — Create nominal/flat discount voucher (Happy Path)
```json
POST /voucher/voucher.php
{
  "voucher_code": "FLAT20K",
  "voucher_name": "Flat Rp20.000 Discount",
  "discount_type": "nominal",
  "discount_value": 20000,
  "min_transaction": 100000,
  "usage_type": "multi_use",
  "start_date": "2026-04-01",
  "end_date": "2026-04-30",
  "is_active": 1
}
```
**Expected:** `201 Created` — no `max_discount` needed for nominal type

---

### TC-003 — Create one-time use voucher (Happy Path)
```json
POST /voucher/voucher.php
{
  "voucher_code": "NEWUSER50",
  "voucher_name": "New User 50% Off",
  "discount_type": "percentage",
  "discount_value": 50,
  "min_transaction": 0,
  "max_discount": 50000,
  "usage_type": "one_time",
  "max_total_usage": 1,
  "start_date": "2026-04-01",
  "end_date": "2026-12-31",
  "is_active": 1
}
```
**Expected:** `201 Created`

---

### TC-004 — Duplicate voucher (Error Case)
> Create TC-001 first, then send the same request again.

**Expected:** `400 Voucher has already exists in database !!`

---

### TC-005 — percentage discount > 100 (Error Case)
```json
POST /voucher/voucher.php
{
  "voucher_code": "OVER100",
  "voucher_name": "Invalid Over 100%",
  "discount_type": "percentage",
  "discount_value": 150,
  "usage_type": "multi_use",
  "start_date": "2026-04-01",
  "end_date": "2026-04-30",
  "is_active": 1
}
```
**Expected:** `400 percentage discount cannot be more than 100`

---

### TC-006 — start_date after end_date (Error Case)
```json
POST /voucher/voucher.php
{
  "voucher_code": "BADDATE",
  "voucher_name": "Bad Date Voucher",
  "discount_type": "nominal",
  "discount_value": 5000,
  "usage_type": "multi_use",
  "start_date": "2026-06-01",
  "end_date": "2026-04-01",
  "is_active": 1
}
```
**Expected:** `400 start_date cannot be greater than end_date`

---

### TC-007 — Invalid discount_type (Error Case)
```json
POST /voucher/voucher.php
{
  "voucher_code": "BADTYPE",
  "voucher_name": "Bad Type Voucher",
  "discount_type": "flat",
  "discount_value": 5000,
  "usage_type": "multi_use",
  "start_date": "2026-04-01",
  "end_date": "2026-04-30",
  "is_active": 1
}
```
**Expected:** `400 Invalid discount_type`

---

### TC-008 — Missing required field (Error Case)
```json
POST /voucher/voucher.php
{
  "voucher_code": "MISSINGFIELD",
  "voucher_name": "Missing End Date",
  "discount_type": "nominal",
  "discount_value": 10000,
  "usage_type": "multi_use",
  "start_date": "2026-04-01",
  "is_active": 1
}
```
**Expected:** `400 end_date is required`

---

### TC-009 — Get all vouchers with search (Happy Path)
```
GET /voucher/voucher.php?page=1&limit=5&search=RAKI
```
**Expected:** `200` with list filtered to vouchers matching `RAKI`

---

### TC-010 — Get voucher detail (Happy Path)
```
GET /voucher/voucher.php?voucher_id=<voucher_id from TC-001>
```
**Expected:** `200` with full voucher object

---

### TC-011 — Get detail with invalid ID (Error Case)
```
GET /voucher/voucher.php?voucher_id=voucher_does_not_exist
```
**Expected:** `404 Voucher not found`

---

### TC-012 — Update voucher partial fields (Happy Path)
```json
PUT /voucher/voucher.php
{
  "voucher_id": "<voucher_id from TC-001>",
  "end_date": "2026-09-30",
  "max_total_usage": 500
}
```
**Expected:** `200 Voucher updated successfully`

---

### TC-013 — Update voucher — deactivate (Happy Path)
```json
PUT /voucher/voucher.php
{
  "voucher_id": "<voucher_id from TC-001>",
  "is_active": 0
}
```
**Expected:** `200 Voucher updated successfully`

---

### TC-014 — Update with no fields (Error Case)
```json
PUT /voucher/voucher.php
{
  "voucher_id": "<voucher_id from TC-001>"
}
```
**Expected:** `400 No fields provided for update`

---

### TC-015 — Delete voucher (Happy Path)
```
DELETE /voucher/voucher.php?voucher_id=<voucher_id from TC-002>
```
**Expected:** `200 Voucher deleted successfully`

---

### TC-016 — Delete already-deleted voucher (Error Case)
> Run TC-015 again with the same `voucher_id`.

**Expected:** `404 Voucher not found`
