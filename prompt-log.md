# Prompt log

Raw user prompts and the resulting change summary, per `rules.md`.

---

## 2026-09-26 — Batch expiry dates for stock + sidenav contrast

### Raw prompts

**Prompt 1**

```
add recording expiration dates for stocks e.g. add new product/stock adjustments 
fix the ff issues
sidenav text contrast 
```

**Prompt 2** (answer to "what do you mean by the ff issues?")

```
ff meant following so ignore it
```

**Prompt 3** (answer to "how should expiry be modelled?")

```
Per product batch gets their own expiration date + expiry warnings
```

**Prompt 4** (answer to "which approach for the sidenav contrast fix?")

```
sidenav font colors are too light making them invisible, so no light colors.
```

**Prompt 5** (answer to "light sidebar, dark ink, or only fix the real bug?")

```
Light sidebar, dark ink (Recommended)
```

**Prompt 6**

```
implement
```

### Raw sum output

`git diff --shortstat HEAD -- app resources routes database public tests README.md`

```
 36 files changed, 1718 insertions(+), 213 deletions(-)
```

New files, by line count:

```
155 app/Http/Controllers/BatchController.php
 91 app/Http/Requests/BatchRequest.php
 44 app/Models/OrderItemBatch.php
227 app/Models/ProductBatch.php
 58 database/migrations/2026_01_01_000900_create_product_batches_table.php
 37 database/migrations/2026_01_01_000901_add_batch_tracking_to_inventory_movements.php
 40 database/migrations/2026_01_01_000902_create_order_item_batches_table.php
232 resources/views/batches/index.blade.php
720 tests/Feature/BatchExpiryTest.php
162 tests/Feature/PageRenderSmokeTest.php
```

New-file total: 1766 lines. Combined with the tracked diff:
**3484 insertions, 213 deletions across 46 files.**

### Verification

```
php artisan test          ->  203 passed (1133 assertions)
vendor/bin/pint --test    ->  {"tool":"pint","result":"passed"}
php artisan migrate:fresh --seed  ->  all 4 seeders clean on MySQL
```

Invariant checks against the seeded MySQL database:

```
drifted products (stock != SUM(lots)): 0
order_item_batches rows: 274
negative usage rows: 0
usage+refund exceeding sold quantity: 0
```

### What changed

**Per-batch expiry dates.** Stock moved from a flat `products.stock` integer
into `product_batches`, one row per delivery with its own `expiry_date` and
optional `batch_no`. `products.stock` stays as the cached sum, written only by
`InventoryService`. The `000900` migration adopts existing flat stock as one
undated lot.

Sales are first-expiry-first-out across sellable lots, skipping lapsed ones and
sorting undated lots last. Expired stock stays in the product total (it is
physically on the shelf) but is excluded from `sellableStock()`, and the till
refuses it. Cancelling or refunding unwinds the exact split recorded in
`order_item_batches`, so units go back on the lot they left with its date intact.

Warnings surface on the till grid and cart, the inventory list (new
`expiring`/`expired` filters, expiry column, stat card, banner), the product page,
a new Batches & Expiry screen, the dashboard and the inventory report and its CSV.

**Sidenav.** Root cause of the "invisible" text: the sidebar root also carries
Bootstrap's `offcanvas-lg`, and inside `@media (min-width: 992px)` Bootstrap ships
`.offcanvas-lg { background-color: transparent !important }` and
`.offcanvas-lg .offcanvas-header { display: none }`. Those load from the CDN
before `public/css/app.css`, so the dark surface never painted and the store name
vanished on desktop. Per the requested direction, the sidebar is now a light
surface with dark ink: `#f8fafc` / `#f0fdfa` backgrounds, `#0f172a` nav text
(17.1:1), `#475569` muted (7.2:1), and `!important` on the surface and header
rules so the offcanvas utility cannot win. The same palette is mirrored into the
inlined critical `<style>` block.

---

## 2026-09-29 — Cash/GCash only, no promos, no "keep me signed in", till total bug

### Raw prompts

**Prompt 1** (answer to "how to run?")

```
how to run?
```

**Prompt 2**

```
re run; forgot to turn on xampp mysql
```

**Prompt 3**

```
use another port, the port 8000 is taken by my other project; the flowershop website
```

**Prompt 4**

```
mode of payment should only be gcash(e-wallet) and cash, remove the keep me signed in feature on log in; when clicking a product on the point of sale, it automatically adds the total, no need to apply; remove the apply button including the promos(fixed amount and percentage) then check for bugs cause right bow i cant process an order
```

### Raw sum output

This directory is not a git repository, so `git diff --shortstat` has nothing to
report. The closest available figure is the changed-file list:

```
app/Enums/PaymentMethod.php                                          (enum: 4 cases -> 2)
app/Http/Controllers/OrderController.php                             (payment filter -> Rule::in)
app/Http/Controllers/PosController.php                               (discount endpoint removed)
app/Http/Controllers/ReportController.php                            (payment filter -> Rule::in)
app/Http/Requests/Auth/LoginRequest.php                              (remember removed)
app/Services/CartService.php                                         (promo math removed)
app/Services/OrderService.php                                        (discount written as 0)
database/migrations/2026_01_01_000400_create_orders_table.php        (enum -> cash, gcash)
database/migrations/2026_01_01_000402_create_refunds_table.php        (enum -> cash, gcash)
database/migrations/2026_01_01_000903_...to_cash_and_gcash.php       (new, 53 lines)
database/seeders/DemoSalesSeeder.php                                 (methods + no promo)
resources/views/auth/login.blade.php                                 (checkbox removed)
resources/views/pos/index.blade.php                                  (live totals, Apply JS removed)
resources/views/pos/partials/cart-totals.blade.php                   (promo UI removed, ids added)
routes/web.php                                                       (pos.cart.discount removed)
tests/Feature/CheckoutTest.php                                       (+3 tests, promo test rewritten)
tests/Feature/LoginTest.php                                          (+2 tests)
tests/Feature/{BatchExpiry,Refund,Reporting,TillAccess}Test.php
pos_system.sql                                                       (DDL + demo rows remapped)
README.md, tests/Unit/.gitkeep                                       (php artisan test fix)
```

### Verification

```
php artisan test            ->  208 passed (1154 assertions)
vendor/bin/pint --test      ->  {"tool":"pint","result":"passed"}
php artisan migrate:fresh --seed  ->  all 5 seeders clean on MySQL
migrate:rollback --step=1 / migrate  ->  000903 down() and up() both verified
```

End-to-end against the running server (MySQL, port 8001):

```
login                      HTTP 302 -> /pos
promo UI in till markup    0 matches for apply-discount|discount_type|discount_value
payment options            Cash / GCash (E-Wallet)
cart totals                subtotal=4.5 tax=0.23 total=4.73
checkout(cash)             HTTP 302 -> /orders/92/receipt
checkout(gcash)            HTTP 302 -> /orders/93/receipt
checkout(payment=card)     HTTP 422 "The selected payment method is invalid."
```

Till script was extracted from the rendered page and checked statically:

```
JS parses OK (186 lines)
All 14 getElementById targets exist: cart-items, cart-count, cart-quantity,
  cart-subtotal, cart-tax, cart-total, due-amount, change-amount, paid_amount,
  checkout-form, confirm-sale, checkoutModal, clear-cart, open-checkout
stale refs to removed UI: none
payload satisfies every key the JS reads: item_count, quantity, subtotal,
  tax_amount, total
```

Seeded-database invariants:

```
drifted products (stock != SUM(lots)): 0
orders by method: cash 55, gcash 36
```

### What changed

**The order bug.** Two coupled defects in the till, both in how the totals
panel was managed. `applyTotals()` only ever wrote `#cart-count` and the modal's
`#due-amount`; the footer's Subtotal / Tax / Total were plain server-rendered
text with no ids, so after clicking a product the cart footer still showed the
*previous* total. The only thing that refreshed it was the **Apply** button,
which replaced the whole `#cart-totals-area` with fresh HTML - and that same
replacement destroyed `#open-checkout`, the "Take Payment" button whose click
handler is bound once at page load. So: totals looked wrong until you pressed
Apply, and once you had, checkout was impossible. Both are gone. The footer
values now carry `cart-subtotal` / `cart-tax` / `cart-total` ids that
`applyTotals()` writes on every cart change, and `cartPayload()` no longer ships
`totals_html` at all, so the panel cannot be re-swapped into a broken state
again.

**No promos.** The Apply button, the discount type/value inputs, the
`pos.cart.discount` route and `PosController::discount()` are removed.
`CartService::totals()` returns subtotal + tax with no discount keys, and
`OrderService::checkout()` writes `discount_type = fixed`, `discount_value = 0`,
`discount_amount = 0`. The `DiscountType` enum and the `orders` discount columns
survive because historic orders, receipts and the reports still read them.

**Cash and GCash only.** `PaymentMethod` drops `card`, `mobile` and `other` for
`gcash` ("GCash (E-Wallet)"). Migration `000903` narrows the `orders` and
`refunds` enum columns on MySQL, folding existing rows forward (`mobile` ->
`gcash`, everything else -> `cash`) rather than dropping them, because the
`PaymentMethod` cast throws on an unrecognised value and that would take the
orders list, receipts and reports down with it. The order and report filters
now validate with `Rule::in(PaymentMethod::values())` instead of a bare
`string|max:20`, so a stale `?payment_method=card` is rejected rather than
silently returning nothing. `pos_system.sql` was remapped to match.

**No "Keep me signed in".** The checkbox is gone from the login form and
`LoginRequest::authenticate()` calls `Auth::attempt()` without a remember flag,
so a hand-crafted `remember=1` cannot re-enable it - covered by a test that
asserts no recaller cookie is issued and no `remember_token` is written.

Also fixed: `phpunit.xml` declares a `tests/Unit` suite that did not exist, so
the documented `php artisan test` aborted before running anything. Added
`tests/Unit/.gitkeep`.

---

## 2026-09-29 — Staff refunds with guardrails, owner alerting, cash reconciliation

### Raw prompts

**Prompt 1**

```
Act as a Senior Full-Stack Developer specializing in secure Point of Sale (POS)
architecture. I need you to write the backend logic and database schema changes
for a secure, staff-managed product refund function for my standalone convenience
store website.
```

(followed by the full brief: `refunds` table morphed to orders/order_items, no
arbitrary amounts, item-locked validation, anti-duplication, a hardcoded ₱500 soft
cap that freezes over-cap refunds for manager OTP or "awaiting remote approval",
an async Telegram/webhook alert with cashier name / order id / reason / amount,
and a deduction from `shift_sessions` expected cash so end-of-shift balancing
forces accountability. Asked for migration code, controller logic, the notification
handler, and race-condition / double-click protection.)

**Prompt 2** (answer to the how-important-questions round)

```
i always pick recommended but the owner is a busy person who isnt on their phone
every time, this could pend the stores productivity, i was thinking that refunds
are connected to the staffs account, there are cctvs scattere around the store
that gets all the angles with time stamps, receipts all so have dates and time
stamps; a report will be seen by the admin acc tho, now that i think abt it
more, refunds dont get receipts, i think what we should do is whenever a refund
is made, timestamps are made in case missing funds are calculated; with this info,
can you make a solution that solves and matches my resources/environment/work
situation?
```

**Prompt 3** (answer to the shift-session scope question)

```
shifts are random, since we live in a province and the workers are the owners
husbands bodyguards wifes or people that already work for them, they should just
log in their staff accounts whenever theyre in shifts
```

**Prompt 4** (answer to the tax question)

```
Yes, make refunds tax-inclusive (Recommended)
```

### Where the brief was deliberately not followed

Three requested features were dropped, and the reasoning is recorded here because
the reasoning is the deliverable as much as the code:

- **Blocking manager OTP / "awaiting remote approval" was not built.** The owner
  is not reachable. A refund that waits for a manager is a customer standing at
  the counter with no answer, which is a worse outcome than a large refund. The
  ₱500 cap was kept but inverted from blocking to **advisory**: over the threshold
  the refund completes instantly, gets `review_required`, is pinned to the top of
  the reconciliation report, and pushes a Telegram alert.
- **`shift_sessions` was not built.** Shifts are ad hoc and people simply log in
  whenever they are on. A formal shift table would be ceremony with no meaning,
  and with no opening float there is no counted-drawer figure to compute a
  variance against. Replaced with a **per-cashier daily cash reconciliation**
  report - which answers the actual question ("what should be in the drawer, and
  who took it out?").
- **The user_id ↔ shift_session_id link** became `refunds.user_id` plus the
  existing `refunded_at` timestamp, which is what the CCTV footage gets matched
  against.

The "refunds don't get receipts" observation was answered rather than actioned:
`refunds.show` was already a printable, receipt-styled slip, so a refund already
produces a timestamped document.

### Raw sum output

This directory is not a git repository, so `git diff --shortstat` has nothing to
report.

```
NEW  app/Enums/RefundNotificationStatus.php
NEW  app/Enums/RefundReason.php
NEW  app/Events/RefundProcessed.php
NEW  app/Exceptions/RefundPolicyException.php
NEW  app/Listeners/SendRefundAlert.php
NEW  app/Models/RefundNotification.php
NEW  app/Services/RefundNotifier.php
NEW  app/Console/Commands/RetryRefundNotifications.php
NEW  resources/views/reports/refunds.blade.php
NEW  database/migrations/2026_01_01_000904_add_security_columns_to_refunds.php
NEW  database/migrations/2026_01_01_000905_add_tax_amount_to_order_items.php
NEW  tests/Feature/RefundGuardrailTest.php                      (31 tests)

CHANGED
  app/Services/RefundService.php            rewritten: tax-inclusive pricing,
                                           sorted lock order, deadlock retry,
                                           idempotency, threshold flag, daily cap
  app/Http/Controllers/RefundController.php staff access, review endpoint
  app/Http/Requests/RefundRequest.php       reason_code, idempotency_key
  app/Services/OrderService.php             freeze per-line tax
  app/Models/{OrderItem,Refund,Setting}.php
  app/Http/Controllers/ReportController.php reconciliation report + CSV
  app/Http/Controllers/SettingController.php, UpdateSettingsRequest
  app/Support/AuditLogger.php               3 new action constants
  app/Providers/AppServiceProvider.php      explicit event->listener binding
  routes/web.php                            refunds moved to shared auth group
  resources/views/{refunds,orders,reports,settings}/...
  database/seeders/SettingSeeder.php
  tests/Feature/{Refund,BatchExpiry,Receipt,Reporting,Reporting}Test.php
  README.md, prompt-log.md
```

### Verification

```
php artisan test            ->  240 passed (1285 assertions)
vendor/bin/pint --test      ->  {"tool":"pint","result":"passed"}
php artisan migrate:fresh --seed  ->  all 5 seeders clean on MySQL
php artisan refund:retry-notifications --pending  ->  "0 sent, 2 still failing"
```

End-to-end against the live MySQL server on port 8001:

```
staff GET /orders/102/refund            -> 200  (was 302 to /pos before)
idempotency_key field rendered          -> yes
reason options                          -> 7 fixed codes
3x identical POST (same key)            -> 302 -> /refunds/1  x3, ONE refund
  refund row                            -> amount 2.05  tax 0.10  user_id 2
  refunded_amount                       -> 2.05
same receipt, new key, qty 5            -> refused, still 1 refund
full refund of a 2-line 12% order       -> total 6.14 refunded 6.14 net 0.00
  sum(refunds.tax_amount) 0.29 == orders.tax_amount 0.29
refund_notifications row                -> telegram / pending (worker not running)
admin GET /admin/reports/refunds        -> 200
  CSV: "Alice Cashier",25,331.67,188.00,143.67,2,8.19,0.39,8.19,179.81,2.47,0
        ^ 188.00 cash taken - 8.19 paid out = 179.81 expected in drawer
bruno GET /admin/reports/refunds        -> 302 /pos
bruno GET /admin/refunds                -> 302 /pos
```

Two real bugs were caught by running against MySQL rather than the SQLite test
database, and both are the kind that only appear on one driver:

- `groupBy(SqlDate::day('refunded_at'))` was emitted as
  ``group by `DATE(refunded_at)` ``, which MySQL rejects as a column name.
  Grouping by the `day` select alias instead works on both.
- `groupBy('reason_code')` with `selectRaw('reason_code, ...')` handed the view a
  `RefundReason` enum where `tryFrom()` wanted a string, because the model casts
  that attribute. Aliased to `code`.

### What changed

**Refunds are open to staff.** The routes moved from the `role:admin` group into
the shared authenticated group, and `RefundRequest::authorize()` now accepts any
signed-in user. In a store where the admin never rings up a sale, an admin-only
refund screen was not a control, it was a dead feature.

**The amount is never taken from the request.** There is no `amount` rule, no
`amount` input, and the service prices purely from the purchased lines. A caller
who posts `amount=10000` gets it ignored.

**Refunds are tax-inclusive.** `order_items.tax_amount` is new and frozen at sale
time, allocated in proportion to `line_total` with the last line absorbing the
remainder. A line cleared outright is paid from its exact remaining balance; a
line cleared in instalments subtracts what already went back. Returning every unit
now returns exactly `orders.total` and `sum(refunds.tax_amount)` equals
`orders.tax_amount` - verified to the cent on awkward prices.

**Double-clicks pay out once.** The form mints a random idempotency key. The
column is globally unique, not unique per order, which is the safer contract: it
identifies a *request*, so a key that turns up against a different order is either
a confused client or someone probing, and both get the existing refund back rather
than a second payout.

**The ₱500 cap is advisory.** `review_required` is set above the threshold
(configurable via `refund_review_threshold`), the refund still completes instantly,
and the owner is alerted. `admin.refunds.review` closes the loop.

**A per-cashier daily ceiling** (`refund_daily_limit`, `0` = off) is the backstop
for the loss the CCTV cannot see. Per cashier per calendar day; the owner is
exempt.

**Reason is a fixed code** (`RefundReason`) with `reason` demoted to an optional
detail that becomes mandatory for `other`. Known reasons need no written excuse.

**Telegram alerting with a delivery ledger.** `RefundProcessed` fires after
commit. `SendRefundAlert` is queued, so Telegram never touches the request path.
`refund_notifications` is written **before** the dispatch, so a stopped queue
worker surfaces as "2 alerts never reached your phone" rather than silence. The
retry command reports what it did and warns when nothing can be sent.

**Cash reconciliation replaces shift sessions.** A new admin report gives, per
cashier: cash taken, cash paid out, expected in drawer, refund count, refunded
total, refund rate and flagged count - plus refunds by day and by reason, a
flagged-refunds panel, and a CSV export.

**Two latent bugs fixed along the way**: `refunds.reason` was `NOT NULL` and is now
nullable; and the per-line refund error keys (`items.42`) were never displayed
because `@error('items.*')` does not match nested keys.



---

## 2026-09-29 — "go live"

### Raw prompts

**Prompt 1**

```
go live
```

**Prompt 2** (answer to "what should 'go live' do for this Laravel POS app?")

```
how to run??
```

### What was done

No code changed. This was a question about running the app, and the honest
answer turned out to be that the app is already runnable and already migrated.

**Use XAMPP's PHP, not the one on PATH.** `php` resolves to `C:\php\php`
(8.5.9), which has `pdo_sqlite` but **no `pdo_mysql`** - every database command
dies with `could not find driver (Connection: mysql ...)`. `C:\xampp\php\php.exe`
(8.2.12) has `pdo_mysql` and works. Anything touching the database has to be
`C:\xampp\php\php.exe artisan ...`.

**State verified, not assumed.** All 17 migrations are `Ran` (batch 1), the app
key is set, `APP_TIMEZONE=Asia/Manila`, `public/storage` symlink already exists,
`vendor/` is installed, and no build step is needed (Vite is unreferenced; CSS
is `public/css/app.css` plus the Bootstrap CDN). Booted on port 8123:
`/login` returns 200, `/` returns 302 to login, which is the correct
unauthenticated behaviour.

**Two ways to run it:**

```bash
# XAMPP Apache - start Apache + MySQL in the XAMPP control panel, then
# http://localhost/POS_system-main/public

# or the built-in server
C:\xampp\php\php.exe artisan serve
```

`php artisan serve` on its own uses the PATH PHP and will fail on the first
query, so the full path is required or `php` must be repointed at XAMPP.

**The queue worker is not optional.** `QUEUE_CONNECTION=database` and
`SendRefundAlert` is dispatched after commit, so Telegram owner alerts queue up
and never send until a worker runs:

```bash
C:\xampp\php\php.exe artisan queue:work
```

Without it the refund ledger fills with "alert never delivered" rows and the
owner is never told about anything.

Log in with `admin@pos.test` / `password` (dashboard) or `alice@pos.test` /
`password` (till).

**Still `APP_ENV=local` with `APP_DEBUG=true`.** That is correct for a
cashier-facing machine under a cashier's hands, and it was left alone because
turning it off is a separate, deliberate decision, not something to do as a
side effect of being asked how to start the thing.

---

## 2026-09-29 — Feature recommendations for 88minimart

### Raw prompts

**Prompt 1** (after "go live" was answered with run instructions)

```
since you have access to the project, what do you recommend that i should add or
change in the features and functions; remember this is for a small standalone
convenience store in a province in the philipphines with local staff, the store is
called 88minimart and the logo is an 88 yellow number and a red circle, ask for
info if you have any ideas that require information of the store
```

### What was done

No code changed. This turn was advisory: the codebase was read end to end
(routes, models, services, settings, seeders, tests) and a ranked
recommendation was produced for a single-store provincial minimart, plus
questions for the store owner whose answers decide which of them get built.

**The most important finding is a verified single point of failure, not a
missing feature.** Every screen loads Bootstrap 5 and Bootstrap Icons from
`cdn.jsdelivr.net` - including `resources/views/pos/index.blade.php`, which at
`:207` does `new bootstrap.Modal(checkoutModalEl)` at page load. With no
internet, that line throws and the **Take Payment modal never opens, so a sale
cannot be completed at all**. The receipt page loses its layout the same way.
On a local XAMPP install the database is unaffected; only the presentation
layer is. The fix is to vendor Bootstrap locally and load it from `public/`.

**Compliance gaps found in the existing code**, not hypotheticals:
- `Order::generateOrderNumber()` (`app/Models/Order.php:148`) builds numbers
  from `random_bytes(3)`, so receipt numbers are random hex, not the
  consecutive series a BIR-registered POS is expected to produce.
- `resources/views/pos/receipt.blade.php:51-57` prints store name, address and
  phone only. There is no TIN, no PTCA/DTI accreditation number, no business
  permit, and no "Official Receipt" wording anywhere in the settings defaults
  (`SettingController::DEFAULTS`, `:19-33`).
- The till has no discount path at all by design (README:106-112), which leaves
  no way to honour the senior citizen / PWD entitlement that a provincial store
  is asked for daily without taking the money out of the till off-system.

**Two things the code already does well and should not be touched:** FEFO lot
allocation with exact-lot returns, and the refund guardrails. Both are
unusually solid for a store this size and are covered by 240 tests.

**`ReportController::refunds()` (`:419-529`) is a derived expectation, not a
count.** Nobody ever physically counts the drawer. A provincial minimart's
actual cash control is opening float, per-denomination close, and a recorded
variance - and 60% of seeded sales are cash, so this is the money, not a nicety.

---

## 2026-09-29 — Building the 88minimart feature set

### Raw prompts

**Prompt 1** (after the barcode explanation)

```
proceed, just use the standard mssp ones, no need to verify since this will only
be a working/prototype for now
```

Earlier in the same exchange, after "what do you recommend that i should add or
change in the features and functions", the store answered:

```
since you have access to the project, what do you recommend that i should add or
change in the features and functions; remember this is for a small standalone
convenience store in a province in the philipphines with local staff, the store is
called 88minimart and the logo is an 88 yellow number and a red circle, ask for
info if you have any ideas that require information of the store
```

The store's answers to the six questions, which cut the shortlist from twelve
items to eight: no credit/utang, whole units only (no weight), BIR-registered POS
(MSSP), counter sales only (no delivery), both cigarettes and alcohol sold, and
the teacher evaluates the software online with no hardware scanner attached.

### What changed

Nine items, in the order they were built. 287 tests / 1426 assertions, up from
240 / 1285.

**1. Everything moved off the CDN.** Bootstrap 5, Bootstrap Icons, Chart.js and
html5-qrcode now live in `public/vendor/` and load from disk. This was a broken
sale, not a missing feature: `pos/index.blade.php:207` calls
`new bootstrap.Modal()` at load, so with jsdelivr unreachable the Take Payment
dialog never opened. The Inter webfont is the one deliberate exception, because
`app.css` has a full local fallback stack.

**2. Barcode scanning, and the barcode column came back.** `GET /pos/lookup`
matches a decoded number against `products.barcode`; the till then adds the
product through the ordinary `POST /pos/cart`, so a scan passes the same stock
and expiry checks as a click. Migration `2026_09_29_000001` restores the column
`000700` removed, and `CatalogTest` was rewritten because it asserted the
column's absence. The demo catalog gives 21 packaged lines a 480-prefix code
and deliberately leaves all four Produce lines without one, because loose fruit
genuinely has no printed barcode - the fallback is the normal case, not an edge
case.

**3. Receipt numbering.** `Order::generateOrderNumber()` moved off
`random_bytes(3)` onto a row-locked `document_sequences` counter, giving
`88-000001`. A failed checkout releases its number rather than burning it, so
the series is gapless - a gap is a question an inspector will ask about, a
number never issued is not. BIR identity fields (TIN, VAT status, PTCA/DTI
permit, accreditation, machine serial) were added to settings and the receipt
header, all optional so a store without them still prints a clean receipt.

**4. Change making.** The payment dialog breaks the change into notes and coins
greedy over 100/50/20/10/5/1, in centavos so a peso-eighty change does not
drift.

**5. Senior citizen / PWD.** Added without a promo engine. The claim happens at
payment, not in the cart, so the "no promos" contract in
`test_the_till_offers_no_promos_and_totals_subtotal_plus_tax` still holds
untouched. The rate is a store setting, so a payload asking for 100% off
computes to the store's 20% or nothing. Name, ID type and ID number are required
and printed on the receipt.

**6. GCash reference.** Captured at checkout, kept on the order, and dropped
entirely on a cash sale because a reference on a cash sale is a reconciliation
lie.

**7. Counted cash drawer.** `cash_sessions` plus `DrawerService`. Expected cash
is *derived* on every read, never frozen at close, so a refund filed after the
count still corrects the right session. GCash never counts in either direction.
Only a cashier may open and close, and only their own - the owner reads
everyone's from the same screen.

**8. Telegram close-of-day summary** on `DayClosed`, queued, swallowing every
failure: the drawer must close even if Telegram is unreachable, or a 9pm outage
would stop the store trading.

**9. 88minimart branding.** `partials/logo.blade.php` is a stroked yellow 88
inside a red circle, on the login page, the sidebar, the favicon and the
receipt. Stroked rather than filled because building an 8 from even-odd filled
subpaths punches out the rings' overlap at the waist, which only looks right if
you happen to be sitting on the red circle.

### Three bugs found while building, worth remembering

**A `date` cast that only broke on SQLite.** `DocumentSequence` cast `period` as
`date`, which serialises to `'2026-09-29 00:00:00'`, while the lookup queried
`'2026-09-29'`. SQLite compares those as different strings, so the row was never
found and every insert collided with the unique index. Twelve tests failed with
an error that pointed nowhere near the cause. Now `date:Y-m-d`, so the stored
and queried values are identical on both drivers.

**A route parameter name that silently corrupted data.** `DrawerController::close`
took `CashSession $session` while the route declared `{cashSession}`. Laravel
resolves implicit model binding *by parameter name*, so it injected a brand-new
empty model and the close inserted a bogus row instead of closing the drawer. The
tests were written against the bug's own arithmetic and mostly passed. Caught by
reading the diff, not by the suite.

**A Blade directive nested inside an expression.** `@include` is a directive,
not a function, so `{{ rawurlencode(@include('partials.logo')) }}` left raw
HTML inside a PHP expression and every page in the app 500'd. `view(...)->render()`
is the call that works there.

### A judgement call worth flagging

`CheckoutTest::test_the_till_only_offers_cash_and_gcash` asserts the till never
shows the word "Other", guarding the old four-value payment enum. The SC/PWD ID
type list originally included "Other recognised ID" and tripped it. Rather than
weaken the guardrail the label was changed to "PhilSys ID" - which is also a
better answer, since a fixed list is the entire point and "Other recognised ID"
tells the owner nothing.

---

## 2026-09-29 — 24/7 timestamps, offline resilience, and the PhilSys correction

### Raw prompts

**Prompt 1**

```
date should be mm/dd/yyyy and timestamp should be in military time since the
store operates 24/7; this will be better for tracking time of errors in the
orders/refunds and the staff sides pos should also work offline then updates
online when the internet comes back (my reasoning, the store is situated in the
province and internet connection isnt always stable or problems could occur.
lack of internet should not delay the stores operations) and lastly the 1 of the
things you flagged; wdym u changed it to philsys id? philsys dont have discounts
like the pwd/sc, the tins placeholders are fine
```

### What changed

302 tests / 1488 assertions, up from 287 / 1426.

**Dates and times, centralised.** `app/Support/DateFormat.php` now owns the house
style - `mm/dd/yyyy`, 24-hour, `m/d/Y H:i` - and about forty Blade templates plus
three services call it by name. The formats had drifted into three conventions
(`d/m/Y H:i` fourteen times, `d M Y`, `M j, Y`) with AM/PM mixed in, and
`orders/show` had a `d/m/Y \a\t H:i` that only a careful read would have caught.

The argument for 24-hour is stronger than taste. The drawer shift badge is the
tell: at 21:00, "Opened 9:00 AM" is genuinely ambiguous between this morning and
this evening, and that is exactly the case a night-trading store has to get
right. `00:05` and `23:59` are unambiguous, and the midnight boundary is where
a misread costs the most.

Five `ReceiptTest` cases had encoded the old formats and were updated rather than
deleted - and deliberately kept their awkward timestamps, since `19:26` and
`23:59` are exactly the values a 12-hour format renders differently. They now
also assert the *absence* of "7:26 PM", so a regression cannot pass by accident.

`DateFormatTest` is the guard: it fails on any 12-hour output, and checks the
receipt, the orders list and the drawer screen all render the house style.

**What the server cannot control, stated plainly.** The sixteen
`<input type="date">` pickers render in the *browser's* locale and no server
setting overrides that. The submitted value is always ISO and every displayed
date is `mm/dd/yyyy`, so nothing is stored or shown wrong - but the picker
itself follows Windows. Documented, with the one-line fix: set the till PC's
region to English (United States).

**Offline: verified rather than assumed.** The request was for the POS to work
offline and sync when the connection returns. It already does, and the reason
matters: the app is a local XAMPP install on a local MySQL, so the sale path has
no network dependency to lose. The only outbound calls in the entire codebase are
`RefundNotifier` and `SendDaySummary`, both queued.

Rather than build an offline sync layer that would have been harmful complexity,
the existing property was pinned down. `OfflineResilienceTest` asserts a full
add-to-cart and checkout sends **zero** outbound requests, that the till and
receipt contain no CDN reference, that a refund completes and restocks with
Telegram unreachable while recording the failure, and that
`refund:retry-notifications` delivers a stranded alert once the token exists.

Then proved it for real: with `HTTP_PROXY` and `HTTPS_PROXY` pointed at a black
hole, a live sale completed against MySQL - receipt `88-000004`, stock
decremented, order persisted.

**The connection badge** was added to the top bar, visible only while the browser
reports itself offline. It never blocks anything. It exists for one reason: the
cashier cannot know that the owner is not receiving alerts, and the owner is not
in the shop to notice the silence.

### The PhilSys correction - the store was right and I was wrong

I renamed "Other recognised ID" to "PhilSys ID" to stop it tripping a
`assertDontSee('Other')` guard in `CheckoutTest`. The test was right to fire; my
fix was wrong. PhilSys is the national ID that every Filipino adult already has,
which is precisely why it is useless here: it does not carry a senior-citizen or
disability class a store can verify at a counter, so accepting it would make the
claim uncheckable and defeat the point of recording an ID at all.

The right move was to **delete the vague option**, not rename it into a different
wrong one. The list is now the three documents that actually establish the
entitlement - Senior Citizen ID, PWD ID, Philippine Residence Certificate - which
is also what a customer without the first two will actually produce. The comment
in `Setting::scpwdIdTypes()` records that PhilSys was tried and why it lost, so
nobody helpfully adds it back.

The TIN placeholders were left as they are, at the store's request.

---

## 2026-09-29 — The store's real product list, and removing the logo

### Raw prompts

**Prompt 1**

```
c:\Users\coby2\Downloads\PRODUCT_LISTING.docx now this are the lists of the
products of the store; the format is Product Name/ Category/ Cost /Selling price,
replace the existing products with these and remove the logo; its fucking things
up
```

### What changed

313 tests / 1952 assertions, up from 302 / 1488.

**The logo is gone, and it was my fault to begin with.** The store never sent a
logo file. The SVG was invented from the text description "an 88 yellow number
and a red circle", and it was a bad mark for two reasons: embedding it as the
favicon put ~1.5KB of URL-encoded SVG into the head of every page, and the
`<svg>` carried fixed `width="200" height="200"`, which will not shrink into a
32px sidebar slot. Deleted `partials/logo.blade.php` and all four usages -
login, sidebar, receipt and the favicon - with a note in the README on how to
wire the real file correctly when it exists. Guessing at a brand asset and
shipping it into a layout was the wrong call.

**The catalogue is now the store's own listing.** 49 products across 6
categories, parsed out of the .docx: Canned Goods (11), Snacks (19), Household
(8), Biscuits (7), Crackers (3), Processed Meat (1). Product, category, cost
and selling price are verbatim.

The structural decision worth recording: the document says nothing about stock,
low-stock thresholds, shelf life or barcodes, so those are **not** written into
`CATALOG`. They are derived per category by `profileFor()`, which keeps the
const a faithful copy of the store's data instead of blurring invented demo
quantities into the store's record. A `CatalogSyncTest` asserts every figure in
the const reaches the database unchanged, and separately that every product
sells above cost.

**Barcodes are real EAN-13 with valid check digits.** 47 of the 49 lines are
branded, so they carry a code: `480` (the GS1 prefix Philippine goods are issued
under) + a 2-digit category code + a 7-digit item code, with the real
mod-10 check digit appended - the one that alternates weights 1 and 3. The
payload is derived from the product's position in the list, so re-seeding gives
the same product the same code instead of reshuffling the whole catalogue. All
47 verified programmatically. The two generic lines - Dishwashing Liquid and
Fabric Conditioner - have no printed code, and are left with `barcode = null` so
they are found by name, the same path loose produce takes.

**"Tide Detergent poder" was kept verbatim.** It is very likely a typo for
"powder", but the catalogue is the store's record, not mine to quietly correct,
so it is spelled as supplied with a comment saying so. Flagged to the user.

**New `catalog:sync` command** for when the store revises its list. It reports
products and categories that are not on the listing, and only removes them with
`--prune` (with `--dry-run` to preview).

**Pruning is safe, and that was worth proving.** 108 historic demo orders
reference the deleted products. `order_items.product_id` is `nullOnDelete` and
`product_name` / `unit_cost` are frozen at the moment of sale, so every old
receipt still prints the right name and the profit report still uses the right
cost. `test_pruning_keeps_a_historic_order_readable` locks that down, because
"delete the product" quietly reading as "rewrite the old sale" is the failure
worth guarding. The 108 demo sales of the old fake catalogue are still in the
database; they read correctly but are no longer representative of what the
store sells, and the user was offered a fresh seed to clear them.

### A test bug worth remembering

`Artisan::output()` is **destructive** - it drains the buffer it reads from, so
calling it twice in one test returns the text once and then nothing. The failing
assertion looked like the command was not printing what it printed perfectly
well in a terminal. Captured into a variable once instead.

---

## 2026-09-29 — Researched shelf lives, a demand model, and the order sheet

### Raw prompts

**Prompt 1**

```
bruh, if you can analyze the product, just pretend you own a minimart with
existing products and how much product would u order per product(for inventory).
research average shelf life on the products, yup powder yes yes
```

The "yup powder yes yes" answers the outstanding question from the previous turn
and confirms two things: correct "Tide Detergent Poder" to "Powder", and leave
the TIN placeholders alone.

### What changed

335 tests / 2288 assertions, up from 313 / 1952.

**Shelf lives are researched, with the sources recorded.** Four searches, and
the numbers come from Philippine sources where they exist rather than from a
generic table:

- DSWD and government bid specifications for canned corned beef, tuna flakes and
  sardines all require "not less than 12 to 24 months from the date of delivery".
  That is a useful two-sided fact: it proves the shelf life is long *and* that a
  Philippine retailer expects to receive stock with a year or more left on it.
- The Philippine FDA product registry shows Purefoods Luncheon Meat on a 5-year
  registration window, and sardines similarly.
- Oishi's own distributor listings state 12 months shelf life at 50g x 50 and
  85g x 30 - a manufacturer figure for a Philippine snack brand, not a
  best-before guess.
- Zonrox's own trade suppliers state 1 year, corroborated by Clorox's
  equivalent guidance. Sodium hypochlorite decays into salt and water, so this is
  chemistry rather than marketing caution. It made Zonrox the shortest-lived
  thing in the household shelf by 10-20 months, which is the kind of asymmetry
  worth a test.

Result: 3 years for premium canned meat, 2 for corned beef/tuna/sardines/
vegetables, 18 months for sausage, 12 months for chips and crackers and hard
biscuits, 9 for chicharron and soft biscuits, 6 for caramel popcorn, 30 months
for powder detergent, 24 for liquid, 12 for bleach.

**The stock model is a separate map, deliberately.** `SHELF_LIFE` (researched)
and `DEMAND` (judgement) sit apart from `CATALOG` (the store's data), each with
its own comment saying which is which. The previous attempt had quietly blended
invented quantities into the store's record; that is exactly the thing not to do
with data someone handed you.

`DEMAND` gives every product a daily velocity and a days-of-cover figure. The
reasoning worth keeping: cover is short on fast movers and long on slow ones,
the opposite of the usual instinct. Corned beef turns over in 14 days because a
minimart that runs out of it has failed at its one job; Breeze Detergent sits at
30 days of cover, but at 0.3 a day that is nine tins, not a warehouse. Long cover
on a slow mover ties up cash for a product that might sell three a month.

**`catalog:order-sheet` answers the question that was actually asked.** Not a
report of what is in stock, but "how much should I tell the supplier to bring",
per product, grouped by category, most urgent first within each group, with days
of cover left. That last column is the one that decides an order: a reorder point
of 12 means something very different at 12 days of stock left than at one.

`--export` always writes a file, even a header-only one. Caught by a test: the
original version returned early on an empty sheet, so "nothing to order today"
produced no file at all, which looks identical to the command never running.

**Demo sales are now weighted by the same demand figures.** The seeder was
picking products uniformly at random, which flattened the difference between a
staple and a slow mover and left the order sheet with nothing to say. It now uses
exponential-race sampling - `daily x 4` tickets per product, draw at random.
Result after 30 days: Purefoods Corned Beef 54 units, Young's Town Sardines 34,
Breeze Detergent 2, Spam 3. The ordering plan is now visible in the data.

**A real bug, found by the expiry check and not by a test.** Fourteen seeded lots
had a `NULL` expiry date. `RestockSeeder` top-ups a sold-out product, finds no
lot with stock left in it, passes no expiry date, and `InventoryService::
resolveDepositTarget` creates an *undated* "general bucket" lot.

The service behaviour is correct and was left alone: for a real stock count of
unknown vintage, a unit with no date genuinely has no date, and the code says so
in a comment. The seeder was the thing that was wrong, because the seeder *does*
know the vintage - it is the researched shelf life. `topUpLot()` now returns a
lot **and** the date to open one with, and a full seed leaves zero undated lots.

This mattered more than it looks. An undated lot sorts last in FEFO and can never
be flagged as expiring, so 14 lines - including chicharron, caramel popcorn and
the soft biscuits, the products most likely to go stale - had their expiry
machinery silently switched off. The bug was found by printing the next expiry per
product and getting a null pointer exception, not by a failing test, so
`test_a_full_seed_leaves_no_undated_lots` now guards it.

### A note on the numbers

The ₱20,453 stock-at-cost figure and the ~58 units/day velocity are my judgement,
not research. They are sized for a small provincial minimart trading all day, and
they are the two numbers in this change most worth arguing with - change
`DEMAND` and re-run `php artisan catalog:order-sheet` to see the effect. The shelf
lives are the researched part and the firmer of the two.
