# E2E coverage

One folder per feature under `features/`, one file per step. A feature PR adds its own folder and a
row here (`npm run e2e:new` does both). Design: `docs/superpowers/specs/2026-11-20-test-suite-design.md`.

| Feature | Steps | Roles |
|---|---|---|
| `00-smoke` | `01-login-each-role`: login lands on the right page per role, guest redirect, wrong password, logout | super-admin, manager, cashier, stock-opname, dine-orders |
