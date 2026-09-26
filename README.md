# Corner POS

A Laravel 13 point-of-sale system for a single store, built around two roles: **Administrator** and **Staff**.

- **Administrators** get the full system: staff accounts, product and category management, stock adjustments, refunds, reports, settings and the audit log.
- **Staff** get the daily operating surface: the POS terminal, their own sales history, receipt reprints and read-only stock levels.

## Requirements

| Component | Version |
| --- | --- |
| PHP | 8.3+ (developed against 8.5) with `pdo_sqlite` |
| Composer | 2.x |
| Node.js | 20+ |

## Installation

```bash
composer install
npm install

cp .env.example .env
php artisan key:generate

touch database/database.sqlite
php artisan migrate:fresh --seed
npm run build
```

`migrate:fresh --seed` builds the whole system from nothing: schema, roles and
permissions, the two accounts below, shop settings, and a small drink-and-snack
catalogue so the terminal has something to sell on first run.

### Seeded accounts

| Role  | Email                | Password   |
| ----- | -------------------- | ---------- |
| Admin | `admin@example.com`  | `password` |
| Staff | `staff@example.com`  | `password` |

On a **local** install the familiar `password` is used for convenience. Anywhere
else — and whenever `SEED_ADMIN_PASSWORD` / `SEED_STAFF_PASSWORD` are unset — a
random 24-character password is generated and printed once, because a deployment
that quietly inherits a well-known administrator password is the actual
disaster. `php artisan pos:health` **fails** while any account still uses a
well-known password.

Re-running `php artisan db:seed` is safe: it only fills gaps, so it will not
duplicate records and will not revert a renamed shop, a re-priced product or a
changed tax rate.

Serve the app:

```bash
php artisan serve
```

The database defaults to SQLite (`DB_CONNECTION=sqlite`). To use MySQL instead, set `DB_CONNECTION=mysql` and fill in the `DB_*` values in `.env`. Note that a second register or a second terminal writing at the same time is a write-contention problem on SQLite — move to MySQL before that happens.

## Roles and permissions

Roles live in `spatie/laravel-permission`. The role and permission sets are declared as enums and reconciled with the database on every `migrate`:

| Source | Contents |
| --- | --- |
| `app/Enums/Role.php` | `admin`, `staff` |
| `app/Enums/Permission.php` | 15 granular permissions |
| `app/Services/RolePermissionSyncer.php` | Writes roles/permissions to the database |

Admins bypass every permission check through `Gate::before()` in `app/Providers/AppServiceProvider.php`. Staff are governed by the permission set on the `staff` role.

Route protection uses these middleware aliases, registered in `bootstrap/app.php`:

| Alias | Purpose |
| --- | --- |
| `role:admin` | Spatie role check |
| `permission:products.manage` | Spatie permission check |
| `active` | Ejects deactivated accounts on every request |
| `guest` | Keeps signed-in users off the login screen |

Unauthorized access is answered according to the request type:

| Request | Response |
| --- | --- |
| Browser | `302` redirect plus `session('error') = 'Unauthorized action.'` |
| `Accept: application/json` / AJAX | `403` with `{"message":"Unauthorized action."}` |
| Guest, JSON | `401` with `{"message":"Unauthenticated."}` |

Deactivated accounts are signed out on their next request rather than at the moment an admin disables them, so an open session cannot keep transacting.

## Session security

`Illuminate\Session\Middleware\AuthenticateSession` is appended to the `web` group, so changing a password invalidates every other device immediately:

1. The current password is verified by the `current_password` validation rule.
2. `Auth::logoutOtherDevices($currentPassword)` runs **before** the new hash is written — Laravel validates that argument against the stored hash, so the reverse order throws.
3. The new password is then saved.

## Prompt log

`App\Support\PromptLogger` records AI-assisted instruction sequences, development prompts and other significant build input to a plain-text log.

**Location:** `storage/logs/prompt.log`

**Format:** one entry per line, `[YYYY-MM-DD HH:MM:SS] <prompt text>`

```
[2026-09-26 03:13:16] Phase 2: Logging Infrastructure Setup
[2026-09-26 03:15:07] Add refund handling for damaged goods
```

**Usage:**

```php
use App\Support\PromptLogger;

PromptLogger::log('Describe the instruction or command being processed');
```

Or from the command line:

```bash
php artisan prompt:log "Describe the instruction or command being processed"
```

**Behaviour worth knowing:**

- The directory is created on demand with `File::ensureDirectoryExists()`, so the log works on a fresh checkout with no setup.
- Writes use `file_put_contents($path, $line, FILE_APPEND | LOCK_EX)`. The exclusive lock is what makes concurrent appends safe — a busy multi-terminal install will not produce interleaved or half-written lines.
- Multi-line prompts are collapsed to a single line. The file is line oriented, so an embedded newline would split one entry across several unparseable ones.
- Blank prompts are ignored rather than logged as an empty entry.
- Nothing in the logger throws. If the disk is full or the path is unwritable, the write is silently skipped, because a diagnostic log must never take down the request that triggered it.
- Once the file passes `max_bytes` it is moved aside to `prompt-<timestamp>.log` and a fresh file is started. Rotated files keep the `.log` suffix so the repository's `*.log` ignore rule still covers them.

**Configuration** — `config/prompt-log.php`:

| Key | Default | Purpose |
| --- | --- | --- |
| `directory` | `logs` | Target directory, relative to `storage/` |
| `filename` | `prompt.log` | Active log file name |
| `timestamp_format` | `Y-m-d H:i:s` | Entry timestamp prefix |
| `max_bytes` | `5242880` (5 MB) | Rotation threshold; `0` disables rotation |

The threshold also honours the `PROMPT_LOG_MAX_BYTES` environment variable.

Reading the log back:

```php
PromptLogger::entries();   // array<int, string>, oldest first
```

## Testing

```bash
composer test              # preferred: clears config cache first
php artisan test           # equally safe, see below
./vendor/bin/pint          # code style
```

The suite covers authentication, rate limiting, deactivated-account ejection, role and permission enforcement, both unauthorized response shapes, password change with cross-session invalidation, the POS cart and checkout, refunds, staff management, reporting, seeders, and prompt log formatting, concurrency and rotation.

`AuthorizationMatrixTest` walks every route under `admin/` and asserts a staff
member cannot obtain a `200` from any of them, and `RouteReferenceTest` walks
every Blade view and asserts each `route()` call resolves. Both cover new
routes automatically, so a future permission or naming mistake fails a test
rather than a live screen.

### Caching and the test database

A cached config (`php artisan config:cache`, which `php artisan optimize` runs)
freezes the `.env` values and takes precedence over the `<env>` entries in
`phpunit.xml`. Without protection the suite would connect to the real
`database/database.sqlite` and `RefreshDatabase` would wipe it.

`tests/bootstrap.php` deletes the stale cached config before anything boots, so
the suite always uses an in-memory database regardless of cache state. It is
safe to run `php artisan test` directly after `php artisan optimize`.

## Batches and expiry (FEFO)

Perishable goods can be tracked batch by batch, sold **FEFO** — First Expired,
First Out — so short-dated stock leaves first and never gets buried under a newer
delivery.

Opt in per product, on the product form: **Track expiry by batch**. A shop that
sells coffee does not want to create lots for every bag of beans; one that sells
sandwiches cannot work without them. Products without the flag behave exactly as
before — a single stock count, no batches.

For a tracked product, `product_lots` is the source of truth and `products.stock`
is a cached total of the batches, maintained in the same transaction. Every stock
movement on a tracked product names the batch it applies to, so the movement log
still reconciles batch by batch.

- **Receiving** — `Inventory → Batches → Receive delivery` records a batch code,
  quantity, expiry and (optionally) the cost that batch was bought at. Receiving
  into an existing code tops it up, so a delivery entered twice is visible rather
  than a duplicate batch.
- **Counting** — a stock count is per batch, never a flat product total. A count
  that cannot say which batch it saw would destroy the expiry record.
- **Selling** — the terminal shows the soonest expiry and draws FEFO across
  batches as needed; a single line can consume several.
- **Expired stock** is refused at add-to-cart and at checkout. An admin holding
  `inventory.sell-expired` can tick an override to ring up a markdown or a
  write-off; a cashier cannot.
- **Returns and cancellations** put stock back into the batch it came from, not
  whichever batch is current — otherwise short-dated goods would be folded into a
  long-dated delivery and sold well past their date.

A lot with no recorded expiry is treated as the longest-lived and sold last:
"unknown" is never read as "most urgent".

The seeded install includes a **Fresh** category (Milk in two batches, Plain
Bagel) so the behaviour is visible immediately.

Not yet built, and the natural next slice: near-expiry warnings on the dashboard
and an expiry report.

## Operations

Three commands for running the till day to day.

### `pos:health`

Read-only check of anything that would be a problem in real use. Run it before
the till takes money.

```bash
php artisan pos:health
```

Reports (and exits non-zero on blocking issues): a missing administrator, an
administrator still on a well-known password, `APP_DEBUG` left on, unrun
migrations, and warns about running on SQLite with one writer. It changes
nothing.

### `pos:backup`

Copies the SQLite database to a timestamped file, folding the write-ahead log in
first so the copy is consistent, and prunes old backups.

```bash
php artisan pos:backup                 # storage/app/backups, keep 14
php artisan pos:backup --keep=30
php artisan pos:backup --source=/path/to/database.sqlite
```

On a MySQL or PostgreSQL connection it refuses and prints the `mysqldump`
equivalent rather than silently copying nothing.

### `pos:reconcile-refunds`

Repairs orders left behind by the refund bug where the order-level tax was never
returned, so `refunded_total` stayed below `total` and the order read as
"partially refunded" forever with no way to clear it.

```bash
php artisan pos:reconcile-refunds          # report only
php artisan pos:reconcile-refunds --fix    # apply
```

It only ever raises `refunded_total` to the sum of refunds already recorded
against the order — money that physically left the till and was not booked — and
never books more than the order was worth. It reports before it writes, and is
idempotent.

## Deployment

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force        # or migrate:fresh --seed on a new install
php artisan optimize               # config, route, view and event caches
```

`php artisan optimize` is verified to work: all screens and a full sale run
correctly against the cached build. If you use the database session driver,
remember that `php artisan optimize` also freezes `SESSION_DRIVER` — clear the
config cache (`php artisan optimize:clear`) after changing it.

## License

MIT.
