# 38-dine-orders-queue

| Steps | Roles |
|---|---|
| `01-accept-order-into-transaction`: accept takes stock, creates the transaction, guest page follows by polling; no open shift and short stock refuse; `02-reject-order`: reject with a reason, cancelled prompt; `03-settle-accepted-order`: an accepted order is an open bill, paid (cash, transfer, QRIS) from Transactions History or the POS "Belum Lunas" and moved through Mulai Buat, Siap and Serahkan from the queue (own permission per step); the kitchen starts only on a paid bill (payment first) and serving completes it (#128) | manager, guest |
