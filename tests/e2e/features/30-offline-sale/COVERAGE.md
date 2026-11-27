# 30-offline-sale

| Steps | Roles |
|---|---|
| `01-queue-offline-sale`: an offline cash sale is queued in IndexedDB (pending, with client_uuid) and nothing reaches the server; fixme (#109): the saved-offline toast and emptied cart. `02-flush-on-reconnect`: fixme (#107), going online flushes the queue exactly once. `03-replay-same-client-uuid`: replaying the queued payload via the sync API gives synced, then duplicate (same invoice), then conflict for a changed basket; one transaction, stock down once. Service workers are blocked in the e2e context, so the PWA asset cache is not covered | cashier |
| `06-unsynced-sale-holds-close`: a sale this device cannot sync keeps the shift close disabled with Sinkronkan transaksi offline dulu (REQ-003 AC-12, #242 BR-12). `04-pay-later-offline`: with Bayar Belakangan on, going offline replaces the toggle with "Bayar belakangan tidak tersedia saat offline" and nothing is queued (REQ-003 BR-8); the old queued entry rejected on sync (BR-9) is PHPUnit only, it needs a pre-release queue entry | cashier |
| `05-kitchen-notice` (REQ-003 AC-14): offline, a sale of a product that needs preparation shows "Dapur belum menerima pesanan ini" and nothing reaches the server; after the sync the item is on the kitchen display as Diterima. The timing from server receipt is PHPUnit only | cashier |
