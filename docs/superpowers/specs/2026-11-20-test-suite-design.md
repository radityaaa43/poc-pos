# Design: full backend, frontend and end-to-end test suite in CI

- **Date:** 2026-11-20
- **Status:** draft for the maintainer's approval
- **Requester:** `@radityaaa43`
- **Module:** `module:platform`

## 1. Intent

Every feature, flow, role and sub-feature of poc-pos must be checked automatically on every pull
request, so that a change that breaks something cannot reach `main`. The maintainer asked for it
"truly end to end": the real app in a real browser, as each real role.

The end-to-end scripts are organized **per feature and per step**, so that when a feature changes,
the developer (or agent) adds or edits the step file for that feature instead of touching a large
shared script.

## 2. Goals, non-goals, anti-goals

**Goals**

1. Three layers, all run in GitHub Actions on every PR into `main` and on every push to `main`:
   backend (PHPUnit), frontend (Vitest), end-to-end (Playwright).
2. E2E covers all 36 modules, the public flows (first-install setup, dine-in QR menu, customer
   portal, public invoice), and every role: `super-admin`, `manager`, `cashier`, the per-module
   roles (e.g. `dine-tables-access`), and an unauthenticated visitor.
3. Every role × module pair is checked for access: allowed roles see the menu entry and the page,
   denied roles see neither (403 or redirect).
4. A failed run leaves enough evidence to diagnose without re-running: Playwright trace,
   screenshot and video of the failure, HTML report, coverage report.
5. Adding a test for a changed feature is a one-file change in a predictable place.

**Non-goals**

- Real payment gateways, a real WhatsApp device, a real thermal printer, a real camera. Those stay
  in `docs/testing-manual.md`. CI never holds real credentials.
- Visual-regression (pixel diff) testing, load or performance testing, cross-browser runs beyond
  Chromium, mobile device emulation of the separate mobile app.
- Testing the WhatsApp Node service in isolation (`whatsapp-service/` has no logic beyond proxying;
  it is faked at its HTTP boundary).

**Anti-goals** (would make this a failure even if it technically works)

- A flaky suite that people learn to re-run or ignore.
- A suite so slow that PRs stall: the wall-clock target for the whole CI run is **≤ 25 minutes**.
- Tests that only exercise the fakes, or that assert implementation details instead of what a user
  sees.
- Changes to application behavior made only to make tests pass. Test-only hooks are confined to
  `APP_ENV=e2e` and never active in `local`, `testing` or `production`.

## 3. Constraints

- **PR size:** governance fails a PR over 2,000 counted lines and wants one under 1,000 to be
  split. This work ships as a sequence of PRs (§9), each with its own issue.
- **Required checks:** `main` is protected by ruleset 27731300 (PR checklist, Standards approval,
  PR size, File length, CI (Build)); since 2026-11-25 (#61) Backend, Frontend and E2E are required
  instead of CI (Build). New test jobs become required only after they have run green
  at least once, and changing the ruleset needs the maintainer's go-ahead.
- **No deploy:** CI/CD must never deploy to the VPS (ADR 0001). Test workflows have no secrets.
- **Public repository:** GitHub Actions minutes are free on standard `ubuntu-latest` runners, which
  makes sharding affordable.
- **Upstream sync:** the code is a fork of `aryadwiputra/point-of-sales`. Keep edits to upstream
  application files minimal; new test code lives in new files.
- **Local development** runs in Docker on Windows (`docker compose exec -T app ...`). Every test
  command must also run locally. The Claude Code guard hook asks before `migrate:fresh` and
  `db:seed`; the e2e reset runs against the dedicated e2e database only (§6.3) and is a script, not
  an ad-hoc command.
- **Tests on SQLite** today: `phpunit.xml` forces in-memory SQLite. Production is MySQL 8.4.

## 4. Approaches considered

| Layer | Considered | Chosen | Why |
|---|---|---|---|
| Backend | PHPUnit (existing), Pest | **PHPUnit** | 590 tests already exist; no reason to migrate |
| Frontend | Vitest + React Testing Library, Jest | **Vitest + RTL** | Repo already builds with Vite; no Babel setup; `fake-indexeddb` covers the offline queue |
| E2E tool | Playwright, Laravel Dusk, Cypress | **Playwright (Chromium)** | Parallel and sharded out of the box, traces, `page.route`, offline emulation, `addInitScript` for WebUSB stubs; Dusk is slower and serial, Cypress parallelism is paid |
| When e2e runs | A: full suite every PR, sharded · B: smoke + affected on PR, full nightly · C: nightly only | **A** (maintainer's pick) | Catches cross-feature breakage (e.g. a recipe change breaking POS and dine-in); minutes are free; same tests would allow B later by changing only the workflow |

Tool choices are the designer's call (the maintainer talked about outcomes, not tools). The CI
timing was the maintainer's decision.

## 5. Backend layer

**Keep:** the 88 PHPUnit files and their conventions (`docs/agents/project-guide.md` → Test
conventions).

**Add:**

1. **MySQL run.** A CI job runs the full suite against a MySQL 8.4 service container in addition to
   the existing SQLite run. Override `DB_CONNECTION`/`DB_DATABASE` through env in the job;
   `phpunit.xml` stays as is so local runs keep using SQLite. Tests that fail only on MySQL are real
   bugs (production parity) and are fixed, not skipped.
2. **Coverage.** The MySQL job (or a third job) runs with `pcov` and publishes a Clover/HTML report
   as an artifact and a line-coverage summary on the job. **No coverage threshold gate at first**
   (designer's call): record the baseline, then add a "no decrease" ratchet in a later PR.
3. **Gap tests.** Services with no test today: `AuditLogService`, `BatchService`,
   `CrmAutomationService`, `CustomerSegmentationService`, `DineOrderService`,
   `GoodsReceivingService`, `PayableAgingService`, `PriceListService`, `ReceivableService`,
   `ReorderService`, `StockMutationService`, `TransactionTenderService`, `UnitConversionService`,
   `MidtransGateway`, `XenditGateway`; and the Import/Export controller. Gateways are tested with
   `Http::fake()` (request shape, response mapping, error mapping). Webhook endpoints are tested
   with valid and invalid signatures. Some services may already be covered indirectly through
   feature tests; the builder checks coverage before writing duplicates.
4. **Unit suite.** Pure logic (pricing math, unit conversion, HPP, tax) gets fast unit tests where
   it is extractable without changing application structure.

## 6. Frontend layer

**Tooling:** `vitest`, `@testing-library/react`, `@testing-library/user-event`,
`@testing-library/jest-dom`, `jsdom`, `fake-indexeddb`, added as devDependencies with
`npm install` (lockfile updated in the same PR). A `vitest.config.js` reuses the `@/` alias.
Scripts: `npm test` (run once) and `npm run test:watch`.

**Location:** colocated `*.test.js(x)` next to the file under test in `resources/js/`.

**What gets unit/component tests** (logic a browser test would reach only slowly or not at all):

- `Utils/offlineDb.js` (queue, flush, idempotency by `client_uuid`), `Utils/escpos.js` (byte
  output for 58 mm / 80 mm, fallback when `navigator.usb` is missing), `Utils/authorization.js`,
  `Utils/imageUrl.js`, `Utils/Menu.jsx` (entries filtered by permission), `Utils/tours.js`.
- `Hooks/useBarcodeScanner.js`, `Hooks/useTour.js`, `Context/OnlineStatusContext.jsx`,
  `Context/ThemeSwitcherContext.jsx`.
- POS components: `CartPanel`, `PaymentPanel` (split tenders, change due), `NumpadModal`,
  `ProductGrid` (portions, out-of-stock), `SearchBar`, `CustomerSelect`, `HeldTransactions`.
- Shared form components (`TextInput`, `InputError`, `Modal`, `Dropdown`, buttons): rendering and
  accessibility basics.
- **i18n parity:** every key in `i18n/locales/id.json` exists in `en.json` and vice versa.

**Not here:** full page behavior (the 114 Inertia pages). Pages are exercised by e2e with real
server props; mocking Inertia props per page would duplicate e2e at high cost.

## 7. End-to-end layer

### 7.1 Layout

```
tests/e2e/
  playwright.config.js
  README.md                     how to run, how to add a step, conventions
  COVERAGE.md                   feature → steps → roles, kept current by each PR
  support/
    fixtures.js                 test fixtures: per-role logged-in pages, arrange(), uniq()
    roles.js                    role list and credentials for the e2e dataset
    arrange.js                  calls `php artisan e2e:arrange <scenario> --json`
    webhooks.js                 builds signed Midtrans/Xendit webhook payloads
    stubs/                      browser stubs (navigator.usb, camera)
  features/
    00-access/role-matrix.spec.js        every role × module: menu + page allowed or denied
    01-setup/01-first-install-wizard.spec.js
    02-auth/01-login.spec.js  02-logout.spec.js  03-profile.spec.js  ...
    10-pos/01-open-shift.spec.js  02-add-to-cart.spec.js  03-checkout-cash.spec.js
           04-checkout-split-tender.spec.js  05-hold-and-resume.spec.js  ...
    11-recipes/01-sell-recipe.spec.js  02-raw-material-hidden.spec.js  ...
    ...                          one folder per feature, one file per step
```

- **Folder per feature**, numbered so the report reads in business order. **File per step**,
  numbered in flow order.
- **Each step file is independent.** It arranges its own preconditions (§7.3) instead of relying
  on the previous step having run, so a step can be run alone, in any order, on any shard. The
  numbering is for reading, not for execution order. (Designer's call: chained steps would make
  one failure cascade through a feature and would break sharding.)
- Tags: `@smoke` on a handful of critical steps (login, checkout cash, dine-in order), `@serial`
  on steps that change global state (§7.4), `@quarantine` for a step disabled with a linked issue.

### 7.2 Feature inventory

Derived from the 36 `*-access` permissions, `docs/feature-index.md` and the public routes. Each
becomes a folder; the step list per folder is written by the builder from the feature doc in
`docs/features/` and the existing feature tests.

| Group | Feature folders |
|---|---|
| Platform | setup wizard, auth (login, logout, password reset, profile), dashboard, language switch, guided tours, notifications, users, roles, permissions, outlets and outlet context switching, warehouses, units, settings (store profile, tax, printer, sales target, payment settings, WhatsApp settings), audit logs, import/export |
| Sales / POS | cashier shifts (open, cash movements, close, force close), POS cart, checkout (cash, transfer, QRIS, split tender, receivable/credit), held transactions, transactions list and detail, print and share invoice, sales returns, discount approvals, offline mode, receivables and aging |
| Inventory | categories, products (simple, multi-unit, composite/recipe, raw material, batches/expiry), stock mutations, stock opname, stock transfers (draft → in transit → completed/cancelled), reorder suggestions, low-stock alerts |
| Purchasing | suppliers, purchase orders, goods receiving, supplier returns, payables and payments |
| Pricing and CRM | price lists, pricing rules (qty break, bundle, buy-get), customer vouchers, customers and regions, members and loyalty points, customer segments, CRM campaigns and logs, CRM reminders |
| Dine-in | dine areas, tables and QR, guest menu order (`/dine/{token}`), dine orders accept/reject/process into a transaction |
| Reports and documents | sales report, profit report, insights, PDF documents, exports |
| Public | customer portal (transaction, pay receivable), public invoice (`/share/transactions/...`) |

### 7.3 Test data

- **Database:** a dedicated MySQL database for e2e (`point_of_sales_e2e` locally; the service
  container in CI). Never the developer's working database.
- **Baseline:** `migrate:fresh` + the essential `DatabaseSeeder` + a new **`E2eSeeder`** that builds
  a small, deterministic dataset: two outlets (one sales outlet, one central `PUSAT`), one user per
  role with known e2e-only passwords, a few products of each kind. Designer's call: not the
  `DemoSeeder`, whose contents change with demos and would make assertions brittle. The setup
  wizard test runs against an empty database instead (its own scenario).
- **Per-step arrangement:** `php artisan e2e:arrange <scenario> [--option=value] --json` creates
  the data a step needs (e.g. `open-shift --cashier=...`, `product-with-recipe`, `pending-po`) and
  prints the created IDs as JSON. The command is registered only when `APP_ENV=e2e`. Playwright
  calls it through `support/arrange.js`, which prefixes `docker compose exec -T app` locally and
  runs it directly in CI.
- **Uniqueness:** steps that create records through the UI use a `uniq()` suffix so parallel steps
  in one database don't collide.
- **Reset between runs** (not between tests): the baseline is rebuilt at the start of each run (and
  each CI shard) by Playwright's global setup.
- **Local runtime** (designer's call): a separate Compose service `app-e2e` under a `e2e` profile
  (`docker compose --profile e2e up -d app-e2e`), on port 8001, with `APP_ENV=e2e` and
  `DB_DATABASE=point_of_sales_e2e` set in the service environment. `support/arrange.js` runs
  `docker compose exec -T app-e2e php artisan e2e:arrange ...` locally and plain `php artisan ...`
  in CI (detected by `CI=true`); Playwright's `baseURL` is `E2E_BASE_URL` (default
  `http://localhost:8001`). The developer's `app` service and `point_of_sales` database are never
  touched. As a second guard, the reset script and `e2e:arrange` refuse to run unless
  `APP_ENV=e2e` and the database name ends in `_e2e`.

### 7.4 Isolation and parallelism

- **CI:** each shard is its own job with its own MySQL service and its own app server, so shards
  never share data.
- **Within a shard:** `workers: 1` at first (designer's call, for stability). Raise to 2 only once
  the suite is green and stable for two weeks.
- **Global-state steps** (`@serial`: tax rate, payment settings, store profile, setup wizard,
  permission edits) run in a separate Playwright project after the parallel ones, so they can't
  change what other steps see mid-run.

### 7.5 Faking external systems

| System | How | Where |
|---|---|---|
| Midtrans, Xendit | `Http::fake()` registered for their hosts when `APP_ENV=e2e`, returning canned charge/invoice responses (QR string, payment URL, reference) | a new `E2eServiceProvider`, loaded only in `e2e` |
| Payment confirmation | the test posts a correctly signed webhook to `/api/webhooks/{midtrans,xendit}` using the e2e gateway keys | `support/webhooks.js` |
| WhatsApp service | `Http::fake()` for `WA_SERVICE_URL` (status connected, send ok) | same provider |
| Thermal printer (WebUSB) | `addInitScript` stub of `navigator.usb` that records written bytes; also a run with no `navigator.usb` for the `window.print()` fallback | `support/stubs/` |
| Camera barcode scanner | not driven; steps use the manual barcode input that the scanner fills | — |
| Offline | `context.setOffline(true)` | — |

### 7.6 Role matrix

`00-access/role-matrix.spec.js` is data-driven: a table of module → index route → menu label →
required permission, crossed with **every** seeded role (about 40: `super-admin`, `manager`,
`cashier`, the per-module `*-access` roles and the action roles) plus an unauthenticated visitor.

- Per role: one login, one dashboard render to assert which menu entries are present, then a
  request (`page.request.get` with the role's session) per module route to assert allowed (200) or
  refused (403 or redirect to login). About 40 renders plus about 1,500 requests: a few minutes on
  one shard, so the literal "every role" fits the 25-minute budget (designer's call).
- **Expected results** come from a committed table, `support/role-matrix.expected.json`
  (role → module → allowed/refused), not from the seeder at run time. The builder generates it once
  from the current `RoleSeeder`; the maintainer reviews and corrects it in the PR that adds it, and
  that review is the gate for merging that PR. A grant the maintainer rejects becomes a `fix/` issue
  (§7.9). After that, any difference between the table and the running app fails the build, so a
  permission change needs a matching table change that someone approves.
- The table lives in `support/` and gains a row whenever a module is added.

### 7.7 Assertions and selectors

- Assert what the user sees: text, totals, status badges, toast messages, and the resulting record
  in a list. Where a total or stock number matters, also read it back through the UI, not the DB.
- Selectors: `getByRole`/`getByLabel`/`getByText` first. Add `data-testid` attributes to
  application markup only where no accessible selector exists, in the same PR as the step that
  needs it.
- The UI language for e2e is fixed to Indonesian (the default for users); assertions use the
  `id.json` strings.

### 7.8 Evidence and flake policy

- On failure: trace, screenshot and video retained; HTML report uploaded per shard and merged into
  one report artifact.
- `retries: 1` in CI. A test that passes only on retry is reported as flaky in the job summary.
- A step that is flaky twice in a week gets `@quarantine` with an issue link and an owner, and is
  fixed or deleted within the next PR touching that feature. Quarantined steps are listed in the
  job summary so they stay visible.

### 7.9 Application bugs the tests find

Follows `AGENTS.md` → Bugs, which already decides this: a bug found while writing a test is not
fixed inside the test PR. The test PR keeps the step that exposes it, tagged `@quarantine` with a
link to a new `fix/<issue>-<slug>` issue; the fix PR removes the tag, so the step is the failing
test that proves the fix. A MySQL-only backend failure works the same way (the test is skipped on
MySQL only, with the issue link). This keeps each test PR bounded and each fix reviewable.

## 8. CI

New workflow `.github/workflows/tests.yml`. (Originally `deploy.yml` → `CI (Build)` was kept as the
build + SQLite backend run; #61 retired it once Backend and Frontend covered the same ground, so the
tests run on MySQL only.)

| Job | Runs | Notes |
|---|---|---|
| `Backend (MySQL)` | PHPUnit on MySQL 8.4 with pcov coverage | coverage artifact + summary |
| `Frontend` | `npm ci`, `npm test` | Vitest, coverage summary |
| `E2E (shard i/N)` | build assets, MySQL service, `php artisan serve` in `e2e` env, Playwright shard i of N | N = 6 at first, tuned to keep ≤ 25 min |
| `E2E` | needs all shards; merges reports; fails if any shard failed | the single check made required |

- Caches: Composer, npm, Playwright browsers.
- Triggers: `pull_request` into `main` and `push` to `main`. No secrets, no deploy step.
- `concurrency` cancels superseded runs on the same PR.
- After the jobs have run green once, the maintainer is asked to add `Backend (MySQL)`, `Frontend`
  and `E2E` to the ruleset's required checks.

## 9. Delivery: sequence of PRs

Each line is one issue and one PR, all under 1,000 counted lines where possible.

1. **Frontend foundation:** Vitest setup, `Frontend` job, i18n parity, Utils tests.
2. **Frontend components:** POS components, hooks, contexts, shared form components.
3. **Backend in CI:** `Backend (MySQL)` job, pcov coverage, fix any MySQL-only failures found.
4. **Backend gaps (2–3 PRs):** the services, gateways, webhooks and Import/Export listed in §5.
5. **E2E foundation:** Playwright config, `support/`, `E2eSeeder`, `e2e:arrange`,
   `E2eServiceProvider`, the `app-e2e` Compose service, sharded CI jobs, README and COVERAGE, first
   features: setup wizard and auth. Also sets `verify.e2e` and `e2e_in_ci: true` in
   `.github/governance.json` so `AGENTS.md` → Verify lists the e2e command (a Standards change,
   approved by the maintainer as Standards owner).
6. **E2E role matrix** (`00-access`).
7. **E2E by group, one PR per group** (§7.2): Sales/POS (likely 2 PRs), Dine-in, Inventory,
   Purchasing, Pricing and CRM, Platform administration and settings, Reports and documents, Public
   flows, Offline and printer.
8. **Required checks and rules:** ask the maintainer to update the ruleset; a Standards PR adding
   to `AGENTS.md`/`CONTRIBUTING.md`/PR template that a feature change must add or update its e2e
   step and `COVERAGE.md`.

## 10. Decided by the maintainer

- Tool choice per layer was left to the designer; the full e2e suite runs on every PR (option A).
- Role-matrix expected results: a table generated once from `RoleSeeder`, then reviewed and
  corrected by the maintainer; differences fail the build (§7.6).

## 11. Left to the builder

- The exact step list inside each feature folder, derived from `docs/features/*.md` and the
  existing feature tests.
- `E2eSeeder` contents and the `e2e:arrange` scenario catalog, grown as steps need them.
- Shard count and Playwright timeouts, tuned against the 25-minute target.
- Which components need `data-testid`.
- Whether a backend service is already covered indirectly (check coverage first).
