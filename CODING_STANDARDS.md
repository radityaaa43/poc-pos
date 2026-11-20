# CODING_STANDARDS.md

How code in this repo is written. Reviews (`REVIEW.md`) check against this file and the skills it
links; style preferences not written here are not findings. Lint and format rules are enforced by
the tools, so they are not repeated here.

## All code

- **Match the surrounding code.** Follow the patterns of the module you are in before introducing a
  new one; a new pattern needs a reason in the PR (and an ADR when it is structural).
- **Names from the domain.** Use the terms in [`GLOSSARY.md`](GLOSSARY.md); don't invent synonyms.
- **Small units.** Functions do one thing; files stay under the length limit in `CONTRIBUTING.md`.
- **Comments say why, not what.** A workaround, an external constraint or a non-obvious choice gets
  a short *why* comment; obvious code gets none.
- **Errors are handled where they can be acted on**, with context; never swallowed silently.
- **No secrets in code.** Configuration comes from the environment; every new variable is in the
  `*.example` env file and documented.
- **Tests sit next to behavior**: new behavior gets a test that fails without the change.

## Per stack

### laravel (`.`, php)

- **Thin controllers, logic in services.** Dashboard controllers live in
  `app/Http/Controllers/Apps/`, API in `Api/`, reports in `Reports/`. Domain operations (checkout,
  stock, purchasing, pricing, payments) live in `app/Services/<Name>Service.php` and are injected
  through the controller constructor.
- **Responses:** dashboard pages return `Inertia::render('Dashboard/<Module>/<Page>', [...])`;
  form actions return `back()->with(...)` or a redirect; API controllers return JSON (resources in
  `app/Http/Resources/`).
- **Validation:** follow the module. Most controllers validate inline with `$request->validate()`;
  larger forms use a FormRequest in `app/Http/Requests/` (`Store…Request`, `Update…Request`).
- **Business-rule errors** throw `ValidationException::withMessages([...])` so Inertia shows them on
  the form and the API returns 422. Use `abort(403|404)` only for access and missing records.
- **Authorization:** routes carry `->middleware(['permission:<module>-<action>'])` (Spatie). New
  permissions go into `PermissionSeeder` and the roles in `RoleSeeder` (hotspots). API routes also
  need the matching Sanctum ability (`docs/agents/project-guide.md` → API ability system).
- **Stock and money changes** run inside `DB::transaction` with `lockForUpdate()` on the affected
  rows; `product_warehouse.stock` and `products.stock` change together.
- **Audit trail:** state changes an operator may need to trace (settings, prices, stock, payments)
  call `AuditLogService::log(...)`, as the surrounding module does.
- **Schema:** a new migration per change; never edit a merged one. Money columns are whole rupiah in
  `bigInteger`, like `buy_price`, `sell_price` and `grand_total`.
- **Style:** Laravel Pint (`vendor/bin/pint --test --diff=main`).
- **Tests:** PHPUnit feature tests in `tests/Feature/<Module>/`, `RefreshDatabase`, in-memory
  SQLite. Conventions: `docs/agents/project-guide.md` → Test conventions.

### frontend (`.`, node)

- **Pages** are Inertia React components in `resources/js/Pages/<Area>/<Module>/<Page>.jsx`
  (`Index`, `Create`, `Edit`, `Show`), one `export default function` per file, wrapped in the
  dashboard layout from `@/Layouts`.
- **Imports** use the `@/` alias (`@/Components`, `@/Layouts`, `@/Utils`); reuse `@/Components`
  (buttons, inputs, `Modal`, `InputError`) before writing a new one.
- **Forms** use Inertia `useForm` and `route('<name>')` (Ziggy); navigation and actions use
  `router.get/post/put/delete`. `axios` only for JSON endpoints that don't change the page.
- **Feedback:** `react-hot-toast` for results, `sweetalert2` for confirmations; icons from
  `@tabler/icons-react`; colors from the Tailwind tokens (`primary`, `accent`, `success`,
  `warning`, `danger`), not raw hex.
- **Text:** new UI strings go into both `resources/js/i18n/locales/id.json` and `en.json`.
- **Sidebar entries** go into `resources/js/Utils/Menu.jsx` behind the same permission as the route.
- **Oversized files** (exempt from the length check until split; don't grow them further, extract
  components when you touch them): `Pages/Dashboard/Transactions/Index.jsx` (2,083 lines),
  `Pages/Dashboard/Reports/Insights.jsx` (1,300), `Pages/Dashboard/Transactions/Print.jsx` (1,045).
- **Checks:** no frontend lint; `npm test` (Vitest; tests sit next to the file as `*.test.js(x)`,
  `npm run test:watch` while editing) and `npm run build` must pass, and UI changes are tested by hand
  with before/after screenshots in the PR.

### whatsapp-service (`whatsapp-service`, node)

- A single Express app in `server.js` (CommonJS `require`), wrapping `whatsapp-web.js` with
  `LocalAuth`; the login session lives in `session/` (git-ignored, never commit it).
- Every route goes through `app.use(requireServiceToken)` (`X-Service-Token`, compared with
  `timingSafeEqual`); configuration comes from environment variables (`WA_SERVICE_TOKEN`, ...).
- Errors return JSON `{ status: false, reason }` with an HTTP status code.
- No tests or lint; test by hand against a connected device and the Laravel `WhatsAppService`.


## Repo skills

Stack conventions with examples can live as skills in `.agents/skills/` (for example
`<stack>-coding-rules` and `<stack>-development-guide`), so agents load them only when they work in
that stack. List them in `.agents/skills/README.md`.
