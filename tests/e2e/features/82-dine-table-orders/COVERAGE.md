# 82-dine-table-orders

| Steps | Roles |
|---|---|
| `01-send-a-table-order` (REQ-009 AC-01, AC-02): **Pesanan meja** lists the active tables with their status (an inactive one is absent); the cart sent to a table as Bayar Belakangan is an unpaid bill of that table, takes stock and records its kitchen item. Stock refusals, pricing, outlet scope and access are PHPUnit | cashier |
| `02-add-to-running-order` (AC-03): "Tambah ke pesanan #id" links the new sale to the running one | cashier |
| `03-status-and-bill-count` (AC-04, AC-05): the POS dialog and the Tables page show Terisi with 1 unpaid bill; after paying the last order the table is Kosong with 0. Rejected and cancelled orders, and Dipesan (no reservations until #443), are PHPUnit only | cashier, manager |
| `04-offline-refusal` (AC-21): offline, sending and opening are refused with a message; nothing is queued | cashier |
| `05-retire-a-busy-table` (AC-23): deleting or switching off a Terisi table is refused and the message names the order; the reservation case and the API are PHPUnit only | cashier, manager |
| `06-english` (AC-22): the dialog and the Tables page status read in English. Server messages follow the user's saved language (PHPUnit) | cashier, manager |
