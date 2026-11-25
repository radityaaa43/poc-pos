# E2E coverage

One folder per feature under `features/`, one file per step. A feature PR adds its own folder and a
row here (`npm run e2e:new` does both). Design: `docs/superpowers/specs/2026-11-20-test-suite-design.md`.

| Feature | Steps | Roles |
|---|---|---|
| `00-smoke` | `01-login-each-role`: login lands on the right page per role, guest redirect, wrong password, logout | super-admin, manager, cashier, stock-opname, dine-orders |
| `20-po-purchased-by` | `01-outlet-rejects-central-material`: an outlet manager adds a central-bought material, picks the outlet warehouse, the page stops offering it and saving is rejected on that line | manager |
| `09-recipe-cost` | `01-edit-form`: recipe edit form shows "HPP per porsi (<outlet>)" from component averages and no Harga Beli, a plain product still has it, the product list shows the same cost | manager |
| `21-product-purchased-by` | `01-raw-material-needs-buyer`: ticking Bahan baku on the product form shows Dibeli Oleh and rejects saving without it, the choice persists, unticking hides and clears it | manager |
| `01-role-matrix` | `01-super-admin` … `05-dine-orders` (one per role; `06-orphan-create-routes` is fixme, #97): every menu page opens (200) or is refused (403, no redirect) as `role-matrix.expected.json` says; the sidebar shows exactly that role's menu | super-admin, manager, cashier, stock-opname, dine-orders |
