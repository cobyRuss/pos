# POS System

A point-of-sale and inventory management application built with Laravel 12, Bootstrap 5 and vanilla JavaScript. It covers the till, the product catalogue, stock movements, refunds, sales reporting, user roles and an audit trail.

## Features

- **Till** - name/description search, a cart that totals itself the instant a product is clicked, quantity edits, cash and GCash payment, change calculation and printable receipts. There are no promo or discount controls: what the cashier rings up is what the customer pays, plus tax.
- **Orders** - searchable history with status and payment filters. Staff only ever see their own sales; admins see everything.
- **Inventory** - stock-in, stock-out and absolute adjustments, a filterable movement log, low-stock and out-of-stock alerts, CSV export.
- **Batch expiry dates** - stock is held as delivery lots, each with its own expiration date and optional batch number. Sales draw first-expiry-first-out and never touch a lapsed lot; cancelling or refunding puts units back on the exact lots they left. Expired and expiring-soon warnings appear on the till, the inventory list, the product page, the dashboard and the reports.
- **Refunds** - cashiers process them, because the administrator is never on the till. Item-locked, tax-inclusive, non-repeatable, and always attributed to the person who pressed the button. See [Refunds](#refunds) below.
- **Cash reconciliation** - what each cashier's takings should have been, minus what left again as refunds. This is the report that catches a short drawer. CSV export included.
- **Owner alerting** - every refund raises a Telegram message to the owner with the cashier, order, reason and amount. Non-blocking, and its delivery is tracked so a failure is visible rather than silent.
- **Cancellations** - order cancellation with automatic restock, admin-only.
- **Reports** - sales, revenue and inventory reports with date ranges, previous-period comparison, per-day/payment-method/cashier breakdowns and CSV exports.
- **Administration** - product and category CRUD, product photos, staff accounts, store settings (currency, tax rate, receipt footer) and a searchable audit log.
- **Local time** - every timestamp is stored and printed in the store's own timezone (`APP_TIMEZONE`), so receipts, reports and the dashboard show the wall-clock time a sale actually happened.
- **Roles** - `admin` and `staff`, enforced by the `role:admin` middleware, which records every denied attempt.
- **Profit tracking** - the dashboard breaks down every product by cost price, selling price, quantity and total profit (`(selling - cost) x quantity`), with total cost, total sales, profit margin and a red loss treatment for anything sold below cost. Two views are shown: what the selected period actually earned, and what the current stock would earn.

## Requirements

- PHP 8.2+ (developed against XAMPP PHP)
- Composer 2
- MySQL 5.7+ or MariaDB 10.3+ (the test suite runs on SQLite)
- Node.js and Vite are not used. Styles come from `public/css/app.css` plus the Bootstrap 5 CDN, and each page's JavaScript lives inline in its Blade template. The untouched `resources/js`, `resources/css`, `vite.config.js` and `package.json` files are Laravel skeleton leftovers and are unreferenced.

## Installation

```bash
# 1. PHP dependencies
composer install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Point .env at your database
#    DB_CONNECTION=mysql
#    DB_HOST=127.0.0.1
#    DB_PORT=3306
#    DB_DATABASE=pos_system
#    DB_USERNAME=root
#    DB_PASSWORD=
#
# 4. Set the store's timezone so receipts and reports show local time
#    APP_TIMEZONE=Asia/Manila

# 5. Create the schema and demo data
php artisan migrate:fresh --seed

# 6. Expose uploaded product images over HTTP
php artisan storage:link
```

Serve it from the XAMPP document root (`http://localhost/Pos`) or run:

```bash
php artisan serve
```

## Demo accounts

All seeded accounts use the password `password`.

| Role  | Email            | Landing page    |
|-------|------------------|-----------------|
| Admin | `admin@pos.test` | Dashboard       |
| Staff | `alice@pos.test` | Till            |
| Staff | `bruno@pos.test` | Till            |
| Staff | `carla@pos.test` | Till (inactive) |

The seeders create 25 products across 6 categories, about 30 days of demo sales, and a restocking pass that leaves a few low-stock items on the dashboard.

## Roles

| Capability                        | Admin | Staff |
|-----------------------------------|:-----:|:-----:|
| Sell, search, print receipts      |  yes  |  yes  |
| View own sales history            |  yes  |  yes  |
| View all sales history            |  yes  |  no   |
| Browse products and stock levels  |  yes  |  yes  |
| Issue a refund on any order       |  yes  |  yes  |
| See the full refund ledger        |  yes  |  no   |
| Reconcile the drawer              |  yes  |  no   |
| Manage products and categories    |  yes  |  no   |
| Adjust stock, log movements       |  yes  |  no   |
| Cancel orders                     |  yes  |  no   |
| Reports, users, settings, logs    |  yes  |  no   |

Refunds are open to staff because the administrator is never on the till - see [Refunds](#refunds). Everything that *reads* the money stays with the owner, and the daily refund ceiling does not apply to the admin.

The last active administrator cannot be demoted, deactivated or deleted, and an admin cannot delete their own account.

## Payment

The store takes exactly two methods, `App\Enums\PaymentMethod`:

| Value    | Label              |
|----------|--------------------|
| `cash`   | Cash               |
| `gcash`  | GCash (E-Wallet)   |

The same two values drive the till's payment select, the refund method select, the order and report filters, and the `orders.payment_method` / `refunds.method` enum columns. The `000903` migration narrows those columns from the original four-value enum; historic rows are folded forward rather than dropped, because the `PaymentMethod` cast throws on a value it does not recognise and that would take the orders list, receipts and reports down with it. The old `mobile` bucket becomes `gcash`, and `card` / `other` become `cash`.

## The till has no promos

There is no discount input and no Apply button. Clicking a product posts to `pos/cart`, and the response carries the new `totals`, which the page writes straight into the footer (`#cart-subtotal`, `#cart-tax`, `#cart-total`) and into the checkout modal's Amount Due. The cart is therefore always `subtotal + tax`.

Only the line-item panel is re-rendered from HTML. The totals panel is deliberately **not** swapped: it holds the "Take Payment" button, whose click handler is bound once at page load, so replacing that panel silently kills checkout. `CheckoutTest::test_the_till_offers_no_promos_and_totals_subtotal_plus_tax` guards the route, the button and the payload shape.

The `orders` discount columns and the `DiscountType` enum still exist because historic orders and the reports read them. New orders are written with `discount_type = fixed`, `discount_value = 0`, `discount_amount = 0`.

## Authentication

The login form is email and password only. There is no "Keep me signed in" checkbox, and `LoginRequest` calls `Auth::attempt()` without a remember flag, so a hand-crafted `remember=1` cannot re-enable it. Sessions simply expire after `SESSION_LIFETIME`.

## Refunds

This store is run by people the owner trusts personally, and the owner is not on the premises. That shapes the whole design: **nothing tries to stop a determined cashier** - a determined cashier can always type a plausible story. What the system does instead is make the dishonest path expensive, and the honest path frictionless.

That is why refunds moved out from behind `role:admin`. An admin-only refund screen in a store where the admin never rings up a sale is not a security control, it is a broken feature: the customer stands at the counter and there is nobody who can help.

### What a cashier can and cannot do

| Rule | Why |
|------|-----|
| The amount is **never accepted from the request** | It is derived from the purchased lines, tax included. There is no way to "refund" a peso amount that was never charged. |
| Lines must belong to **that order** | A line id from another receipt resolves to nothing and is refused. A refund cannot be assembled out of two receipts. |
| Each unit can be returned **once** | `order_items.refunded_quantity` is re-checked inside the transaction while holding a row lock. A second attempt blocks on the lock, re-reads the incremented counter, and fails. |
| A **double-clicked submit pays out once** | The form mints an idempotency key; the column is globally unique, so a replay collapses onto the refund that already exists. |
| A known reason needs **no written excuse**, `other` **does** | Keeps the report comparable without forcing a sentence to pick "damaged item". |
| **Cashier daily ceiling** (setting, `0` = off) | The backstop for the failure the CCTV cannot see: a slow drip to a friend. Per cashier, per calendar day. The owner is exempt. |
| Refunds **above the threshold still complete** | The owner may be asleep. A refund that waits for a manager is a customer with no answer, so above the threshold it is flagged, not blocked. |
| Cashiers can only **see their own** refunds | They need their own slip. Another cashier's payouts are the owner's business. |

The refund listing, the reconciliation report and the review action stay **admin-only**. Reading that money is the owner's job, not the cashier's.

### Tax is returned too

A refund used to be priced from `line_total`, which is pre-tax, so a 100% refund left a permanent sliver on the order and `net_total` never reached zero. `order_items.tax_amount` is now frozen at sale time — allocated in proportion to `line_total`, with the last line absorbing the rounding remainder — and a refund pays back `line_total + tax_amount`.

A line cleared in one go is paid from its exact remaining balance, and a line cleared in instalments subtracts what has already gone back. Together those guarantee that returning every unit returns **exactly** `orders.total`, to the cent, and `sum(refunds.tax_amount)` equals `orders.tax_amount`.

Because the tax is frozen on the line, changing `tax_rate` tomorrow cannot rewrite what a refund pays out today — the same property the codebase already relies on for `unit_cost`.

### Cash reconciliation instead of shift sessions

Shifts here are ad hoc and nobody opens a till session, so a `shift_sessions` table would be ceremony with no meaning. There is also no counted-drawer figure to compare against. So **Reports → Cash Reconciliation** shows, per cashier and per day:

cash taken · cash paid back out · **expected in drawer** · refund count · refunded total · refund rate · flagged refunds

Count the drawer and any difference is the number to investigate, then match the timestamp against the CCTV. A cashier whose refund rate trends upward, or who keeps filing `duplicate_sale` / `price_adjustment`, is visible in one glance.

### Telegram alerting

`RefundProcessed` fires **after** commit, so an alert can never describe a rolled-back refund. The queued listener never blocks the till: a 5-second timeout, every failure swallowed into the refund's `refund_notifications` row.

The outstanding notification row is written **before** the job is dispatched, which is the important detail — a stopped queue worker shows up as "2 alerts never reached your phone" on the refunds page instead of vanishing.

```bash
php artisan refund:retry-notifications           # resend failures
php artisan refund:retry-notifications --pending # include anything still queued
php artisan queue:work                            # required, or alerts stay pending
```

Token and chat id live in **Settings → Integrations** (`telegram_bot_token`, `telegram_chat_id`).

### Concurrency

Lock rows in a **fixed order** — `order_items` sorted by id, then product lots in expiry order — so a refund can never deadlock against a concurrent refund or a checkout wanting the same products. The transaction retries three times on a deadlock; the work is idempotent under the locks, so retrying is safe.

## Architecture notes

| Area            | Where                                                                                  |
|-----------------|----------------------------------------------------------------------------------------|
| Checkout        | `app/Services/OrderService.php` - tax, totals, stock decrement in a transaction |
| Stock           | `app/Services/InventoryService.php` - every quantity change writes an `inventory_movements` row |
| Batch expiry    | `app/Models/ProductBatch.php` + `InventoryService` - lots, FEFO sale allocation, exact-lot returns |
| Refunds         | `app/Services/RefundService.php` - item-locked, tax-inclusive, idempotent, order status roll-up |
| Refund alerting | `App\Events\RefundProcessed` → `App\Listeners\SendRefundAlert` → `app/Services/RefundNotifier.php` |
| Reconciliation  | `ReportController::refunds()` - per-cashier expected drawer, `reports/refunds` |
| Authorisation   | `App\Http\Middleware\EnsureUserHasRole` + per-order checks in `OrderController`          |
| Audit trail     | `App\Support\AuditLogger` - `audit_logs` rows for sensitive actions and denied access   |
| Settings        | `App\Models\Setting` - cached key/value store for currency, tax rate and receipt options |
| Driver SQL      | `App\Support\SqlDate` - date bucketing that works on both MySQL and SQLite               |

Stock is never written directly from a controller: it always goes through `InventoryService`, which records before/after values and the responsible user.

The buying price is frozen onto each sold line (`order_items.unit_cost`) at the moment of sale, so editing a product's cost later never rewrites historic profit. Reports and the dashboard's "sold" view use that frozen figure; the dashboard's "current stock" view uses today's prices.

### How stock and expiry fit together

Stock is **not** a single number on the product any more. It lives in `product_batches`, one row per delivery:

| Table                 | Holds                                                                     |
|-----------------------|---------------------------------------------------------------------------|
| `product_batches`     | `product_id`, `batch_no`, `expiry_date`, `quantity` - the actual stock     |
| `products.stock`      | a **cached sum** of the lots above, written only by `InventoryService`     |
| `order_item_batches`  | which lot each sold line came from and how much of it is still out        |
| `inventory_movements` | `batch_id` plus an `expiry_date` snapshot, so the log stands on its own    |

Three rules govern movement, all enforced in `InventoryService`:

1. **Sales are first-expiry-first-out.** A decrease with no lot named is spread across the sellable lots in expiry order. Undated lots sort last, so they are only used once every dated lot is gone. Expired lots are skipped entirely, and a lot is good *through* its expiry date.
2. **Expired stock cannot be sold.** It is still counted in `products.stock` (it is physically on the shelf) but never in `sellableStock()`. The till refuses to add a wholly-expired product to the cart, and checkout rejects any line that exceeds the sellable total.
3. **Returns go back where they came from.** `order_item_batches` records the exact split of every sale, so a cancellation or a partial refund unwinds the consumption order rather than guessing a lot. Units whose lot has since been deleted fall back to the undated general bucket.

Because `products.stock` is a cached sum it could in principle drift, so `BatchExpiryTest` asserts `stock === SUM(lots)` after every kind of movement, and the `000900` migration adopts any pre-existing flat stock as one undated lot.

Receiving stock requires an expiry date (or an explicit existing lot) so a delivery can never be recorded in a way that makes it impossible to flag later. Writing off lapsed goods is a stock-out against a named lot, which is why the adjust screen lets you pick one.

## Testing

The suite uses in-memory SQLite and `RefreshDatabase`:

```bash
php artisan test
```

240 feature tests / 1285 assertions cover login, the till and checkout, stock adjustments, batch expiry and FEFO allocation, refunds and their guardrails, cancellations, catalogue CRUD, reports and exports, settings, user management, role enforcement and page rendering.

`RefundGuardrailTest` is the one to read first if you touch refunds: one test per way the feature could be abused, and the tax-inclusive tests use deliberately awkward prices (`3.33` at `12%`) because rounding is where refund maths usually goes wrong.

To exercise the same suite against MySQL (recommended after touching raw SQL):

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS pos_system_test"
DB_CONNECTION=mysql DB_DATABASE=pos_system_test php artisan test
```

## Useful commands

```bash
php artisan migrate:fresh --seed      # rebuild the demo database
php artisan view:cache                # pre-compile Blade templates
php artisan route:list                # review the application routes
php artisan queue:work                # deliver refund alerts to the owner
php artisan refund:retry-notifications  # resend alerts that failed
```
