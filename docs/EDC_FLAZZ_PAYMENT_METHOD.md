# New Payment Method: EDC/Flazz

Documents the new `edc_flazz` `payment_method` value accepted by `POST /transaction/index.php`,
and how it flows through session reconciliation. Added for the Outlet POS flow's new "EDC/Flazz"
single-tap button (card/e-money tap on a physical EDC machine, settled outside the app).

## Summary

| Endpoint | Accepts `edc_flazz`? |
|----------|------------------------|
| `POST /transaction/index.php` | **Yes** — `transaction/index.php:146` |
| `POST /pos/transaction.php` | **No** — left untouched (legacy/unreferenced endpoint, not used by the current Outlet POS flow) |

There is no role gate on `payment_method` in `transaction/index.php` — any caller can send `edc_flazz`,
the same as `cash`/`qris`. Restricting the button to the Outlet role is a frontend-only concern.

## Payload

Send it exactly like `cash`/`qris`, as one entry in `payments[]`:

```json
{
  "company_id": "company6abc123",
  "items": [
    { "menu_id": "menu001", "quantity": 2, "unit_price": 15000 }
  ],
  "payments": [
    { "payment_method": "edc_flazz", "amount": 30000 }
  ]
}
```

`payments[].amount` must still sum to the computed transaction total, same validation as before
(`transaction/index.php:200-212`). Multiple methods can be combined in one transaction, e.g.
`[{ "payment_method": "cash", "amount": 10000 }, { "payment_method": "edc_flazz", "amount": 20000 }]`.

## How it's treated downstream

**Business decision:** EDC/Flazz is treated as cash for drawer reconciliation purposes — it does not
get its own bucket.

- **`session/end.php`** (`POST /session/end.php`) — the end-of-session recap sums `cash` and
  `edc_flazz` together into `total_cash`. `total_qris` is unaffected. `total_sales = total_cash + total_qris`
  still holds. See `session/end.php:135-143`.
- **`session/active.php`** (`GET /session/active.php`) — the live `payment_summary.cash_amount` field
  likewise sums `cash` and `edc_flazz`. `qris_amount`, `transfer_amount`, `qris_midtrans_amount` are
  unaffected. See `session/active.php:73`.
- **Dashboard / reports** (`dashboard/index.php`, `dashboard/statistic.php`,
  `dashboard/weekly_report_data.php`, `session/transaction-history.php`, `session/active-drivers.php`)
  — these all group/aggregate by `payment_method` dynamically and required no code change. They will
  show `edc_flazz` as its own distinct row/key, separate from `cash` — only the two session endpoints
  above fold it into a combined cash figure.

**Caveat:** because `total_cash` (session/end.php) and `cash_amount` (session/active.php) now include
non-physical money, they no longer represent "cash that should physically be in the drawer." The
`cash_end` vs `cash_start` discrepancy check in `session/end.php` compares against the counted
physical drawer amount, so a cashier reconciling with sales reports need to be aware EDC/Flazz sales
are included in the "Cash" line even though no cash was added to the drawer for them.

## What changed internally

- `transaction/index.php:146` — `$allowed_methods` gained `'edc_flazz'`.
- `transaction/index.php:207,211` — total-mismatch error message generalized (no longer hardcodes
  "cash + qris").
- `session/end.php:138-141` — payment breakdown loop changed from `=` to `+=` and now matches
  `cash` OR `edc_flazz` into `total_cash`. (This also fixes a latent bug: any `payment_method` not
  explicitly matched here was previously silently dropped from `total_sales`.)
- `session/active.php:73` — `cash_amount`'s `CASE WHEN` changed from `= 'cash'` to
  `IN ('cash', 'edc_flazz')`.

## Database migration

`transaction_payment.payment_method` is a MySQL `ENUM`, not a free-form VARCHAR:

```
enum('cash','qris','transfer','qris_midtrans')
```

`edc_flazz` was added to the enum on both schemas (metadata-only `ALTER TABLE`, no table rewrite):

```sql
ALTER TABLE raki_dev.transaction_payment
  MODIFY COLUMN payment_method ENUM('cash','qris','transfer','qris_midtrans','edc_flazz') NOT NULL;

ALTER TABLE raki.transaction_payment
  MODIFY COLUMN payment_method ENUM('cash','qris','transfer','qris_midtrans','edc_flazz') NOT NULL;
```

Applied to both `raki_dev` and `raki` (prod, per `config.php`'s `DB_SCHEMA` default) on 2026-07-03.
Without this, any transaction sent with `payment_method: "edc_flazz"` would fail at the INSERT —
the application-level allowlist in `transaction/index.php:146` is necessary but not sufficient.

## Not changed

- `pos/transaction.php` — still only accepts `cash`/`qris`. Update it too if it turns out to still be
  live for some client.
