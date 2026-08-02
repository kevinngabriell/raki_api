# Transaction Notification Routing by Role

Documents the role-based notification change to `POST /transaction/index.php` and the new `notification/outlet_daily_recap.php` cron.

## Summary

Transaction notifications are now routed by the creator's role:

| Role | Notification behavior |
|------|------------------------|
| **Abang** (and any role other than Outlet) | Unchanged — WhatsApp sent immediately after every transaction, with email fallback to the company Owner if WhatsApp fails. |
| **Outlet** | No per-transaction notification. Instead, a single recap of the day's Outlet sales (total transactions, total revenue, total cups) is sent once at **18:00 WIB** via `notification/outlet_daily_recap.php`. |

The role is read from the `role` claim already present in the JWT (`app_role_id`, set at login), resolved to a `role_name` via `app_role`, and compared against `'Outlet'`.

## Is there any payload or response change for `POST /transaction/index.php`?

**No.** The request body and response body are exactly the same as documented in `API_DOCUMENTATION.md` — no new fields were added or removed. The role check uses the `role` claim already embedded in the Bearer token from login; nothing new is required from the client.

- Request body: unchanged (`company_id`, `items[]`, `payments[]`, optional `transaction_date`).
- Response `201`: unchanged (`transaction_id`, `company_id`, `transaction_date`, `total_amount`, `items[]`).

The only behavioral difference is invisible to the client: if the caller's role resolves to `Outlet`, the notification side-effect (WhatsApp/email) that used to fire after every transaction is skipped. The transaction is still created and committed identically either way.

## What changed internally

`transaction/index.php`:
- `createTransaction()` gained a 5th parameter, `$role` (nullable), populated from `$decoded->role` at the POST call site.
- After commit, the code resolves `$role` → `role_name` via `app_role` and skips the existing WhatsApp/email notification block when `role_name === 'Outlet'`.

## New endpoint: `GET /notification/outlet_daily_recap.php`

Sends the once-daily Outlet sales recap. Follows the same pattern as the existing `notification/notification.php` (weekly) and `notification/daily_summary.php` (nightly) crons.

**Auth:** None (intended for cron/CLI use; add a secret guard if exposing over the public internet, matching `weekly_report_cron.php`'s `CRON_SECRET` pattern if needed).

**Trigger:** Cron only fires it at 18:00 WIB — outside that hour, HTTP calls return `403` unless `debug=1` is passed. CLI execution (`php outlet_daily_recap.php`) always runs regardless of time.

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `company_id` | string | No | Send only to this company |
| `debug` | int | No | `1` to bypass the 18:00 time restriction (HTTP only) |

**Response 200:**

```json
{
  "status_code": 200,
  "status_message": "Outlet daily recap processed. Check per-company results.",
  "date": "2026-07-02",
  "results": [
    {
      "company_id": "company6abc123",
      "company_name": "Raki Coffee Sudirman",
      "pic_contact": "628111222333",
      "trx_count": 12,
      "total_amount": 540000,
      "total_cups": 30,
      "success": true,
      "whatsapp": { "success": true, "httpCode": 200 }
    }
  ]
}
```

If a company had zero Outlet-created transactions that day, it's silently skipped — no WhatsApp/email is sent for it, and it appears in `results` as `{ "company_id": "...", "skipped": true, "reason": "No Outlet transactions today" }`.

**Suggested cron entry** (server timezone Asia/Jakarta):

```cron
0 18 * * * cd /path/to/raki_api && /usr/bin/php notification/outlet_daily_recap.php >> /var/log/raki_outlet_recap.log 2>&1
```
