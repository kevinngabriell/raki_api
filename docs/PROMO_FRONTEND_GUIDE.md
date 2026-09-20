# Promo & Discount — Frontend Implementation Guide

For the **POS (cashier app)** and **Dashboard (Owner)** developers. The backend is finished and the database migration is applied on `raki_dev` and `raki`. This guide says what to build and how to wire it up.

Full endpoint reference: [API_DOCUMENTATION.md → Promo](API_DOCUMENTATION.md#promo) and [Transaction](API_DOCUMENTATION.md#transaction). This guide does not repeat every field; it tells you the flow.

---

## 1. What we are building

### The first promo: "Monday Bestie Day"

> Every **Monday** (Jakarta time), at **Outlet** cashiers only, **non-coffee drinks only**: for every **2** drinks, **Rp 3.000 off**. Mix and match any non-coffee drinks. No limit on pairs (2 drinks = 3.000, 4 = 6.000, 6 = 9.000).

Confirmed by the business: "kelipatan 2 dapet disc 3000".

### Two things to build

| Surface | Who | What |
|---------|-----|------|
| **POS** | Cashier (role `Outlet`) | The discount appears **automatically** as drinks are added to the cart, shows the running total, prompts the cashier when one more drink unlocks the discount, and is saved with the sale. The cashier never types a discount. |
| **Dashboard** | Business Owner (role `Owner`) | A **Promo** page to create, edit, switch on/off and delete promos. Promos are configurable by day, menu/category, price and outlet type, so the next promo needs no backend change. |

### Ground rules

- **The server is the source of truth.** Never calculate a discount in the frontend. Show what `POST /promo/check.php` returns. The server recalculates when the sale is saved and rejects a payment that doesn't match.
- **Never decide "is it Monday?" in the app.** The server judges it in Asia/Jakarta. If the device clock or timezone is wrong, nothing breaks.
- **Opt-in.** Only requests that send `apply_promo: true` are discounted. Old POS builds keep working exactly as before, just with no discount.
- **Discounted sales are stored net** (after discount). `total_amount` is what the customer pays. Dashboards and cash reconciliation already work off it.

---

## 2. Common API conventions

- Base URL and Bearer JWT are the same as every other endpoint (`Authorization: Bearer <token>`).
- Every response is `{ "status_code": 200, "status_message": "...", "data": ... }`, and the HTTP status equals `status_code`.
- The JWT carries `company_id` and `role`; the server takes the company and cashier role from it. Promos are **per company** (one outlet = one company).
- Money is **whole rupiah integers**. `unit_price` must be an integer.
- `401` = missing/invalid/expired token → send the user to login. `403` = role not allowed. `400` = validation, and `status_message` is safe to show.

---

## 3. POS implementation

### 3.1 Flow

```
cart changes ──► POST /promo/check.php ──► render discount + nudge
                                              │
                  payment screen ◄────────────┘   amount due = data.total
                        │
       cashier confirms ▼
   POST /transaction/index.php  { apply_promo: true, items, payments (sum = net total) }
                        │
                        ▼
   201 → receipt uses response fields      400 (payment mismatch) → refresh totals from response.data, re-confirm
```

### 3.2 Step 1: check on every cart change

Call `POST /promo/check.php` whenever items or quantities change. Debounce ~300 ms and ignore out-of-order responses (only render the response for the latest cart). It is read-only, so it's safe to call often.

**Request**

```json
{
  "items": [
    { "menu_id": "menu6a541a7dd8d63", "quantity": 1, "unit_price": 16000 },
    { "menu_id": "menu6a157d58bfa89", "quantity": 1, "unit_price": 15000 },
    { "menu_id": "menu6967970655a26", "quantity": 1, "unit_price": 10000 }
  ]
}
```

- Send the **same `items` shape you will send on save** (`menu_id` or `package_id`, `quantity`, `unit_price`). Extra fields such as `sugar_level` / `ice_level` are ignored.
- `company_id` is optional (the token's company is used). If your save call sends `company_id`, send the same one here.
- `transaction_date` is optional. See §3.6. Omit it for normal live sales.

**Response `data` (the parts you need)**

| Field | Use |
|-------|-----|
| `eligible` | `true` if a promo applies right now |
| `subtotal` | Gross total (before discount) |
| `discount_amount` | Total discount (0 if none) |
| `total` | **Amount due**: use this on the payment screen |
| `applied` | `null`, or the promo that applied: `promo_name`, `applications`, `discount_amount`, `next_reward` |
| `lines[]` | Per cart line, in the same order you sent: `subtotal`, `discount_amount`, `net_subtotal` |
| `evaluations[]` | One entry per active promo with `applicable`, `reason`, `next_reward` (for hints) |

Real example: 16.000 + 15.000 non-coffee + 10.000 coffee → `subtotal 41000`, `discount_amount 3000`, `total 38000`. The coffee line has `discount_amount: 0`.

### 3.3 Step 2: what to show

1. **Cart lines** show the normal price. If you want per-line net prices, use `lines[i].net_subtotal`. Line shares are rounded by the server, so don't recompute them.
2. **Discount row** when `applied` is not null: `"{applied.promo_name}: −Rp {discount_amount}"`.
3. **Total** is `total`. Always bind the total and the payment amount to this value, never to your own sum.
4. **Nudge** (the big win for speed at the counter). Both cases below are derived from `next_reward`, which says how many more eligible drinks earn the (next) discount:
   - Promo applied: `applied.next_reward` is `{ items_needed, discount_amount }` when another pair is still possible (it is `null` if there is nothing more to earn). E.g. 2 drinks → "Tambah 2 minuman lagi untuk potongan Rp 3.000 berikutnya"; 3 drinks → "Tambah 1 minuman lagi…".
   - Not applied yet: look through `evaluations` for one with `reason` of `need_more_items` or `no_eligible_items` and a non-null `next_reward`. E.g. 1 eligible drink (`need_more_items`) → `items_needed: 1` → "Tambah 1 minuman non-kopi lagi, potongan Rp 3.000". `no_eligible_items` (cart has no eligible drink, e.g. only coffee) also carries `items_needed: 2`; showing that "buy 2 non-coffee drinks" hint is optional, and you may prefer to show it only once the cart has at least one item.
   - If two promos qualify for a nudge, show the one with the smallest `items_needed`.
5. **Show nothing** about promos when `evaluations` is empty (company has no promos) or every reason is `day_not_allowed`, `outlet_type_not_allowed` or `sale_date_not_today`. Those aren't actionable for the cashier, so no error styling.

```ts
// illustrative
function nudge(check) {
  if (check.applied?.next_reward) return check.applied.next_reward;
  const hints = check.evaluations
    .filter(e => !e.applicable && e.next_reward)
    .map(e => e.next_reward);
  return hints.sort((a, b) => a.items_needed - b.items_needed)[0] ?? null;
}
```

Suggested copy (server `message` is English; localise by `reason` / `next_reward`):

| Situation | Suggested text |
|-----------|----------------|
| Applied | "Promo Monday Bestie Day: hemat Rp 3.000" |
| Nudge | "Tambah {items_needed} minuman lagi untuk potongan Rp {discount_amount}" |
| `below_min_subtotal` | "Belanja minuman promo minimal Rp {x} untuk dapat potongan" (the amount is in `message`) |

### 3.4 Step 3: save the sale

`POST /transaction/index.php` as today, plus `"apply_promo": true`:

```json
{
  "company_id": "company6a2d1c9f4da2b",
  "apply_promo": true,
  "items": [
    { "menu_id": "menu6a541a7dd8d63", "quantity": 1, "unit_price": 16000, "sugar_level": "normal", "ice_level": "normal" },
    { "menu_id": "menu6a157d58bfa89", "quantity": 1, "unit_price": 15000 }
  ],
  "payments": [ { "payment_method": "cash", "amount": 28000 } ]
}
```

- `payments[].amount` must sum to the **discounted** `total` from the check (28.000 here, not 31.000). Split payments are fine as long as they sum to it.
- **Do not send a discount value.** There is no such field. The server derives it.
- Send `apply_promo: true` for every sale from the new build. The server decides whether anything applies. If nothing does, the sale is saved at full price and the response has `promo: null`, so you don't need to branch on role or weekday.

**`201` response**: use these for the receipt and success screen:

| Field | Meaning |
|-------|---------|
| `total_amount` | Net, what the customer paid |
| `gross_amount` | Before discount |
| `discount_amount` | Total discount |
| `promo` | `null` or `{ promo_name, applications, discount_amount, … }` |
| `items[].subtotal` | **Net** line amount |
| `items[].discount_amount` | That line's share of the discount (gross line = `subtotal + discount_amount`) |

Receipt layout: list lines at gross (`subtotal + discount_amount`), then `Subtotal`, `Promo: {promo_name} −{discount_amount}`, `Total`.

### 3.5 Step 4: handle the payment mismatch (`400`)

If the amounts don't match what the server computed (the promo changed, the cart differs from what was checked, a sale straddles midnight), the server returns `400` with the numbers it expects:

```json
{
  "status_code": 400,
  "status_message": "Total payment (sum of all payment_method entries) must equal total_amount. total_paid=31000, total_amount=28000",
  "data": { "gross_amount": 31000, "discount_amount": 3000, "total_amount": 28000, "promo": { "...": "..." } }
}
```

Nothing was saved. Re-render the cart and payment screen from `data` (`total_amount` is the new amount due), tell the cashier the amount changed, and ask them to confirm the payment again. Do not silently auto-retry, because cash may already have been handed over.

### 3.6 The sale date (`transaction_date`)

- **Live sales:** omit `transaction_date` from both the check and the save. The server uses "now" in Jakarta.
- **Entering a sale later the same day** (end-of-day entry): send `transaction_date` with the real sale date, and send the **same value** to the check and the save. The promo applies **only if that date is today in Jakarta**. Cashiers enter sales on the same day, at the latest Monday evening.
- **A date that isn't today gets no discount.** The sale still saves, at full price. This is deliberate, so nobody can back-date a sale onto a Monday. If your UI lets a cashier pick a past date, show that the promo won't apply (`reason: "sale_date_not_today"`).
- Only the date part is stored; the time is ignored.

### 3.7 Rules the UI should be aware of

| Rule | What it means for the cart |
|------|----------------------------|
| Non-coffee only | Coffee and toppings never count and are never discounted. They can share the cart. |
| Pairs | `floor(eligible drinks / 2)` pairs × Rp 3.000. 5 drinks → 2 pairs → Rp 6.000. |
| Mix and match | Different drinks, and the same drink twice, both count. |
| Packages (`package_id`) | Never discounted and don't count toward pairs. |
| Only `Outlet` role | Abang and other roles get no discount. The UI shows nothing extra. |
| Best single promo | If several promos match, only the one with the largest discount applies. They never stack. |

### 3.8 Failure handling (recommendation, product to confirm)

- If `check.php` fails (network/5xx), retry once. If it still fails, show a clear "Promo tidak dapat dicek" state. Let the cashier retry, or **explicitly** continue without the promo (save **without** `apply_promo`). Never silently sell at full price on a Monday, because the customer would lose the discount.
- `check.php` is only a preview. A failed check never blocks the save call, and a successful check never guarantees it. Always handle the `400` in §3.5.

---

## 4. Dashboard implementation (Owner)

Add a **Promo** menu item. Owners manage their company's promos; other roles either don't see the page or see it read-only. Write endpoints return `403` for anyone but `Owner`.

An Owner with several outlets has a separate login per outlet (one company each), so promos are managed per login. A promo created in one outlet does **not** appear in another.

### 4.1 List page: `GET /promo/promo.php`

Query: `page`, `limit` (≤ 100), `is_active` (`0`/`1`), `search` (name). Response: `data.data[]` plus `data.pagination { total, page, limit, total_pages }`.

Suggested columns: **Name**, **Discount** (e.g. "Beli 2 hemat Rp 3.000"), **Days**, **Applies to** (categories/menus), **Outlet type**, **Status** (toggle), actions.

Render helpers: `days_of_week` are ISO numbers (`1` = Monday … `7` = Sunday). An empty array means **every day**. Empty `category_ids` **and** `menu_ids` means **all menus**. Empty `outlet_types` means **any role**.

### 4.2 Create / edit form

| Form field | API field | Notes |
|------------|-----------|-------|
| Promo name | `promo_name` | Required, max 255 |
| Buy quantity | `buy_quantity` | Required, integer ≥ 1 (Monday Bestie Day = 2) |
| Discount (Rp) | `discount_amount` | Required, integer ≥ 1. Amount off per group of `buy_quantity` (Monday Bestie Day = 3000) |
| Active | `is_active` | Toggle, default on |
| Days | `days_of_week` | Mon–Sun chips → `[1..7]`. None selected = every day. **Warn the user** ("berlaku setiap hari") when none are selected |
| Categories | `category_ids` | Multi-select from `GET /menu/category.php` |
| Specific menus | `menu_ids` | Multi-select from `GET /menu/menu.php`. A menu qualifies if it's in a chosen category **or** listed here |
| Excluded menus | `exclude_menu_ids` | Never eligible, wins over categories/menus. Handy for "all non-coffee except X" |
| Outlet types | `outlet_types` | RAKI role names: `Outlet`, `Abang`, `Mitra/Franchise`, `Owner`, `Customer`. Offer the sensible ones (`Outlet`, `Abang`, `Mitra/Franchise`) |
| Max pairs per sale (optional) | `max_applications` | Blank = unlimited. **Monday Bestie Day: leave blank** |
| Min / max item price (optional) | `min_item_price`, `max_item_price` | Only drinks priced within range qualify |
| Min eligible spend (optional) | `min_eligible_subtotal` | Eligible drinks must total at least this |

Put the optional price and cap fields under an "Advanced" section. Include a **"Monday Bestie Day" preset** that fills:

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

(`category6907fb386e005` is the `non-coffee` category. Look it up by name from `GET /menu/category.php` rather than hard-coding it if you can.)

### 4.3 Save semantics

- **Create:** `POST /promo/promo.php` → `201`, body is the created promo.
- **Edit:** `PUT /promo/promo.php` with `promo_id` and **only the changed fields**. Returns the updated promo.
  - A condition array that is present **replaces** that whole dimension. Send the **full** new list, not a delta. `[]` clears it.
  - `null` clears a nullable field (`max_applications`, `min_item_price`, `max_item_price`, `min_eligible_subtotal`).
  - Omit a field to leave it unchanged.
- **Switch on/off:** `PUT { promo_id, is_active: 0 | 1 }`. Prefer this over deleting so history stays visible.
- **Delete:** `DELETE /promo/promo.php?promo_id=…` → `200`. Confirm first. Sales already discounted keep their record.
- Show `status_message` on any `400` (e.g. unknown category, day outside 1–7, min price above max price).

### 4.4 Effect on existing reports

Discounted sales are stored net, so **revenue, best-seller revenue and cash reconciliation already reflect the discount** with no dashboard change. Nothing to adjust there.

---

## 5. Testing checklist

Use `raki_dev`. Today may not be a Monday, so for testing **create a promo for today's weekday** with the Owner login (e.g. `days_of_week: [6]` on a Saturday) and an Outlet cashier login for the same company.

| # | Scenario | Expected |
|---|----------|----------|
| 1 | 2 different non-coffee drinks | `eligible: true`, discount 3.000, `total = subtotal − 3000` |
| 2 | 1 non-coffee drink | Not eligible; nudge "add 1 more" from `next_reward.items_needed = 1` |
| 3 | 2 coffee drinks | Not eligible; `reason: "no_eligible_items"` with `next_reward.items_needed = 2` (an optional "buy 2 non-coffee drinks" hint; product decides whether to show it on a cart with no eligible drink) |
| 4 | 2 non-coffee + 1 coffee | Discount 3.000; coffee line `discount_amount: 0` |
| 5 | 4 and 5 non-coffee drinks | 6.000 in both cases; nudge for the 5th case says 1 more |
| 6 | Same drink ×2 on one line, or on two lines | Counts as a pair |
| 7 | A package plus 1 drink | No discount |
| 8 | Login as Abang / non-Outlet | No discount; no promo UI noise |
| 9 | Save with `apply_promo: true` and net payment | `201`; `total_amount` net; `discount_amount` / `promo` present |
| 10 | Save with `apply_promo: true` but the **gross** payment | `400` with expected totals in `data`; nothing saved |
| 11 | Save **without** `apply_promo` | Behaves as before (full price, no new fields) |
| 12 | Pass a past `transaction_date` | No discount; sale saves at full price |
| 13 | Company with no promos | `evaluations: []`; no promo UI |
| 14 | Split payment (cash + QRIS) summing to net total | `201` |
| 15 | Dashboard: create, edit (change days), switch off, delete | List reflects each change; a switched-off promo stops applying at the POS |
| 16 | Dashboard as a non-Owner | Create/edit/delete blocked (`403`) |

Real Monday check on `raki` (production): with the promo configured, a Monday Outlet sale of 2 drinks should show Rp 3.000 off in the preview and save with `discount_amount = 3000`.

---

## 6. Rollout order

1. **Backend and DB are done.**
2. Owners create the Monday promo (dashboard Promo page or API), **once per outlet**, with `max_applications` blank.
3. Ship the POS build that calls `check.php` and sends `apply_promo: true`. Old builds keep selling at full price with no errors.
4. Verify on the first Monday: preview matches the saved total, and `transaction.discount_amount` is set.

---

## 7. Known limitations (backend follow-ups if you need them)

- **Transaction history / detail** (`GET /transaction/index.php`) doesn't return the discount breakdown yet. It returns `total_amount` (net) only. The receipt for a **new** sale can be built from the `201` response. Reprinting an old sale from history shows net amounts without the promo line. Tell us if you need this and we'll extend the GET.
- Only "buy N, get Rp X off" exists today. Percentage or free-item promos would be a backend addition.
- No date range (start/end) on promos yet. Switch a promo off with `is_active` when a campaign ends.
- The server messages (`message`, `status_message`) are English. Localise by `reason` code.
