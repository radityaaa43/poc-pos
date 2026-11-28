# Offline Mode

Offline mode membantu kasir menyelesaikan checkout yang sudah disiapkan ketika koneksi sementara terputus. Fitur ini bukan mode POS penuh tanpa server.

## Perilaku

- Master data tertentu tersedia melalui cache-first PWA.
- Transaksi checkout dimasukkan ke IndexedDB `pending_transactions` ketika offline.
- Queue dikirim dari browser ke `POST /dashboard/transactions/sync` (auth session, permission `transactions-access`) saat koneksi kembali; klien Bearer token memakai `POST /api/v1/pos/transactions/sync`. Keduanya menjalankan `PosApiController::syncTransactions`.
- `client_uuid` menjaga sinkronisasi tetap idempotent.
- Server tetap menjadi sumber harga dan validasi akhir.
- When a queued sale holds a line that needs preparation, the POS shows a persistent notice and marks the sale in the pending list: "Dapur belum menerima pesanan ini. Sampaikan langsung ke dapur." The kitchen receives it at sync and times it from that moment.
- The shift page disables closing while this device still holds unsynced sales ("Sinkronkan transaksi offline dulu").

## Stuck offline sales (queue panel)

While this device holds a sale of the signed-in cashier that has not synced, the POS shows **Transaksi offline tertunda (n)** at the top of the cart panel (on a phone, in the Keranjang tab). It lists each row with its time, items, total, payment, status and the server's reason.

| Row status | Meaning | Hapus |
|---|---|---|
| `pending` (Menunggu dikirim) | Not sent yet | no |
| `syncing` (Sedang dikirim) | In a sync now; after 5 minutes without an answer it counts as `failed` | no |
| `failed` (Gagal) | The server refused it or was unreachable; sent again on every sync | yes |
| `conflict` (Konflik) | The server already has this sale id with other content; never sent again | yes |
| `discarding` (Sedang dihapus) | A removal that has not finished; never sent; still holds the shift close | yes |

- **Coba sinkron sekarang** runs the same sync as the automatic one, which keeps running as before.
- **Hapus** works only online and asks for confirmation. A `failed` sale is not on the server: the confirmation says to ring it up again online first. The device marks the row `discarding`, posts its content to `POST /dashboard/transactions/offline-queue/discard` (`permission:transactions-access`, 30 per minute), and deletes the row only after the server answers 201. On any other answer the row returns to its previous status and stays.
- **Audit entry**: event `offline_sale.discarded`, module `transactions`, with who and when; `meta` holds `client_uuid`, `queue_status`, `reason`, `attempts`, `queued_at`, `sale` (the whole queued sale: `items` as product id, unit id and quantity; `grand_total`, `discount`, `redeem_points`, `customer_voucher_id`, `shipping_cost`, `cash`, `payment_method`, `bank_account_id`, `order_type`, `note` (first 1000 characters), `customer_id`, and `pay_later` / `due_date` on old entries; not the server cart ids; the queue holds no per-line price), `server_invoice` and `cashier_shift_id` (the cashier's open shift at removal). When the server already has a sale with that `client_uuid`, the entry is linked to it. Removing never changes server data.
- A page closed between the audit call and the delete leaves the row `discarding`; removing it again writes a second entry.
- Rollback: before reverting this feature, remove any `discarding` row with the panel; older code counts such a row but never sends it.
- 30-day clean-up (when the POS opens): only `conflict` rows older than 30 days are deleted, without an audit entry, because the server already has that sale. A `failed` row is never cleaned up.
- A line is named by the product title stored on the queue row when the sale was queued, else by the POS product list, else `Produk #<id>`.

## Batasan

- Cart utama berbasis server, sehingga menambahkan produk baru saat offline terbatas.
- Konflik stok atau perubahan harga diselesaikan oleh validasi server ketika queue flush.

## File Terkait

- `resources/js/Utils/offlineDb.js`
- `resources/js/Utils/offlineQueue.js`
- `resources/js/Components/POS/OfflineQueuePanel.jsx`
- `app/Http/Controllers/Apps/Transactions/OfflineSaleDiscardController.php`
- `app/Http/Controllers/Api/PosApiController.php`
- `docs/testing-manual.md`
