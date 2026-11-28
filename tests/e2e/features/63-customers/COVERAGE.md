# 63-customers

| Steps | Roles |
|---|---|
| `01-create-customer-with-region`: validation, cascading region selects with real laravolt codes, duplicate phone; `02-edit-and-delete-customer`, `03-cashier-limits`, `04-detail-segments-and-membership`, `05-phone-keeps-leading-zero` (fixme, #129), `06-may-buy-on-credit` (REQ-003 AC-17 switch and cashier limit, AC-25 audit entry); `07-credit-limit` (REQ-007 AC-01: a manager sets Batas piutang and empties it back to Tanpa batas, a cashier sees it with no field); `08-credit-limit-audit` (REQ-007 AC-02: audit-log entry with who, when, old and new). Server refusals and invalid values: `tests/Feature/Customers/CustomerCreditLimitTest.php` | cashier, manager |
