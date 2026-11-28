# End-to-end tests (Playwright)

A real browser drives the real app on MySQL. Test data comes from `E2eSeeder` plus
`php artisan e2e:arrange`; payment gateways and WhatsApp are faked. Design:
`docs/superpowers/specs/2026-11-20-test-suite-design.md` §7–§8. What is covered: each feature folder's own `COVERAGE.md` (steps, roles, Manual checks).

Everything e2e-only is gated to `APP_ENV=e2e` (`app/Testing/E2e/`): the reset and arrange commands
and `E2eSeeder` refuse to run unless `APP_ENV` is `e2e` and the default connection's database name
ends in `_e2e`. That guard checks names only: it stops a mis-set environment from wiping
`point_of_sales` or a review app database, but not someone who deliberately names a real database
`*_e2e`. (The PHPUnit suite is the one exception: it runs `E2eSeeder` on in-memory SQLite.)

## Run it locally

Everything runs on the host. Prerequisites: the root `README.md` → Local setup (MySQL 8.4 on
`127.0.0.1:3306`, `root` with an empty password, `php` and `mysql` on `PATH`), Node 22+, and
`composer install` and `npm ci` in this checkout.

```bash
npx playwright install chromium   # once
npm run e2e:up                    # creates the DB, serves http://localhost:8010, builds assets, resets the DB
npm run test:e2e                  # whole suite (resets the DB first)
npm run test:e2e -- features/00-smoke          # one feature
npm run test:e2e:ui               # Playwright UI mode
npm run e2e:down                  # stop the server when done
```

- `npm run e2e:reset` rebuilds `point_of_sales_e2e` (migrate, seeders). `test:e2e` does it for you
  unless `E2E_SKIP_RESET=1`.
- `E2E_BASE_URL` points the suite at another host (default `http://localhost:8010`).
- Test users are `*@e2e.test` with password `password` (see `support/roles.js`, `E2eSeeder`).
- After a failure open the report: `npx playwright show-report`, or the trace in `test-results/`.

### Several worktrees at once

`e2e:up` serves one checkout on port 8010 with one database. A second worktree runs its own server on
another port and `*_e2e` database (ports 8000–8004 and 8010 are taken), with its own `composer install`
and `npm ci`. Write a wrapper outside the repo, here `../e2e-php-8011.sh`: it `export`s every value of
`support/e2e-env.js`, with `APP_URL=http://localhost:8011` and `DB_DATABASE=pos_8011_e2e`, and ends with
`exec php "$@"`. Then, from the worktree:

```bash
mysql -uroot -h127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS pos_8011_e2e"
npm run build
bash ../e2e-php-8011.sh artisan serve --host=127.0.0.1 --port=8011 --no-reload   # own terminal, Ctrl+C stops it
E2E_PHP="bash ../e2e-php-8011.sh" E2E_BASE_URL=http://localhost:8011 npm run test:e2e
```

The app env is set in `support/e2e-env.js` (real env vars beat `.env`): session/cache in the database,
`QUEUE_CONNECTION=sync`, bot guard on but with no minimum submit time, low bcrypt cost, no SSR. The
bot-guard honeypot and token stay on. On Windows, PHP's built-in server handles one request at a time.

## In CI

`.github/workflows/tests.yml`: `E2E (shard i/N)` jobs run the same suite against a MySQL service
container (`--shard=i/N`, blob reporter); the `E2E` job merges the blob reports into one HTML report
artifact and fails if any shard failed. The suite runs in three shards (`shard: [1, 2, 3]`), always the whole
suite split between them, never a subset; to change the number of shards, edit the `shard` matrix only (the job
names and `--shard` both follow it). The ruleset requires the aggregate `E2E` check, not the shard jobs. Each
shard job resets its own database.

## Adding e2e for a new feature

One folder per feature, one file per step, and the feature's PR carries its own folder.

```bash
npm run e2e:new -- 08-purchase-orders create-order   # step slug is optional (default: happy-path)
```

This creates `features/08-purchase-orders/01-create-order.spec.js` from `support/templates/step.spec.js`
(a `test.fixme` placeholder: replace it) and the folder's own `COVERAGE.md` (fill it in; one note per folder, so PRs on different features never conflict). Then:

- A step arranges its own data and never depends on another step: call `arrange('open-shift', { cash: 100000 })`
  and use `uniq('product')` for names, so reruns and shards do not collide.
- Specs must not depend on folder order. Shared data created by other specs (outlets, warehouses) may exist,
  so assert on the cell you mean (`warehouseCell` in `support/inventory.js`), not on a whole row's text.
- A scenario that creates an outlet or warehouse must give it a code that sorts after `E2E` / `WH-E2E`:
  the super admin's default outlet and the first warehouse are the lowest code, and other features' steps rely on them.
- Need data no scenario creates? Add a closure to `app/Testing/E2e/Scenarios.php` (name -> options in,
  created ids out) and call it with `arrange('<name>', { ... })`.
- `asRole(role)` gives a page already signed in as a role from `support/roles.js`. Do not log out
  with a shared role: it kills the saved session. Log in with `login(page, email)` in that step instead.
- Selectors: `getByRole`, `getByLabel`, `getByPlaceholder`, `getByText`. Login and wizard fields have no
  label association: use the placeholder or `input[name=...]`. Add `data-testid` only when nothing accessible exists.
- The UI runs in Indonesian (`locale: 'id-ID'`, `i18nextLng=id`). Laravel validation messages such as
  `auth.failed` are English.
- `npm run test:e2e:scaffold` checks the scaffolding command itself.

## Tag tests with the acceptance criteria they prove

A PR whose `Backlog:` line names criteria (`Backlog: REQ-002 AC-04, AC-05`) needs, for each one, a
test under `features/` tagged `@<ID>-AC-<n>` (Playwright's built-in tags), or a per-criterion
exception on the `E2E:` line. The test may come from an earlier PR; `test.fixme`/`test.skip` tests do
not count. CI's *PR checklist* checks it; run one criterion with `npm run test:e2e -- --grep @REQ-002-AC-05`.

```js
test('receive 2 kg of a 5 kg order', { tag: ['@REQ-002-AC-05'] }, async ({ asRole }) => { ... });
```

A criterion no browser can prove (a data migration, an API-only rule, a scheduled job) is excused
with a reason, several separated by `;`: `E2E: AC-07 none — data migration, tests/Feature/...`.

## Troubleshooting

- **Port 8010 busy**: run `npm run e2e:down` in the checkout that started the server; otherwise something else
  holds 8010. Ports 8000–8004 belong to the dev app and review apps.
- **`e2e:up` says nothing answers**: read `storage/logs/e2e-serve.log`.
- **Pages load but use the wrong data / dev database**: `artisan serve` must run with `--no-reload`,
  otherwise it drops the e2e env and reads `.env`. Keep the flag in `support/host-up.js`.
- **Blank page or Vite errors**: stop `npm run dev`. The e2e app ignores `public/hot` but needs a fresh
  `npm run build` (done by `e2e:up`).
- **500 on `/login`**: the database was never reset, or assets are not built. Run `npm run e2e:down`, then
  `npm run e2e:up`.
