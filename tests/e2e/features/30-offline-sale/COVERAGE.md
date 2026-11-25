# 30-offline-sale

| Steps | Roles |
|---|---|
| `01-queue-offline-sale`: an offline cash sale is queued in IndexedDB (pending, with client_uuid) and nothing reaches the server; fixme (#109): the saved-offline toast and emptied cart. `02-flush-on-reconnect`: fixme (#107), going online flushes the queue exactly once. `03-replay-same-client-uuid`: replaying the queued payload via the sync API gives synced, then duplicate (same invoice), then conflict for a changed basket; one transaction, stock down once. Service workers are blocked in the e2e context, so the PWA asset cache is not covered | cashier |
| `04-pay-later-offline`: with Bayar Belakangan on, going offline replaces the toggle with "Bayar belakangan tidak tersedia saat offline" and nothing is queued (REQ-003 BR-8); the old queued entry rejected on sync (BR-9) is PHPUnit only, it needs a pre-release queue entry | cashier |
