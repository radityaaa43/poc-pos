# 84-bill-totals

| Steps | Roles |
|---|---|
| `01-service-charge` (REQ-010 AC-05): at a 5 % outlet Di Tempat shows Service charge and the higher total, Bawa Pulang and Diantar drop it, and the receipt shows the line; `02-tax-kinds`: PB1 10 % on subtotal plus service charge with no PPN line although the product has PPN (AC-06), PPN outlet with the product PPN plus PPN on the service charge (AC-07), each at checkout and on the receipt | cashier |

The steps set the E2E outlet's bill rules (`billsettings.set`) and reset them afterwards, because the suite shares that outlet. Folders 81-83 are taken on open branches.

Not covered here (PHPUnit): tampered client totals on web, API and sync (BR-1), PB1 and PPN with delivery shipping (BR-2), the other receipts and documents. Manual checks: a real thermal print of a service charge sale (58 mm and 80 mm), and the A4 invoice and receipt PDFs.
