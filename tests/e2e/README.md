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

Prerequisites: the normal Docker stack (`docker compose up -d`, for MySQL), Node 22+.

```bash
npx playwright install chromium   # once
npm run e2e:up                    # starts app-e2e (http://localhost:8010), builds assets, resets the DB
npm run test:e2e                  # whole suite (resets the DB first)
npm run test:e2e -- features/00-smoke          # one feature
npm run test:e2e:ui               # Playwright UI mode
docker compose --profile e2e stop app-e2e      # when done
```

- `npm run e2e:reset` rebuilds `point_of_sales_e2e` (migrate, seeders). `test:e2e` does it for you
  unless `E2E_SKIP_RESET=1`.
- `E2E_BASE_URL` points the suite at another host (default `http://localhost:8010`).
- Test users are `*@e2e.test` with password `password` (see `support/roles.js`, `E2eSeeder`).
- After a failure open the report: `npx playwright show-report`, or the trace in `test-results/`.

### Several worktrees at once

`app-e2e` serves one checkout and one database. A second worktree runs its own container on its own
port and `*_e2e` database (ports 8000–8004 and 8010 are taken), with its own `npm ci && npm run build`:

```bash
docker compose exec -T mysql mysql -uroot -psecret -e "CREATE DATABASE IF NOT EXISTS pos_8011_e2e"
MSYS_NO_PATHCONV=1 docker run -d --name pos-e2e-8011 --network poc-pos_default -p 127.0.0.1:8011:8011 \
  -v "$PWD:/var/www/html" -v poc-pos_vendor:/var/www/html/vendor -w /var/www/html \
  -e APP_ENV=e2e -e APP_URL=http://localhost:8011 -e DB_DATABASE=pos_8011_e2e <the app-e2e env> \
  poc-pos-php:8.4 php artisan serve --host=0.0.0.0 --port=8011 --no-reload
E2E_PHP="docker exec -i pos-e2e-8011 php" E2E_BASE_URL=http://localhost:8011 npm run test:e2e
```

`<the app-e2e env>` is every other `environment:` entry of `app-e2e` in `docker-compose.yml`.
Remove it with `docker rm -f pos-e2e-8011`.

The app env is set in the `app-e2e` service of `docker-compose.yml` (real env vars beat `.env`):
session/cache in the database, `QUEUE_CONNECTION=sync`, bot guard on but with no minimum submit time,
low bcrypt cost, no SSR. The bot-guard honeypot and token stay on.

## In CI

`.github/workflows/tests.yml`: `E2E (shard i/N)` jobs run the same suite against a MySQL service
container (`--shard=i/N`, blob reporter); the `E2E` job merges the blob reports into one HTML report
artifact and fails if any shard failed. There is one shard today; to add shards, extend the `shard`
matrix only (the job names and `--shard` both follow it). Each shard job resets its own database.

## Adding e2e for a new feature

One folder per feature, one file per step, and the feature's PR carries its own folder.

```bash
npm run e2e:new -- 08-purchase-orders create-order   # step slug is optional (default: happy-path)
```

This creates `features/08-purchase-orders/01-create-order.spec.js` from `support/templates/step.spec.js`
(a `test.fixme` placeholder: replace it) and the folder's own `COVERAGE.md` (fill it in; one note per folder, so PRs on different features never conflict). Then:

- A step arranges its own data and never depends on another step: call `arrange('open-shift', { cash: 100000 })`
  and use `uniq('product')` for names, so reruns and shards do not collide.
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

- **Port 8010 busy**: another `app-e2e`, or something else on 8010. Ports 8000–8004 belong to the dev app and review apps.
- **Pages load but use the wrong data / dev database**: `artisan serve` must run with `--no-reload`,
  otherwise it ignores the service env and reads `.env`. Keep the flag in `docker-compose.yml`.
- **Blank page or Vite errors**: stop `npm run dev`. The e2e app ignores `public/hot` but needs a fresh
  `npm run build` (done by `e2e:up`).
- **500 on `/login`**: the database was never reset, or assets are not built. Run `npm run e2e:up`.
- **Running compose from a git worktree**: the compose project mounts the checkout you run it from, and a second
  `mysql` would clash on port 3307. Reuse the running stack by exporting `COMPOSE_PROJECT_NAME` (a name of your choice)
  and `COMPOSE_FILE=docker-compose.yml;override.yml` where the override file joins the existing network as
  `external` (`poc-pos_default`) and sets `depends_on: !reset null` on `app-e2e`. From the main checkout none of this is needed.
