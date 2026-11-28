# 29-receivables

| Steps | Roles |
|---|---|
| `01-list-and-filter`: badges, amounts, filters, 403 without access; `02-pay`: over-payment refused, partial then final payment, list and sale read paid; `03-aging-buckets`: five overdue buckets on the aging page, biggest debtor on the receivables aging tab; `04-credit-columns`: Batas and Terpakai % with the near-limit label and Tanpa batas (REQ-007 AC-07); `05-credit-filters`: Mendekati batas (≥ 80%), Melewati batas and Tanpa batas each list only their customers (REQ-007 AC-07). Outlet scope and boundaries: `tests/Feature/Receivables/ReceivableCreditUsageTest.php` | manager, cashier, stock-opname |
