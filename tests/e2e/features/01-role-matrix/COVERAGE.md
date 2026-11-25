# 01-role-matrix

| Steps | Roles |
|---|---|
| `01-super-admin` … `05-dine-orders` (one per role; `06-orphan-create-routes` is fixme, #97): every menu page opens (200) or is refused (403, no redirect) as `role-matrix.expected.json` says; the sidebar shows exactly that role's menu | super-admin, manager, cashier, stock-opname, dine-orders |
