# 21-pos-checkout-cash

| Steps | Roles |
|---|---|
| `01-cash-with-change`: customer required, short cash disables the button, change shown, paid receipt with cash and change, cart emptied, stock 7 to 4 in the outlet warehouse and in the stock book, checkout refused when the shelf emptied meanwhile; `02-walk-in-and-box-stock`: a box sale takes 12 pcs out (fixme #133 walk-in sale) | cashier, super-admin |
| `01-cash-with-change`: customer required, short cash disables the button, change shown, paid receipt with cash and change, cart emptied, stock 7 to 4 in the outlet warehouse and in the stock book, checkout refused when the shelf emptied meanwhile; `02-walk-in-and-box-stock`: a box sale is charged the box price and takes 12 pcs out (fixme #133 walk-in sale) | cashier, super-admin |
| `03-order-name`: an optional order name typed at checkout is on the invoice view and both thermal previews, the kitchen card and the printed kitchen ticket; typed offline it is queued, syncs to the kitchen and neither it, the note nor the order type carries to the next sale; a pay-later bill leads its Belum Lunas row with the name; the field, its limit warning and the receipt line in English. Manual: the line on a real thermal printer and on the 58/80 mm PDF receipts (server tests cover their text) | cashier |
