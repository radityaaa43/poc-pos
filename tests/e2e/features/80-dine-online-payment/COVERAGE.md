# 80-dine-online-payment

| Steps | Roles |
|---|---|
| `01-server-amount`: the guest charge is for the bill total the server computes, a phone-sent `amount` is ignored (QRIS and the hosted page), the response holds only state, amount, attempt, link and QR, the attempt is stored as `{invoice}-r1`; `02-refusals`: unknown token 404, before accept and rejected order `not_payable`, paid bill `already_paid`, sixth post in a minute 429 per token, none of them creates a payment | guest (no login) |

The server half (REQ-005 AC-02, AC-09) is driven through `POST /dine-order/{token}/pay` and the `dine.order-state` query, because no page calls it yet. The guest pay panel and its steps (AC-01, AC-03, AC-04, AC-07) come with the next PR.

Why folder 80: the `dine.bill` scenario creates an outlet and a warehouse per call, and the super admin's lists show every warehouse as a column (`50-raw-materials/03` reads the last column of its row). The folder runs after every feature that reads such lists, like `75`-`79`, whose `public.sale` outlets are the precedent. Keep this folder after them.

Manual checks (no sandbox in CI): a real Midtrans and a real Xendit sandbox charge (does Midtrans Snap return a `qr_string` for the restricted-methods charge, does the finish redirect land on the status page, is the 15 minute expiry applied), and a real e-wallet scan.
