# 25-cashier-shifts

| Steps | Roles |
|---|---|
| `01-pos-blocked-without-shift`: POS locked without a shift, shift-gated endpoint bounces, open from the POS; `02-open-shift-validation`: second open keeps the first shift; `03-cash-in-out`: cash in/out change expected cash; `04-close-shift`: close with counted cash and difference, POS locked again; `05-force-close-by-manager`: password step-up (wrong, then right) then force close; a cashier gets 404 on another user's shift; `06-close-blocked` (REQ-003 AC-12): food not handed over is listed under Belum selesai (order, label, amount), the cashier has no force close and the close is refused with the shift still open; `07-close-pay-later-credit` (AC-17): a credit customer's pay later closes, a guest's holds; `08-force-close-held-shift` (AC-13): the manager must write a note, items become not collected, the unpaid bill is booked in the cash difference and left out of non-cash sales, audit entries; `09-close-cancels-unpaid-pay-now` (AC-20): the waiting QRIS sale is cancelled, both stock columns restored and audited, the paid one untouched; `10-two-shifts-one-outlet` (AC-27): another cashier's food, bill and waiting sale neither hold the close nor change | cashier, manager |

Not covered here (PHPUnit): the cancel of failed and expired sales, split and returned sales, payment of a booked bill in a later shift. The disabled close button while a sale waits to sync is proved in `30-offline-sale/06` (the sync is blocked, the page stays online).

Manual (needs a real device): printing the X and Z shift reports on a thermal printer.
