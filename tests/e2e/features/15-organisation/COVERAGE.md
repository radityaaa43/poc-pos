# 15-organisation

| Steps | Roles |
|---|---|
| `01-units`, `02-warehouses`, `03-outlets`: create/rename/delete, outlet switch keeps settings per outlet | manager, super-admin |
| `04-outlet-pay-later`: an admin turns Bayar Belakangan on for an outlet, the change is audited once (no second row when saved unchanged), a cashier gets 403 on the page (REQ-003 AC-25 outlet half) | super-admin, cashier |
| `05-outlet-bill-settings`: a never-saved outlet shows no service charge, PPN and no rounding; the Tagihan values are saved and shown again, 101% is refused under the field, a cashier gets 403; one audit row per change, none when saved unchanged (REQ-010 AC-01, AC-03, AC-04). AC-02 and BR-2 show on bills from #450; AC-13 is Vitest (both languages) | super-admin, cashier |
