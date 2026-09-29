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
- **Offline selling** - the till keeps selling through a network outage and reconciles afterwards. See [Offline behaviour](#offline-behaviour).
- **24/7 timestamps** - `mm/dd/yyyy` and a 24-hour clock everywhere, because a store that never closes cannot have "9:00 AM" and "9:00 PM" on the same screen. See [Dates and times](#dates-and-times).
- **Roles** - `admin` and `staff`, enforced by the `role:admin` middleware, which records every denied attempt.
- **Profit tracking** - the dashboard breaks down every product by cost price, selling price, quantity and total profit (`(selling - cost) x quantity`), with total cost, total sales, profit margin and a red loss treatment for anything sold below cost. Two views are shown: what the selected period actually earned, and what the current stock would earn.
- **Barcode scanning** - the till scans a product with the device camera, or a USB scanner in keyboard-wedge mode, or a typed number, and adds it to the sale. Scanning is a shortcut, never the only way in: the search box and product grid are always available, because a crumpled label, loose produce and a flat battery all have to be survivable. See [Scanning](#scanning).
- **BIR official receipts** - receipts are numbered consecutively from a row-locked daily counter (`88-000001`, `88-000002`) and print the TIN, VAT status, PTCA/DTI permit, BIR accreditation and registered machine number. See [Receipt numbering](#receipt-numbering).
- **Senior citizen / PWD discount** - a statutory discount claimed at the counter, with the customer's name and ID recorded on the order and printed on the receipt. The rate is a store setting, so a cashier can never decide how much to give away. See [Senior citizen and PWD](#senior-citizen-and-pwd).
- **GCash references** - the reference number is read off the customer's phone and kept against the order, so e-wallet takings can be matched to a remittance.
- **Change making** - the payment dialog breaks the change down into the notes and coins to hand over, which is the part a cashier actually gets wrong.
- **Counted cash drawer** - a cashier opens a drawer with a counted float, sells, then counts the drawer at the end of the shift. The difference against what the orders say should be there is the variance, recorded with a reason. This is the only figure in the system that comes from physically counting money. See [The cash drawer](#the-cash-drawer).

## Requirements

- PHP 8.2+ (developed against XAMPP PHP)
- Composer 2
- MySQL 5.7+ or MariaDB 10.3+ (the test suite runs on SQLite)
- Node.js and Vite are not used. Styles come from `public/css/app.css` plus a **locally vendored** Bootstrap 5 in `public/vendor/`, and each page's JavaScript lives inline in its Blade template. The untouched `resources/js`, `resources/css`, `vite.config.js` and `package.json` files are Laravel skeleton leftovers and are unreferenced.

### Why nothing is loaded from a CDN

A provincial store loses its connection to the internet regularly, and this app used to depend on `cdn.jsdelivr.net` for Bootstrap. That was not a cosmetic problem: `resources/views/pos/index.blade.php` calls `new bootstrap.Modal(...)` at page load, so with the CDN unreachable the **Take Payment dialog never opened and a sale could not be completed at all** - not just an unstyled page.

Bootstrap 5, Bootstrap Icons, Chart.js and the html5-qrcode scanner are now vendored under `public/vendor/` and served from disk. The Inter typeface is the one remaining webfont and is deliberately still remote, because `app.css` has a full local fallback stack: an outage costs the page its font and nothing else.

**No store logo is bundled.** There is no logo asset in the repo and no favicon is inlined - an earlier attempt embedded the mark as a `data:` URI, which put roughly 1.5KB of URL-encoded SVG into the head of every page and laid out badly, because an `<svg>` with fixed `width`/`height` will not shrink into a sidebar-sized container. If the store's logo file is added, reference it as a normal file with `width: 100%` and no intrinsic sizing, and point the favicon at the same file.

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

The seeders create the store's own catalogue: **49 products across 6 categories** (Canned Goods, Processed Meat, Snacks, Crackers, Biscuits, Household), about 30 days of demo sales, and a restocking pass that leaves a few low-stock items on the dashboard.

### The catalogue is the store's listing

`CatalogSeeder::CATALOG` is a verbatim copy of the store's product document - product, category, cost and selling price, exactly as supplied. **A product that is not in that list is not in the store.** Everything the document does not cover is held in two separate maps so the const stays a faithful copy of the store's data:

| Map | What it holds | Where it comes from |
|---|---|---|
| `CATALOG` | name, category, cost, selling price | The store's document, verbatim |
| `SHELF_LIFE` | days from delivery to expiry | **Researched**, with the source recorded in the comment |
| `DEMAND` | units per day, days of cover | **Judgement** about a small provincial minimart |

#### Shelf life

Not round numbers, and the reasoning is in the code so an owner who disagrees can see why:

- **Canned goods** - DSWD and government bid specifications for corned beef, tuna and sardines all require *"not less than 12 to 24 months from the date of delivery"*, which tells you both that the shelf life is long and that a retailer expects to receive product with a year or more left. The Philippine FDA product registry shows Purefoods Luncheon Meat on a 5-year window. So: 3 years for the premium meats, 2 for corned beef, tuna, sardines and vegetables, 18 months for sausage.
- **Snacks and crackers** - Oishi's own distributor listings state 12 months. Chips carry a "best before", not a "use by": FDA guidance classes biscuits and candy as microbiologically stable. Chicharron and caramel popcorn are pulled in to 9 and 6 months because fried snacks go rancid in a hot climate and sitting on the shelf does not help them.
- **Household** - Zonrox's own trade suppliers state 1 year, and that is not marketing caution: sodium hypochlorite decays into salt and water, so a bottle really does lose active strength in about a year. It is the one chemical in the store that genuinely dates. Powder detergent is the most stable thing in the shop at 30 months; liquid detergent and softener get 2 years because heat costs them fragrance and viscosity.

#### How much to stock

```
target stock = daily demand x days of cover
reorder at   = daily demand x (lead time + safety stock)
```

Lead time is 2 days (a wholesale run or a supplier call) and safety stock 3 days (a busy weekend, or a supplier running late). **Cover is deliberately short on fast movers and long on slow ones**, which is the opposite of the usual instinct and is what keeps working capital honest: corned beef and chips turn over in about two weeks because a minimart that runs out of either has failed at its one job, while Breeze Detergent sits at 30 days of cover - but at 0.3 a day that is nine tins, not a warehouse.

The full plan lands at **₱20,453 of stock at cost** across 49 lines, selling about 58 units a day. That is roughly 25-30 baskets at two to three items each: a small standalone store, trading all day.

`StockModelTest` checks the rules the judgement has to obey rather than the numbers themselves - a reorder point must stay below the target (or a full shelf would order itself), bleach must never outlast the powder beside it, snacks must never outlast the tins, and the seeded stock value must land within a plausible band.

#### The order sheet

```bash
php artisan catalog:order-sheet                       # what to order now
php artisan catalog:order-sheet --all                 # every line, for a stock count
php artisan catalog:order-sheet --category=Canned     # one section
php artisan catalog:order-sheet --export=order.csv    # send it to a supplier
```

It lists each product grouped by category, most urgent first within each group, with days of cover left - which is the number that actually decides an order. A reorder point of 12 means something very different when it is 12 days of stock left or one. `--export` always writes a file, even a header-only one, because "nothing to order today" is an answer and no file at all looks like the command never ran.

The demo sales are drawn **weighted by the same demand figures**, which is what makes the plan checkable: after 30 days the staples are visibly drawn down and the long tail is untouched. Uniform random selection would flatten that difference and leave the order sheet with nothing to say. A test asserts the shape of the distribution - four staples out of 49 products are 8% of the catalogue and must be a good deal more than 8% of the units sold.

To bring a live database in line with a revised listing:

```bash
php artisan catalog:sync             # report what is not on the listing
php artisan catalog:sync --prune     # remove it, then re-seed
php artisan catalog:sync --prune --dry-run   # show what would go
```

Pruning is safe for history. `order_items.product_id` is `nullOnDelete` and `product_items.product_name` and `unit_cost` are frozen at the moment of sale, so a historic receipt still prints the right product name and the profit report still uses the right cost. `CatalogSyncTest` pins that behaviour down, because "delete the old product" quietly reading as "rewrite the old sale" is the failure mode worth guarding.

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

The `orders` discount columns and the `DiscountType` enum still exist because historic orders and the reports read them. Orders with no claim are written with `discount_type = fixed`, `discount_value = 0`, `discount_amount = 0`. The one exception is a senior citizen / PWD claim, below - which is a statutory discount applied at payment, not a promo in the cart.

## Scanning

The till can find a product three ways, and all three are live at once:

| Route in | How |
|---|---|
| **Camera** | The Scan button opens the device camera and decodes the barcode with the vendored html5-qrcode library. |
| **USB scanner** | A Bluetooth or USB scanner in keyboard-wedge mode types the digits and presses Enter. No driver, no setup, no code. |
| **Name** | The search box and product grid, exactly as before. |

A decoded number is posted to `GET /pos/lookup`, which matches it against `products.barcode` and returns the product. The till then adds it through the ordinary `POST /pos/cart` endpoint, so a scanned item passes the same stock, expiry and active checks as a clicked one - the scanner is an accelerator, not a way around the rules.

**Scanning is never required and never the only way in.** This is a deliberate constraint, not a fallback bolted on afterwards:

- Produce and repacked goods have no printed barcode at all, so they are only ever found by name. The seeded catalog leaves all four Produce lines without a barcode for exactly this reason.
- A crumpled, greasy or sun-bleached label will not read, and that is most of what sits on a small-store counter.
- If the vendored library fails to load, the camera is refused, or permission is denied, the dialog says so plainly and the typed field still works. Nothing about selling depends on the camera.
- The camera is released when the dialog closes, so the browser does not hold the webcam light for the rest of the shift.
- The same code held in frame for more than a moment is ignored, or one scan would add thirty of the same item.

Browsers only expose a camera on a **secure context** - `https` or `localhost`. Running `php artisan serve` on the store's own PC is localhost, so the webcam works. Scanning from a phone over the WiFi at `http://192.168...` is blocked by the browser until you serve over https.

The barcode column was removed by migration `000700` on the grounds that the till never scanned, and `2026_09_29_000001` puts it back. It is nullable and unique: a store with no printed code on a product stores null, and null never collides with the next product that also has none.

## Receipt numbering

Receipt numbers used to be random hex (`ORD-20260929-A04F5C`), which is fine for an order id and wrong for a receipt: a customer quoting a receipt over the phone, or an inspection checking for gaps, both need a run of numbers that reads straight through.

`Order::generateOrderNumber()` now takes from `document_sequences` - one row per document type per day - inside the same transaction that writes the order:

- **Consecutive.** `88-000001`, `88-000002`, `88-000003`. The prefix is a store setting.
- **Row-locked**, so two sales landing in the same instant cannot be handed the same number.
- **Gapless.** A checkout that fails releases its number for reuse, because no receipt was ever printed for it. A gap is a question an inspector will ask about; a number that was never issued is not.
- **Derived, not stored.** The expected-cash figure on a drawer session is recomputed on read rather than frozen at close, for the same reason - see [The cash drawer](#the-cash-drawer).

`DocumentSequence::nextFor('receipt')` returns the first number not yet issued, which is what a Z-report reconciles its range against.

The receipt header prints the store's TIN with its VAT status, the PTCA/DTI permit number, the BIR accreditation number and the registered machine number. All of them are optional in the schema, because a store that has not been issued them yet must still be able to save its settings and get a clean receipt rather than a column of blanks.

## Senior citizen and PWD

The till still has no promo engine, and this does not add one. What it adds is the one discount a Philippine store has to be able to honour at the counter and currently cannot.

Without a path for it, a cashier has two bad options: refuse an entitlement the customer has, or hand the money over and take it out of the drawer with no record that it happened. So:

- The claim is made **at payment**, not in the cart. The cart stays `subtotal + tax`; there is no `pos.cart.discount` route and no Apply button.
- The **rate is a store setting** (`scpwd_discount_rate`, default 20%, `0` switches it off). A payload carrying its own `scpwd_discount_rate` or `discount_amount` is ignored - the amount is computed server-side from the store setting and the cart the cashier actually rang up.
- The **name, ID type and ID number are required**, stored on the order, and printed on the receipt. A discount with no evidence behind it is a discount the owner cannot follow up.
- The **ID types** are a fixed list of the three documents that actually establish the entitlement: Senior Citizen ID, PWD ID, and Philippine Residence Certificate. PhilSys is deliberately *not* on it - PhilSys is the national ID that literally everyone has, which is exactly the problem, and a PhilSys number does not carry a senior-citizen or disability class a store can verify at a counter. Accepting it would make the claim uncheckable.
- A `100%` rate is clamped so the bill can never go negative.
- No claim means `scpwd_applied = false` and the ID columns are null, so a 0% claim stays distinguishable from a real one.

## The cash drawer

The refund reconciliation report has always answered "what *should* be in the drawer". That is a derived figure - it can show a cashier is skimming, but only after the fact, and it cannot tell a short drawer from a miscount. `cash_sessions` closes that gap.

A cashier opens a drawer with a counted float, sells, then counts the notes and coins at the end of the shift and enters what is actually there:

```
expected = opening float + cash sales - cash refunds
variance = counted - expected
```

- **GCash never counts**, in either direction. Counting it would put money in the drawer that was never physically there and make every till look short by exactly the e-wallet total.
- **The expected figure is derived, never stored.** A refund filed after the drawer was counted still lands in the right session's arithmetic, so a late filing corrects the past rather than leaving it quietly stale.
- **Only a cashier can open and close**, and only their own drawer - the count has to come from the person who was holding the money. The owner reads everyone's from the same screen, which is why the read is shared and the write is staff-only.
- **One open drawer per cashier**, enforced in `DrawerService::open()`. A unique index cannot do this: `closed_at` is NULL while a drawer is open and MySQL treats every NULL as distinct.
- A variance does not have to be explained, but it should be - the reason is what turns "the drawer was 40 short" into something actionable. It is optional because a miscount is the most common reason and nobody should be blocked from closing over it.

Closing dispatches `DayClosed`, which queues a close-of-day summary to the owner's Telegram: float, cash sales, refunds, expected, counted, and whether it balanced. The listener swallows every failure on purpose - the drawer must close even if Telegram is unreachable, or a 9pm network outage would stop the store trading.

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

## Dates and times

The store trades through the night, so the whole app has one house style, defined once in `app/Support/DateFormat.php`:

| | Format | Example |
|---|---|---|
| Date | `mm/dd/yyyy` | `09/29/2026` |
| Time | 24-hour | `21:40` |
| Both | `mm/dd/yyyy 24h` | `09/29/2026 21:40` |

**Why 24-hour.** A 12-hour clock puts "9:00 AM" and "9:00 PM" on the same screen, and on a shop's dashboard or audit log those are twelve hours apart with nothing to tell you which is which. The drawer shift badge is the clearest case: at 21:00 it is genuinely ambiguous whether the drawer was opened this morning or this evening. `00:05` and `23:59` are unambiguous, and the day boundary is exactly where a misread hurts most.

**Why centralise it.** The formats used to be string literals across forty Blade templates, drifted into three conventions (`d/m/Y H:i`, `d M Y`, `M j, Y`) with AM/PM mixed in - and one of them, `orders/show`, had a `d/m/Y \a\t H:i` that only a careful read would catch. Now a view asks for `DateFormat::dateTime($order->created_at)` by name, and `DateFormatTest` fails if anything renders a 12-hour clock again.

**Timestamps are the store's wall clock.** `config/app.php` reads `APP_TIMEZONE` (`Asia/Manila` for this store), so an order printed at 21:40 is the time the sale happened, not UTC shifted into a local label.

**The one thing the server cannot control.** The 16 `<input type="date">` pickers render in the *browser's* locale, which no server-side setting overrides - a Windows machine set to English (United Kingdom) will show a `dd/mm/yyyy` picker regardless of what the app does. The **value** is always ISO `yyyy-mm-dd` on submit, so nothing is ever stored wrong, and every date the app *displays* is `mm/dd/yyyy` regardless. To make the picker match, set the till PC's region to **English (United States)** in Windows Settings → Time & Language.

## Offline behaviour

The store is in a province and its connection is not dependable. A customer standing at the counter cannot be told to wait for a cable to come back, so **losing the internet does not stop the store trading**.

**This is architectural, not a mode that can be switched on.** The app is a local XAMPP install talking to a local MySQL, so there is no network dependency on the sale path to lose. `OfflineResilienceTest` pins this down: a full add-to-cart and checkout is asserted to send **zero** outbound HTTP requests, and the till and receipt are asserted to contain no CDN reference at all.

The only outbound calls in the entire codebase are the two Telegram notifiers, and both are queued and non-blocking. So the practical behaviour during an outage is:

| Thing | During an outage |
|---|---|
| Ring up a sale | Works. Nothing to wait for. |
| Stock, FEFO, expiry rules | Work. All local. |
| Print a receipt | Works. Styles and icons are vendored, not fetched. |
| Barcode scanning | Works, if the till PC has a camera. No internet needed. |
| Refunds and restock | Work. |
| Close the cash drawer | Works. The count is local arithmetic. |
| Owner Telegram alert | **Waits.** Queued in the database, delivered when a worker can reach Telegram. |

**Nothing is held in the browser and nothing has to be replayed by hand.** The queued job lives in the `jobs` table, so a `queue:work` that is already running simply delivers once the connection returns. If no worker is running, the job waits until one starts. A refund alert that fails outright is written to `refund_notifications` with its error, so a stopped worker shows up as "this alert never reached your phone" rather than as silence, and `php artisan refund:retry-notifications` walks the backlog.

**The one honest caveat.** An `<img>` on a product page is served from `storage/app/public` through the `public/storage` symlink, so it is local too - but if that symlink is missing, product photos will not load offline. `php artisan storage:link` fixes it. The Google Fonts webfont is the one remaining remote request: an outage costs the page its typeface and nothing else, because `app.css` has a full local fallback stack.

**The connection badge** in the top bar appears only while the browser reports itself offline. It never blocks anything, and it exists for one reason: the cashier cannot know that the owner is not receiving alerts. `navigator.onLine` reports "connected to a network" rather than "the internet is reachable", which is why the badge is worded as information rather than as an alarm.

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

335 feature tests / 2288 assertions cover login, the till and checkout, stock adjustments, batch expiry and FEFO allocation, refunds and their guardrails, cancellations, catalogue CRUD and store-listing sync, barcode scanning, receipt numbering, the senior citizen / PWD claim, counted drawers, offline resilience, the date and time house style, reports and exports, settings, user management, role enforcement and page rendering.

`RefundGuardrailTest` is the one to read first if you touch refunds: one test per way the feature could be abused, and the tax-inclusive tests use deliberately awkward prices (`3.33` at `12%`) because rounding is where refund maths usually goes wrong. `ScpwdDiscountTest` follows the same shape for the discount, `DrawerSessionTest` is the place to look if you change how expected cash is derived, `OfflineResilienceTest` guards the no-network-on-the-sale-path property, and `DateFormatTest` guards the 24-hour house style.

The suite runs on in-memory SQLite, which is not MySQL. Two bugs in this codebase were only visible there:

- `DocumentSequence` stored `'2026-09-29 00:00:00'` into a DATE column through the `date` cast while looking rows up with `'2026-09-29'`. SQLite compares those as different strings, so the row was never found and every insert collided with the unique index. The cast is now `date:Y-m-d` so the stored and queried values are identical.
- `DrawerController::close` took a parameter named `$session` while the route said `{cashSession}`. Laravel resolves implicit model binding **by parameter name**, so it injected a brand-new empty model and the close silently inserted a bogus row instead of closing the drawer. The test suite missed it; reading the diff caught it.

To exercise the same suite against MySQL (recommended after touching raw SQL):

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS pos_system_test"
DB_CONNECTION=mysql DB_DATABASE=pos_system_test php artisan test
```

## Useful commands

```bash
php artisan migrate:fresh --seed      # rebuild the demo database
php artisan catalog:sync              # report products not on the store listing
php artisan catalog:sync --prune      # remove them and re-seed
php artisan catalog:order-sheet         # what to reorder from the supplier
php artisan view:cache                # pre-compile Blade templates
php artisan route:list                # review the application routes
php artisan queue:work                # deliver refund alerts to the owner
php artisan refund:retry-notifications  # resend alerts that failed
```
