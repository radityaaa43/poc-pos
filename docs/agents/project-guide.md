# Project guide

Detail that every change to this codebase may need. `AGENTS.md` keeps the rules short and links
here. Moved from the previous `AGENTS.md` when the governance standard was adopted (ADR 0001).

Laravel POS with an Inertia/React frontend, multi-warehouse inventory, and a separate optional
WhatsApp service.

## Quick start

Everything runs on the host (PHP 8.4, MySQL 8.4, Node 22+); the machine setup is in `README.md` →
Local setup.

```bash
cp .env.example .env              # DB values already match the host MySQL
composer install                  # once per clone and per worktree
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
PUPPETEER_SKIP_DOWNLOAD=true npm install
php artisan serve                 # app on http://localhost:8000
php artisan queue:listen          # queue worker, second terminal
npm run dev                       # Vite, third terminal
```

`composer run dev` runs server, queue and Vite together (plus logs where PHP has `pcntl`).

First run redirects to `/setup` to create the admin and store profile; there are no default
accounts. The optional WhatsApp gateway runs separately: `cd whatsapp-service && npm install && npm start`.

Run focused tests with PHPUnit's `--filter`
(`php artisan test --filter=TestName`). Frontend tests run with Vitest: `npm test`
(`npm run test:watch` while editing; tests sit next to the file as `*.test.js(x)`). Lint the frontend
with `npm run lint` (ESLint 9, `react-hooks` rules; there is no typecheck script). Existing findings are
baselined in `eslint-suppressions.gen.json`, so only new errors fail; do not fix or edit them in an unrelated PR.
After fixing suppressed findings, shrink the baseline with
`npx eslint resources/js --suppressions-location eslint-suppressions.gen.json --prune-suppressions`.
Never run `--suppress-all` to hide a new error. Trade-off: `no-unused-vars` ignores capitalised names
(JSX components, as there is no `react` plugin), so an unused capitalised const slips through.

## Structure and references

- `app/Http/Controllers/Apps/`: dashboard modules; `Api/` and `Reports/` contain API and report controllers.
- `app/Services/`: domain operations, including checkout, stock, purchasing, and payment workflows.
- `resources/js/`: Inertia React app; Vite entry is `resources/js/app.jsx`.
- `routes/web.php`, `routes/api.php`, `routes/console.php`: web, REST, and scheduled command routes.
  Dashboard routes are one file per module in `routes/web/`, required by `routes/web.php`.
- `whatsapp-service/`: separate Node.js WhatsApp gateway.
- Module and operational detail: `ARCHITECTURE.md`, `docs/architecture-overview.md`,
  `docs/feature-index.md`, `docs/features/`, `docs/configuration.md`, `docs/testing-manual.md`
  (manual QA checklist for QRIS, ESC/POS and offline sync).

## Non-obvious behavior

- Root `DatabaseSeeder` seeds essential system configuration, not users or the full demo dataset. First-install setup creates the initial admin; demo data is opt-in via `php artisan seed:demo --force`.
- `php artisan test` uses SQLite `:memory:`; avoid database-specific assumptions. For API tests use `Sanctum::actingAs($user, ['*'])` or a real token with the needed abilities.
- Laravel's scheduler is defined in `routes/console.php`: CRM segment sync (01:00), CRM reminders (01:15), reorder generation (02:00), pending-transaction expiry (hourly), and kitchen intake recovery (`kitchen:recover-intake`, every 5 minutes). Production must invoke `php artisan schedule:run` every minute.
- Midtrans/Xendit webhooks require a publicly reachable `APP_URL`; product images require `php artisan storage:link`.
- Dine-in online payment is currently rejected; `pay_at_counter` and, when the outlet turns Bayar Belakangan on, `pay_later` are supported.
- After permission seeding, an existing session may retain stale permissions; log out and back in.

## Seeding and setup

`DatabaseSeeder` runs only system-essential seeders with permission cache reset before & after:

```
PermissionSeeder → RoleSeeder → PaymentSettingSeeder → DineInSettingsSeeder
```

After seeding, a default `PUSAT` warehouse is created and existing product stock is migrated to the `product_warehouse` pivot.

**No default users.** Admin account, store profile, business type, categories, and main warehouse are created via the first-install setup wizard at `/setup`. Open the root URL after migration; it automatically redirects to `/setup` while `Setting::app_setup_completed` is false. The `setup.notinstalled` middleware redirects to login once setup is done.

**Demo data is opt-in, not part of `DatabaseSeeder`:** run `php artisan db:seed --class=DemoSeeder --force` (or `php artisan seed:demo --force`) for the complete demo dataset. It creates demo outlets `MAL`, `TKB`, and `PUT`; `PUSAT` remains a central non-sales warehouse. Demo accounts (password `password`): `arya@gmail.com` (super-admin, all outlets), `manager@gmail.com` (manager role, MAL+TKB), `cashier@gmail.com` (cashier, MAL). Never run the demo seeder on production. Full dataset details: `docs/demo-data.md`.

**Email verification is disabled** — `User` no longer implements `MustVerifyEmail`, dashboard routes carry no `verified` middleware, and the verification routes/controllers/pages are removed. `markEmailAsVerified()` is still available via the retained trait (used by seeders and tests).

## Inventory model

`product_warehouse.stock` is the operational source of truth. `products.stock` is maintained as a global aggregate — both must be updated in the same DB transaction for every mutation. Always prefer locking `product_warehouse` rows with `lockForUpdate()` before decrementing. Use `inventory:reconcile --fix` to align global stock after data repairs.

## Critical gotchas

1. **Permission cache stale after seed** — logout + login again. Seeder resets cache but session still holds old permissions.
2. **Webhooks need public APP_URL** — Midtrans/Xendit won't work with localhost.
3. **Product images need storage:link** — `php artisan storage:link` or images won't render.
4. **Missing migrations cause 500 on new modules** — run `php artisan migrate` for newer modules (purchase orders, goods receiving, supplier returns, stock opname, dine-in, etc.).
5. **Tests force SQLite in-memory** — `phpunit.xml` sets `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`. Don't assume MySQL features. **Set `tax_rate=0` on test Product::create** to avoid PPN changing grand_total.
6. **Dev servers unified** — `composer run dev` starts server + queue + pail + vite together (`php artisan dev`). WA service stays separate.
7. **WhatsApp service separate** — `whatsapp-service/` needs `npm start` in another terminal + `WA_SERVICE_URL` in .env.
8. **CRM campaign auto-send** — requires `wa_enabled=true` + connected device in Settings > WhatsApp.
9. **Version bump on release** — update `APP_VERSION` in `.env` + `.env.example` when tagging.
10. **Concurrency patterns** — all stock mutations (checkout, transfer, receiving, payment) are wrapped in `DB::transaction` with `lockForUpdate()` on affected rows. Never skip the transaction or lock.
11. **Dine-in online payment is disabled** — `payment_option=pay_online` returns 422. Only `pay_at_counter` and (setting-gated) `pay_later` are accepted.
12. **Background requests eat flash data** — Laravel ages the flash on every request, so a poll or background fetch can delete the errors a form post is about to show. Give such a route the `keep_flash` middleware. `onSuccess` alone does not prove success (a refusal can redirect with `flash.error`), so checkout toasts only the server's `flash.success`.

## Release process

`main` is the only long-lived branch. CI/CD does not deploy; deploying to the VPS is done by hand
(ADR 0001).

1. Bump `APP_VERSION` in `.env.example` (and the server `.env`) in a `chore(release): vX.Y.Z` PR.
2. After it merges: `git tag -a vX.Y.Z -m "vX.Y.Z"` on `main` and push the tag.
3. Create the GitHub Release from the tag.

## Frontend

- **Icons**: `@tabler/icons-react`
- **Alerts/confirm**: `react-hot-toast` + `sweetalert2`
- **Charts**: `chart.js`
- **Routing**: Ziggy `route()` helper available
- **Offline mode**: `resources/js/Utils/offlineDb.js` (IndexedDB via `idb`) queues transactions when offline, flushes on reconnect; idempotent via `client_uuid` — server price wins
- **ESC/POS printing**: `resources/js/Utils/escpos.js` (WebUSB, Chromium-only; fallback `window.print()`)
- **Tailwind tokens**: `primary` (indigo), `accent` (cyan), `success` (emerald), `warning` (amber), `danger` (rose)
- **i18n**: Indonesian (`id.json`) and English (`en.json`) in `resources/js/i18n/locales`

## Test conventions

- Use `RefreshDatabase` trait on every test class
- Seed: `PermissionSeeder → RoleSeeder → UserSeeder` before every test
- Admin: `arya@gmail.com` (super-admin, all permissions); manager: `manager@gmail.com`; cashier: `cashier@gmail.com`
- **Always call `markEmailAsVerified()`** before `actingAs()` for HTTP controller tests (email verification is disabled, but the trait method remains available)
- `PUSAT` warehouse: `type='main'`, `is_active=true`, `sort_order=0`
- Product needs: `image`, `barcode`, `sku`, `title`, `description`, `category_id`, `buy_price`, `sell_price`, `stock`, `tax_rate=0`
- Attach warehouse stock: `$warehouse->products()->attach($product->id, ['stock' => N])` or `$product->warehouses()->attach($warehouse->id, ['stock' => N])`
- Open shift: `app(CashierShiftService::class)->openShift($cashier, $cashier, $openingCash, null, $warehouse->id)`
- **PHPUnit 12: no `$faker` property** — use `static int $seq = 0` counters or `uniqid()` for unique values
- For API tests: `Sanctum::actingAs($user, ['*'])` — explicit abilities required; TransientToken does not bypass `abilities` middleware

## API ability system

Master-data API routes (`/api/v1/products`, `/customers`, `/categories`, `/warehouses`, `/suppliers`) enforce Sanctum abilities matching Spatie permission names:

| Route | Ability |
|-------|---------|
| index, show | `{module}-access` |
| store | `{module}-create` |
| update | `{module}-edit` (products/customers/categories) or `{module}-update` (warehouses) |
| destroy | `{module}-delete` |
| suppliers (all verbs) | `suppliers-access` |
| `/api/v1/pos/*` (all verbs) | `pos-access` |

`/api/v1/auth/*` is auth-only (no abilities). Token abilities are stamped at login from Spatie permissions + `'user:read'`. Public registration creates a token with only `'user:read'` (so it cannot reach `/pos/*`). The `pos-access` permission is granted to `cashier`, `manager`, and `super-admin` roles by the seeders. Existing mobile tokens must re-login after this change to receive `pos-access`.

API tests must use `Sanctum::actingAs($user, ['*'])` or real tokens via `$user->createToken('test', $abilities)->plainTextToken`.

## Route naming gotchas

- Price list sidebar link: `price-lists.index` (NOT `settings.price-lists.index`)
- Profile URL: `/dashboard/profile` (NOT `/apps/profile`)
- Public invoice: `/share/transactions/{invoice}?token={access_token}`
