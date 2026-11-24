# PR 5: E2E foundation (issue #42) — Implementation Plan

> **For agentic workers:** execute task by task; each task ends with a check. Steps use `- [ ]`.

**Goal:** `npm run test:e2e` runs a Playwright suite against a real app on MySQL, locally (Docker
service `app-e2e`, port 8010) and in CI (sharded `E2E` jobs), with one logged-in browser state per
role, test data from `E2eSeeder` + `php artisan e2e:arrange`, and fakes for payment gateways and
WhatsApp — all gated to `APP_ENV=e2e`. Ships with one smoke feature (`00-smoke`) proving every main
role can log in. Setup-wizard and auth features follow in the next PR from the test catalog.

**Spec:** `docs/superpowers/specs/2026-11-20-test-suite-design.md` §7–§8. Research notes (facts with
file:line): see the issue #42 thread / PR description.

## Global Constraints

- **E2E-only code never runs in `local`, `testing` or `production`.** `E2eServiceProvider` is
  registered from `AppServiceProvider::register()` only when `$this->app->environment('e2e')`.
  E2E commands live in `app/Testing/E2e/` (NOT `app/Console/Commands/`, which is auto-discovered)
  and are registered by that provider. Every destructive e2e command refuses to run unless
  `app()->environment('e2e')` AND the default connection's database name ends in `_e2e`.
- **Never touch the developer's database** (`point_of_sales`) or `pos_review_*`.
  `php artisan serve` must run with `--no-reload` (otherwise it drops `DB_*` env and uses `.env`).
- Local e2e: Compose service `app-e2e` under profile `e2e`, port **8010** (8001–8004 are used by
  review apps), DB **`point_of_sales_e2e`**, env set in the service (`APP_ENV=e2e`, `DB_*`,
  `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`,
  `BCRYPT_ROUNDS=4`, `INERTIA_SSR_ENABLED=false`, `SECURITY_BOT_GUARD_MIN_SUBMIT_SECONDS=0`,
  `AUTH_FORGOT_PASSWORD_THROTTLE` raised, `MIDTRANS_*`/`XENDIT_*` test values). Real env vars win
  over `.env` (Dotenv is immutable), so the developer's `.env` supplies only `APP_KEY`.
- `E2eServiceProvider` calls `Vite::useHotFile(storage_path('framework/e2e.hot'))` so a running
  `npm run dev` (which writes `public/hot`) never leaks into e2e; e2e always uses `npm run build`.
- Bot guard stays on (honeypot, token); only the minimum submit time is 0 in e2e.
- Playwright `@playwright/test@^1.65` (Node ≥ 20), Chromium only, `locale: 'id-ID'`,
  `serviceWorkers: 'block'`, `workers: 1`, `retries: process.env.CI ? 1 : 0`, `trace:
  'retain-on-failure'`, `screenshot: 'only-on-failure'`, `video: 'retain-on-failure'`.
  An init script sets `localStorage.i18nextLng = 'id'`.
- Selectors: `getByRole`/`getByLabel`/`getByPlaceholder`/`getByText`; login/wizard fields have no
  `htmlFor`, so use placeholder or `[name=…]`. No `data-testid` unless no accessible selector exists.
- Layout: `tests/e2e/` with `playwright.config.js`, `support/`, `features/<NN-feature>/<NN-step>.spec.js`,
  `README.md`, `COVERAGE.md`. Each step file is independent (arranges its own data).
- CI jobs in `.github/workflows/tests.yml`: `E2E (shard i/N)` matrix (N = 2 to start; spec allows
  up to 6 — raise as the suite grows) and a job named exactly `E2E` that merges blob reports into one
  HTML report artifact and fails if any shard failed. No secrets, no deploy, `permissions: contents:
  read`. Use Playwright's documented blob-report sharding (`--shard=i/N`, reporter `blob`, artifact
  `blob-report-<i>`, `merge-reports --reporter html`).
- PR under 1,000 counted lines (lockfile excluded); Conventional Commits, `Refs #42`, no AI attribution.

---

### Task 1: E2E environment on the app side

**Files:** `app/Providers/AppServiceProvider.php` (one guarded registration), `app/Testing/E2e/E2eServiceProvider.php`,
`app/Testing/E2e/ResetCommand.php` (`e2e:reset`), `app/Testing/E2e/ArrangeCommand.php` (`e2e:arrange`),
`database/seeders/E2eSeeder.php`, PHPUnit test `tests/Feature/E2e/E2eGuardTest.php`.

- [ ] `E2eServiceProvider`: registers the two commands; `Vite::useHotFile(...)`; `Http::fake()` for
  `app.sandbox.midtrans.com/*`, `app.midtrans.com/*`, `api.xendit.co/*` (canned charge/invoice with a
  QR string, payment URL and reference) and for the WhatsApp URL the seeder sets
  (`http://wa.e2e.test/*`: status connected, send ok); `Http::preventStrayRequests()` is NOT used
  (other HTTP may be legitimate).
- [ ] `e2e:reset`: refuse unless env `e2e` and DB name ends `_e2e`; then `migrate:fresh --force`,
  `db:seed --force` (essential `DatabaseSeeder`), `db:seed --class=E2eSeeder --force`. Prints one line.
- [ ] `E2eSeeder` (deterministic): outlet `E2E` (sales enabled) + its warehouse, keeps `PUSAT`;
  marks app setup completed; users with password `password` and `completed_tours` set to every tour
  name (`dashboard`, `pos`, `products`, `cashier_shifts`, `reports`): `superadmin@e2e.test`
  (super-admin), `manager@e2e.test` (manager), `cashier@e2e.test` (cashier),
  `opname@e2e.test` (`stock-opnames-access`), `dineorders@e2e.test` (`dine-orders-access`), each
  with `user_outlets` for the E2E outlet (super-admin all); a category, 3 products with warehouse
  stock (`tax_rate = 0`); the outlet's WhatsApp URL `http://wa.e2e.test`; a `PaymentSetting` with
  test Midtrans/Xendit keys. Follow `DemoSeeder`/`SetupController`/existing tests for how these
  records are created — reuse services, don't hand-write SQL.
- [ ] `e2e:arrange <scenario> [--opt=value ...] --json`: a small registry (array of scenario name →
  closure) starting with `open-shift` (opens a shift for `--cashier` with `--cash`), `product`
  (creates a product with `--stock`), printing created IDs as JSON. Unknown scenario → non-zero exit
  and the list of known ones. New scenarios are added by later PRs.
- [ ] `E2eGuardTest`: in `testing` env the provider is not registered and `e2e:reset` /
  `e2e:arrange` are not available; calling the reset command's guard with a non-`_e2e` DB name
  refuses (unit-test the guard method).
- [ ] Check: `php artisan test --filter=E2e` passes (SQLite); full suite still green.

### Task 2: Local runtime

**Files:** `docker-compose.yml` (add `app-e2e` service, profile `e2e`), `package.json` scripts,
`tests/e2e/README.md`.

- [ ] `app-e2e`: same image/build/volumes as `app`, `command: php artisan serve --host=0.0.0.0
  --port=8010 --no-reload`, port `8010:8010`, env from Global Constraints, `depends_on` mysql healthy,
  `profiles: [e2e]`. Do not change `app`, `queue`, `mysql`.
- [ ] npm scripts: `test:e2e` (`playwright test -c tests/e2e/playwright.config.js`),
  `test:e2e:ui`, `e2e:up` (create DB `point_of_sales_e2e` if missing via the mysql service, start
  `app-e2e`, build assets), `e2e:reset` (runs `php artisan e2e:reset` in `app-e2e`). Keep them short;
  a small `tests/e2e/support/cli.js` may hold the "local = docker compose exec -T app-e2e, CI = plain
  php" switch (detect `process.env.CI`).
- [ ] README: prerequisites, `npm run e2e:up`, `npm run e2e:reset`, `npm run test:e2e`, how to add a
  feature/step, how to add an arrange scenario, troubleshooting (port, `public/hot`, `--no-reload`).
- [ ] Check: `npm run e2e:up` then `curl -s -o /dev/null -w '%{http_code}' http://localhost:8010/login`
  → 200, and `docker compose exec -T app-e2e php artisan e2e:reset` succeeds, and the developer DB
  `point_of_sales` is untouched (compare `select count(*) from users` before/after).

### Task 3: Playwright scaffolding and smoke feature

**Files:** `package.json`/lockfile (`@playwright/test`), `.gitignore` (`tests/e2e/.auth/`,
`test-results/`, `playwright-report/`, `blob-report/`), `tests/e2e/playwright.config.js`,
`tests/e2e/support/{roles.js,cli.js,arrange.js,auth.setup.js,fixtures.js}`,
`tests/e2e/features/00-smoke/01-login-each-role.spec.js`, `tests/e2e/COVERAGE.md`.

- [ ] Config: `baseURL` = `E2E_BASE_URL` or `http://localhost:8010`; projects: `setup`
  (`auth.setup.js`: logs in each role from `roles.js` through the real login form and saves
  `tests/e2e/.auth/<role>.json`) and `chromium` (depends on `setup`); `globalSetup` runs
  `e2e:reset` via `cli.js` unless `E2E_SKIP_RESET=1`. Reporter: `list` locally; in CI `blob` + `github`.
- [ ] `fixtures.js`: `test.extend` with `asRole(role)` → a page using that role's storage state,
  `arrange(scenario, opts)` → parsed JSON, `uniq(prefix)`.
- [ ] Smoke steps (`00-smoke/01-login-each-role.spec.js`), data-driven over `roles.js`: super-admin,
  manager, cashier land on `/dashboard/transactions`; module roles land on `/dashboard/access`;
  guest visiting `/dashboard` is redirected to `/login`; logout returns to `/login`.
- [ ] `COVERAGE.md`: table feature → steps → roles, starting with `00-smoke`.
- [ ] Check: `npx playwright install chromium`, `npm run e2e:up`, `npm run test:e2e` → all green,
  locally, twice in a row (idempotent reset).

### Task 4: CI

**Files:** `.github/workflows/tests.yml`, `CONTRIBUTING.md` (CI table rows), `AGENTS.md` (Verify:
one comment line pointing to `tests/e2e/README.md`; ≤ 200 lines).

- [ ] Job `e2e` named `E2E (shard ${{ matrix.shard }}/${{ strategy.job-total }})`, matrix
  `shard: [1, 2]`, `fail-fast: false`, `timeout-minutes: 30`, MySQL 8.4 service with DB
  `point_of_sales_e2e`; steps: checkout, setup-php 8.4 (same extensions as the retired deploy.yml), setup-node 22
  with npm cache, `composer install`, `PUPPETEER_SKIP_DOWNLOAD=true npm ci`, `npm run build`,
  `npx playwright install --with-deps chromium` (cache `~/.cache/ms-playwright` keyed on the
  Playwright version), `cp .env.example .env && php artisan key:generate --force`, start
  `php artisan serve --no-reload --port=8010 &` with the e2e env, wait for `/login` (curl loop, 60 s),
  `php artisan e2e:reset`, `npx playwright test -c tests/e2e/playwright.config.js
  --shard=${{ matrix.shard }}/2`, upload `blob-report` as `blob-report-${{ matrix.shard }}`
  (`if: always()`), upload `storage/logs` on failure.
- [ ] Job `merge`, named `E2E`: `needs: e2e`, `if: always()`; download `blob-report-*` with
  `merge-multiple: true`, `npx playwright merge-reports --reporter html ./all-blob-reports`, upload
  `playwright-report` (14 days), then fail if `needs.e2e.result != 'success'`.
- [ ] Load the `github-actions-hardening` skill and apply it (no `${{ }}` of untrusted input in
  `run:`, least privilege, pinned major versions consistent with the file).
- [ ] CONTRIBUTING CI table: rows for `E2E (shard i/N)` and `E2E` (blocks: "not yet (required after
  its first green run)").
- [ ] Check: governance script tests + `audit.py` pass; push the branch and get both shards and `E2E`
  green on the PR.

### Task 5: Per-feature mechanism (maintainer request, 2026-11-25)

"E2E is split into small features, so when a new feature is built it gets its own e2e tests."

**Files:** `tests/e2e/support/new-feature.js`, `tests/e2e/support/templates/step.spec.js`,
`package.json` script `e2e:new`, `tests/e2e/README.md`, `tests/e2e/COVERAGE.md`.

- [ ] `npm run e2e:new -- <NN-feature-slug> [first-step-slug]` creates
  `tests/e2e/features/<NN-feature-slug>/01-<first-step-slug>.spec.js` from the template (imports the
  shared fixtures, one `test.describe("<feature>")`, one `test.fixme` placeholder that fails review
  if left), and appends a row to `COVERAGE.md`. Refuses if the folder exists or the slug isn't
  `NN-kebab-case`. Plain Node (`node:fs`), no new dependency.
- [ ] README section "Adding e2e for a new feature": one folder per feature, one file per step, the
  step arranges its own data (`arrange()`), add an arrange scenario when needed, update COVERAGE.md,
  the feature PR includes its e2e folder (CI enforcement comes in the follow-up "CI standard" PR).
- [ ] A small self-check: `node tests/e2e/support/new-feature.js --self-test` (or a Vitest test
  under `resources/js`? no — keep it in Node: a tiny `node:test` file `tests/e2e/support/new-feature.test.mjs`
  run by `node --test`) covering slug validation and the created paths in a temp dir.
- [ ] Check: scaffold a throwaway feature into a temp copy, verify, delete; `node --test` passes.
