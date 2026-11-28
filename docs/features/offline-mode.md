# Offline Mode

Offline mode membantu kasir menyelesaikan checkout yang sudah disiapkan ketika koneksi sementara terputus. Fitur ini bukan mode POS penuh tanpa server.

## Perilaku

- Master data tertentu tersedia melalui cache-first PWA.
- Transaksi checkout dimasukkan ke IndexedDB `pending_transactions` ketika offline.
- After a sale is queued, the POS clears the sale form (note, order type, order name, discount, customer, payment) as it does after an online sale.
- Queue dikirim dari browser ke `POST /dashboard/transactions/sync` (auth session, permission `transactions-access`) saat koneksi kembali; klien Bearer token memakai `POST /api/v1/pos/transactions/sync`. Keduanya menjalankan `PosApiController::syncTransactions`.
- `client_uuid` menjaga sinkronisasi tetap idempotent.
- Server tetap menjadi sumber harga dan validasi akhir.
- When a queued sale holds a line that needs preparation, the POS shows a persistent notice and marks the sale in the pending list: "Dapur belum menerima pesanan ini. Sampaikan langsung ke dapur." The kitchen receives it at sync and times it from that moment.
- The shift page disables closing while this device still holds unsynced sales ("Sinkronkan transaksi offline dulu").

## Batasan

- Cart utama berbasis server, sehingga menambahkan produk baru saat offline terbatas.
- Konflik stok atau perubahan harga diselesaikan oleh validasi server ketika queue flush.

## File Terkait

- `resources/js/Utils/offlineDb.js`
- `app/Http/Controllers/Api/PosApiController.php`
- `docs/testing-manual.md`
