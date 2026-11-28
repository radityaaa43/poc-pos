# 23-pos-receivable

| Steps | Roles |
|---|---|
| `01-pay-later`: pay-later toggle, customer and due date required, receipt as unpaid note, history badge, receivable listed, stock taken, toggle off | cashier |
| `03-settle-guest-bill`: a guest pay-later bill is listed in "Belum Lunas" with its note, amount and age and paid in full with cash (no receivable); a short payment is refused in the modal; settling counts in the shift it is taken in and adds no kitchen item; two cashiers pay the same bill: the first wins and the second is told "Tagihan ini sudah dilunasi."; a fully returned bill leaves the list and a partly returned one shows the remaining amount; no open shift: refused, the bill stays (REQ-003 AC-17 settle half); `04-credit-panel`: the picked credit customer's Batas, Terpakai and Sisa, Sisa Rp 0 for a limit below usage, Tanpa batas, and the overdue warning (REQ-007 AC-03); `05-credit-no-limit-pay-later`: a credit customer without a limit, with an overdue receivable, still buys on pay later (REQ-007 AC-08). The release migration and the all-outlet sum: `tests/Feature/Customers/` | cashier, manager |
