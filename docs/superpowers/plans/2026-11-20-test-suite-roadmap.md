# Test suite: delivery roadmap

**Spec:** `docs/superpowers/specs/2026-11-20-test-suite-design.md` (approved 2026-11-20).

The spec covers three independent layers, so it ships as a sequence of PRs. Each PR has its own
issue, branch `<type>/<issue>-<slug>`, and a detailed plan in this folder, written just before the
PR starts (so it is based on the code as it is then). Each PR stays under 1,000 counted lines.

| # | PR | Depends on | Plan |
|---|---|---|---|
| 1 | Frontend foundation: Vitest, `Frontend` CI job, Utils tests, i18n parity, menu and tour cross-checks (#11) | — | `2026-11-20-pr01-frontend-foundation.md` |
| 2 | Frontend components: POS components, hooks, contexts, shared form components | 1 | to write |
| 3 | Backend in CI: `Backend (MySQL)` job with pcov coverage; MySQL-only failures become `fix/` issues | — | to write |
| 4a–c | Backend gaps: services, payment gateways and webhooks, Import/Export (spec §5) | 3 | to write |
| 5 | E2E foundation: Playwright, `support/`, `E2eSeeder`, `e2e:arrange`, `E2eServiceProvider`, `app-e2e` service, sharded `E2E` jobs, setup wizard and auth features, `governance.json` e2e fields | — | to write |
| 6 | E2E role matrix with `role-matrix.expected.json` reviewed by the maintainer | 5 | to write |
| 7a–j | E2E per feature group (spec §7.2): POS (2), dine-in, inventory, purchasing, pricing and CRM, platform administration and settings, reports and documents, public flows, offline and printer | 5 | to write |
| 8 | Required checks in the ruleset (maintainer's go-ahead) and the Standards rule "a feature change adds or updates its e2e step and `COVERAGE.md`" | 1, 3, 5 | #61 |

Bugs found along the way follow spec §7.9: a `fix/` issue, the exposing test tagged or allow-listed
with the issue link, and a separate fix PR.

## Coverage notes

- **Offline flush and `client_uuid` idempotency** (spec §6 lists them under `offlineDb.js`) are not
  unit-tested in PR 1: the flush loop lives in `Pages/Dashboard/Transactions/Index.jsx` and
  idempotency is enforced by the server. They are covered by the e2e "Offline and printer" group
  (PR 7) and the backend transaction tests, without changing application code for testability.
- **Known gap:** the English sidebar label for Outlets (#12) is recorded in
  `resources/js/i18n/locales.test.js` → `KNOWN_GAPS`; the fix PR empties it.
