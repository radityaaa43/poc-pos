# PR 1: Frontend test foundation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development
> (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** `npm test` runs a Vitest suite covering the frontend utilities, and a `Frontend` job runs
it on every PR.

**Architecture:** A standalone `vitest.config.js` (not the Laravel Vite config) with jsdom, the
`@/` alias and a setup file. Tests sit next to the file they test as `*.test.js(x)`. A new
workflow `.github/workflows/tests.yml` holds the `Frontend` job; later PRs add jobs to it.

**Tech Stack:** Vitest 3.2 (supports the repo's Vite 5), jsdom 26 (Node ≥ 18; jsdom 31 needs
Node ≥ 22.22), React Testing Library 16 (React 19), `@testing-library/jest-dom` 6,
`@testing-library/user-event` 14, `fake-indexeddb` 6, `@vitest/coverage-v8` 3.2.

**Spec:** `docs/superpowers/specs/2026-11-20-test-suite-design.md` §6, §8, §9 (PR 1). Roadmap:
`docs/superpowers/plans/2026-11-20-test-suite-roadmap.md`.

## Global Constraints

- Exact devDependency ranges: `vitest@^3.2.7`, `@vitest/coverage-v8@^3.2.7`, `jsdom@^26.1.0`,
  `@testing-library/react@^16.3.3`, `@testing-library/dom@^10.4.2`,
  `@testing-library/jest-dom@^6.10.0`, `@testing-library/user-event@^14.6.13`,
  `fake-indexeddb@^6.2.8`. Install with `PUPPETEER_SKIP_DOWNLOAD=true npm install -D ...` so the
  lockfile is updated with npm (AGENTS.md → npm only).
- No application code changes in this PR. Bugs the tests find go to a `fix/` issue (spec §7.9).
- Tests assert behavior callers see (return values, stored records, bytes, rendered output), not
  internals.
- CI job name is exactly `Frontend`. No secrets, no deploy step. Node 22.
- All repo text in English.

## Review Focus

1. **Offline queue across users or warehouses on one device:** a sale queued under one scope must
   not appear under another, but legacy rows with no scope must stay visible → Task 3,
   `getPendingTransactions filters by scope and keeps legacy rows`.
2. **A sync that died mid-way:** a row stuck in `syncing` for more than 5 minutes must be
   surfaced as failed, not hidden forever → Task 3, `stale syncing rows are reported as failed`.
3. **Pruning must never delete an unsynced sale:** only old `failed`/`conflict` rows go → Task 3,
   `pruneExpiredPendingTransactions only removes old failed and conflict rows`.
4. **Browser without WebUSB (Firefox, Safari):** printing must fail with a clear message, not
   crash → Task 4, `requestPrinter without WebUSB rejects with a clear message`.
5. **A menu entry guarded by a permission that doesn't exist:** the entry would never show for
   anyone but super-admin → Task 5, `every menu permission exists in PermissionSeeder`.

---

## File map

| File | Responsibility |
|---|---|
| `package.json` | devDependencies; scripts `test`, `test:watch`, `test:coverage` |
| `vitest.config.js` | Vitest config: jsdom, `@` alias, setup file, include pattern, coverage |
| `resources/js/test/setup.js` | jest-dom matchers, `fake-indexeddb/auto`, global `route()` stub |
| `resources/js/Utils/imageUrl.test.js` | image URL resolution |
| `resources/js/Utils/authorization.test.js` | `can`, `canAny`, `canAll`, `isSuperAdmin` |
| `resources/js/Utils/offlineDb.test.js` | offline queue and caches |
| `resources/js/Utils/escpos.test.js` | receipt bytes, drawer kick, WebUSB errors |
| `resources/js/Utils/Menu.test.jsx` | menu filtering by permission; menu ↔ seeder cross-check |
| `resources/js/Utils/tours.test.js` | tour steps; selector ↔ page cross-check |
| `resources/js/i18n/locales.test.js` | `id.json` ↔ `en.json` key parity |
| `.github/workflows/tests.yml` | `Frontend` job |
| `.gitignore` | ignore `/coverage` |
| Docs | `AGENTS.md` Verify, `CODING_STANDARDS.md` frontend checks, `docs/agents/project-guide.md`, `.github/governance.json` frontend commands, `.claude/settings.json` allowlist |

---

### Task 1: Vitest tooling and the first test

**Files:**
- Modify: `package.json`, `package-lock.json`, `.gitignore`
- Create: `vitest.config.js`, `resources/js/test/setup.js`
- Test: `resources/js/Utils/imageUrl.test.js`

**Interfaces:**
- Produces: `npm test` (= `vitest run`); global `route(name, params?)` stub in every test returning
  `` `/${name}` `` (Ziggy's `@routes` is a Blade directive and doesn't exist in tests); IndexedDB
  available through `fake-indexeddb/auto`.

- [ ] **Step 1: Install devDependencies** with the exact ranges from Global Constraints.
- [ ] **Step 2: Add scripts** `"test": "vitest run"`, `"test:watch": "vitest"`,
  `"test:coverage": "vitest run --coverage"`.
- [ ] **Step 3: Create `vitest.config.js`** using `@vitejs/plugin-react`; `resolve.alias`
  `{ '@': resolve(__dirname, 'resources/js') }` (ESM: derive `__dirname` from
  `import.meta.url`); `test.environment: 'jsdom'`; `test.setupFiles: ['resources/js/test/setup.js']`;
  `test.include: ['resources/js/**/*.test.{js,jsx}']`; `test.coverage`: provider `v8`, reporters
  `text-summary`, `html`, `json-summary`, `reportsDirectory: 'coverage/frontend'`, include
  `resources/js/**/*.{js,jsx}`.
- [ ] **Step 4: Create `resources/js/test/setup.js`**: import `@testing-library/jest-dom/vitest`
  and `fake-indexeddb/auto`; define `globalThis.route`.
- [ ] **Step 5: Write `imageUrl.test.js`**:
  - `getImageUrl(null)` → `null`; `getImageUrl("")` → `null`
  - `"default.jpg"` and `"products/default.jpg"` → `"/images/product-placeholder.svg"`
  - `"https://cdn.x/a.png"`, `"http://x/a.png"`, `"/storage/products/a.png"` → unchanged
  - `getImageUrl("a.png")` → `"/storage/products/a.png"`; `getImageUrl("a.png", "categories")` →
    `"/storage/categories/a.png"`
  - `getProductImageUrl(null)` → `"/images/product-placeholder.svg"`
- [ ] **Step 6: Run** `npm test`. Expected: 1 file, all pass.
- [ ] **Step 7: Add `/coverage` to `.gitignore`. Commit** `test(frontend): add Vitest tooling`.

### Task 2: Authorization helpers

**Files:** Test: `resources/js/Utils/authorization.test.js`

- [ ] **Step 1: Write tests** (pure functions; no React):
  - `resolveAuthorizationState(undefined)` → `{ permissions: {}, super: false }`
  - `can("x", { permissions: { x: true } })` → `true`; `{ x: false }` → `false`; missing → `false`
  - `can("anything", { super: true })` → `true`; `{ super: "true" }` (string) → `false`
  - `canAny([], auth)` and `canAny("x", auth)` → `false`; `canAny(["a","b"], {permissions:{b:true}})` → `true`
  - `canAll(["a","b"], {permissions:{a:true}})` → `false`; both true → `true`; `canAll([], {super:true})` → `false`
  - `isSuperAdmin({ super: true })` → `true`
- [ ] **Step 2: Run** `npx vitest run resources/js/Utils/authorization.test.js`. Expected: PASS
  (characterizes existing code; a failure is a bug → spec §7.9).
- [ ] **Step 3: Commit** `test(frontend): cover authorization helpers`.

### Task 3: Offline queue

**Files:** Test: `resources/js/Utils/offlineDb.test.js`

The module opens the database once at import. `beforeEach` calls `clearPendingTransactions()`.
For time, use `vi.useFakeTimers({ toFake: ['Date'] })` + `vi.setSystemTime(...)` — faking all
timers would stall fake-indexeddb.

- [ ] **Step 1: Write tests**:
  - `queueTransaction stores a normalized pending record`: queue `{ client_uuid: "u-1", total: 5 }`
    with scope `"user1:wh1"`; the row has `status "pending"`, `attempts 0`, `client_uuid "u-1"`,
    `scope_key "user1:wh1"`, `last_error null`, and a `created_at` ISO string.
  - `getPendingTransactions filters by scope and keeps legacy rows`: rows for `"user1:wh1"`,
    `"user2:wh1"` and one put directly with no `scope_key`; querying `"user1:wh1"` returns the first
    and the legacy row only.
  - `stale syncing rows are reported as failed`: `status "syncing"`, `last_attempt_at` 6 minutes
    ago → returned with `status "failed"`, `last_error "Sync sebelumnya terhenti."`; 1 minute ago →
    still `"syncing"`.
  - `getPendingCount ignores synced and duplicate rows`: statuses pending, failed, synced,
    duplicate → `2`.
  - `pruneExpiredPendingTransactions only removes old failed and conflict rows`: with
    `maxAgeDays 30`, `failed` and `conflict` created 31 days ago are deleted; `failed` 29 days ago,
    and `pending` and `syncing` 40 days ago, remain.
  - `updatePendingTransaction merges changes` (`{ status: "synced", attempts: 1 }` keeps `data`);
    unknown id → resolves without throwing and changes nothing.
  - `removePendingTransaction` and `clearPendingTransactions` empty the queue.
  - `cacheProducts round-trips and overwrites by id`: cache `[{id:1,name:"A"}]` then
    `[{id:1,name:"B"},{id:2,name:"C"}]` → `getCachedProducts()` has 2 rows, id 1 named `"B"`. Same
    for customers.
- [ ] **Step 2: Run** the file. Expected: PASS.
- [ ] **Step 3: Commit** `test(frontend): cover the offline transaction queue`.

### Task 4: ESC/POS receipt bytes and printer errors

**Files:** Test: `resources/js/Utils/escpos.test.js`

Decode bytes with `new TextDecoder().decode(bytes)` to assert text. `money` in the data is
`(n) => String(n)`.

- [ ] **Step 1: Write tests**:
  - starts with `[0x1b, 0x40]` and ends with `[0x1d, 0x56, 0x42, 0x00]`
  - `"58mm"` separator is 32 `-`; default and `"80mm"` are 48
  - store name defaults to `TOKO`; footer defaults to `Terima kasih!`
  - `leftRight` lines are exactly the paper width: `"TOTAL"` + spaces + grand total has length 48
    on 80 mm
  - `Diskon`, `PPN`, `Ongkir`, `Kembali` lines appear only when their amount is `> 0`; `Tunai`
    appears when `cash_received` is `0` (not null) and not when it is `null`
  - item names longer than the width are cut to the width
  - `drawerKickBytes()` → `[27, 112, 0, 25, 250]`; `drawerKickBytes(5)` → `[27, 112, 1, 25, 250]`
  - `requestPrinter without WebUSB rejects with a clear message`: with `navigator.usb` undefined →
    rejects with `"WebUSB tidak didukung browser ini. Gunakan Chrome/Edge."`
  - `printBytes` with a stub `navigator.usb.getDevices` resolving `[]` → rejects with
    `"Printer belum terhubung."`
  - `hasCachedPrinter()` → `false` before any connection
  - with a stub device (one interface, alternate `interfaceClass 7`, endpoint
    `{ direction: "out", endpointNumber: 2 }`), `printReceipt` sends the receipt bytes to
    `transferOut(2, bytes)`; when `transferOut` throws, `printBytes` rejects with
    `"Gagal mengirim data ke printer. Periksa koneksi printer."` and `hasCachedPrinter()` becomes
    `false`
- [ ] **Step 2: Run** the file. Expected: PASS.
- [ ] **Step 3: Commit** `test(frontend): cover ESC/POS receipt bytes and printer errors`.

### Task 5: Sidebar menu

**Files:** Test: `resources/js/Utils/Menu.test.jsx`

`Menu()` calls hooks (`usePage`, `useTranslation`, `useAuthorization`), so call it through
`renderHook` from RTL. Mock `@inertiajs/react`'s `usePage` with `vi.mock` to return
`{ url, props: { auth } }`. `useTranslation` without a provider returns keys; that is fine.

- [ ] **Step 1: Write tests**:
  - with `auth.super = true`, every item in every section has `permissions === true`
  - with `auth.permissions = { "categories-access": true }`, exactly one item has
    `permissions === true` and its `href` is `"/categories.index"` (the route stub)
  - with `url "/dashboard/categories"`, that item has `active === true` and the dashboard item
    `active === false`
  - there are 46 items and no two share an `href`
  - `every menu permission exists in PermissionSeeder`: read `Menu.jsx` and
    `database/seeders/PermissionSeeder.php` with `node:fs`, collect the strings inside
    `hasAnyPermission([...])` and the quoted permission names in the seeder; the first set is a
    subset of the second
- [ ] **Step 2: Run** the file. Expected: PASS (checked before planning: 47 permissions, all
  seeded; 46 unique hrefs).
- [ ] **Step 3: Commit** `test(frontend): cover the sidebar menu permissions`.

### Task 6: Tours and i18n parity

**Files:** Test: `resources/js/Utils/tours.test.js`, `resources/js/i18n/locales.test.js`

- [ ] **Step 1: Open the bug issue first.** The parity check finds one mismatch today: `id.json`
  has `sidebar.items.outlets`, `en.json` has `sidebar.menu.outlets`, and `Menu.jsx` uses
  `sidebar.items.outlets`, so the English sidebar falls back to Indonesian. Create issue
  "English sidebar shows the Outlets entry in Indonesian" (`module:platform`, `needs-triage`) and
  note its number `#F`.
- [ ] **Step 2: Write `locales.test.js`**: flatten both files to dotted keys; the symmetric
  difference equals `KNOWN_GAPS = ["sidebar.items.outlets", "sidebar.menu.outlets"] // #F`; no value
  is an empty string. A comment says the fix PR for `#F` empties `KNOWN_GAPS`.
- [ ] **Step 3: Write `tours.test.js`**: for `dashboardTour`, `posTour`, `productsTour`,
  `cashierShiftsTour`, `reportsTour`: at least one step; every step has an `element` of the form
  `[data-tour='…']` and a non-empty popover `title` and `description` that do not start with
  `tour.` (the key was resolved). Cross-check: every `data-tour` value in `tours.js` appears as
  `data-tour="…"` in some `resources/js/**/*.jsx` file other than `tours.js` (checked before
  planning: 25 selectors, all present).
- [ ] **Step 4: Run** `npm test`. Expected: all files PASS.
- [ ] **Step 5: Commit** `test(frontend): cover tours and i18n key parity`.

### Task 7: CI job and docs

**Files:**
- Create: `.github/workflows/tests.yml`
- Modify: `AGENTS.md` (Verify block), `CODING_STANDARDS.md` (frontend "Checks" bullet),
  `docs/agents/project-guide.md` (Quick start note "no frontend lint or typecheck"),
  `.github/governance.json` (`verify.areas[frontend].commands` adds `"npm test"`),
  `.claude/settings.json` (allow `Bash(npm test:*)`)

- [ ] **Step 1: Create `tests.yml`**: `name: Tests`; on `pull_request` to `main` and `push` to
  `main`; `concurrency: tests-${{ github.ref }}` with `cancel-in-progress: true`;
  `permissions: contents: read`. Job `frontend`, `name: Frontend`, `runs-on: ubuntu-latest`:
  checkout; `actions/setup-node@v4` with `node-version: '22'` and `cache: npm`;
  `PUPPETEER_SKIP_DOWNLOAD=true npm ci --no-audit --no-fund`; `npm run test:coverage`; append the
  `coverage/frontend/coverage-summary.json` totals (lines, statements, branches, functions %) to
  `$GITHUB_STEP_SUMMARY`; upload `coverage/frontend` as artifact `frontend-coverage` (`if: always()`,
  retention 14 days).
- [ ] **Step 2: Verify locally** that `PUPPETEER_SKIP_DOWNLOAD=true npm ci` succeeds with the
  updated lockfile, then `npm run test:coverage` writes `coverage/frontend/coverage-summary.json`.
- [ ] **Step 3: Update the docs** listed above so they say frontend tests exist and how to run
  them (`npm test`, `npm run test:watch`; tests sit next to the file as `*.test.js(x)`).
- [ ] **Step 4: Run** governance checks: `PYTHONUTF8=1 python -m unittest discover -s .github/scripts -p 'test_*.py'`
  and `python ~/.claude/skills/code-governance/scripts/audit.py . --tier full`. Expected: OK and
  no FAIL.
- [ ] **Step 5: Commit** `ci: run frontend tests on every PR`. Include the spec, the roadmap and
  this plan in the PR (`docs/superpowers/`).
- [ ] **Step 6: Open the PR** (`Closes #<PR1 issue>`, `Backlog: none — test infrastructure`),
  wait for the `Frontend` job to pass, and record its coverage summary in the PR.
