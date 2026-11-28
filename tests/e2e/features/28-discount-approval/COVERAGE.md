# 28-discount-approval

| Steps | Roles |
|---|---|
| `01-request-from-pos`: POS discount over the limit waits and shows to the manager; `02-approve`: sale becomes paid; `03-reject`: discount removed, full price due; `04-cashier-forbidden`: 403; `05-credit-approval-forbidden`: a cashier cannot approve or deny a credit exception, 403 (REQ-007 AC-06; the flow itself is in `23-pos-receivable`) | cashier, manager |
