# Offline Mode

Offline mode membantu kasir menyelesaikan checkout yang sudah disiapkan ketika koneksi sementara terputus. Fitur ini bukan mode POS penuh tanpa server.

## Perilaku

- Master data tertentu tersedia melalui cache-first PWA.
- Transaksi checkout dimasukkan ke IndexedDB `pending_transactions` ketika offline.
- Queue dikirim dari browser ke `POST /dashboard/transactions/sync` (auth session, permission `transactions-access`) saat koneksi kembali; klien Bearer token memakai `POST /api/v1/pos/transactions/sync`. Keduanya menjalankan `PosApiController::syncTransactions`.
- `client_uuid` menjaga sinkronisasi tetap idempotent.
- Server tetap menjadi sumber harga dan validasi akhir.
- Offline the screen keeps the last total the server computed. At an outlet with a service charge the order type buttons are disabled offline and the POS says why (#418 lifts this); at other outlets the order type does not change the total.
- Service charge and tax are computed again at sync from the outlet's settings and the queued order type. Cash below that total is refused and the sale stays in the queue as failed.
- When a queued sale holds a line that needs preparation, the POS shows a persistent notice and marks the sale in the pending list: "Dapur belum menerima pesanan ini. Sampaikan langsung ke dapur." The kitchen receives it at sync and times it from that moment.
- The shift page disables closing while this device still holds unsynced sales ("Sinkronkan transaksi offline dulu").

## Batasan

- Cart utama berbasis server, sehingga menambahkan produk baru saat offline terbatas.
- Konflik stok atau perubahan harga diselesaikan oleh validasi server ketika queue flush.

## File Terkait

- `resources/js/Utils/offlineDb.js`
- `app/Http/Controllers/Api/PosApiController.php`
- `docs/testing-manual.md`
