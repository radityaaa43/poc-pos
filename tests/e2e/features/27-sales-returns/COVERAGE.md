# 27-sales-returns

| Steps | Roles |
|---|---|
| `01-partial-cash-return`: draft, complete, totals, stock back, shift cash, list; `02-validation-errors`: no item, no reason, complete without a shift; `03-pay-later-return`: returning a pay-later sale clears its receivable; `04-walk-in-and-cashier`: cash-only without customer, store credit, cashier has no return button or page (403); `05-restock-default-by-kitchen-status`: restock unticked with a hint for an item being made, ticked for an accepted one, the person can change it (@REQ-003-AC-11); `06-return-permission`: a cashier has no return and the kitchen display offers no remove action (@REQ-003-AC-22); `07-return-refunds-service-and-tax`: a return refunds the proportional service charge and tax, never the rounding (@REQ-010-AC-10); `08-return-refunds-net-of-discount`: a return of a discounted sale previews the discount deduction and refunds what was paid | manager, cashier |
