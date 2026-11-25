# 25-cashier-shifts

| Steps | Roles |
|---|---|
| `01-pos-blocked-without-shift`: POS locked without a shift, shift-gated endpoint bounces, open from the POS; `02-open-shift-validation`: second open keeps the first shift; `03-cash-in-out`: cash in/out change expected cash; `04-close-shift`: close with counted cash and difference, POS locked again; `05-force-close-by-manager`: password step-up (wrong, then right) then force close; a cashier gets 404 on another user's shift | cashier, manager |

Manual (needs a real device): printing the X and Z shift reports on a thermal printer.
