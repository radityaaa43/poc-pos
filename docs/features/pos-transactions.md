# POS & Transactions

Kembali ke indeks dokumentasi: `docs/README.md`

## Daftar Isi

- Tujuan
- Fitur Saat Ini
- Halaman dan Route
- Permission
- Alur User
- Integrasi Data
- Batasan Saat Ini
- File Sentral

## Tujuan

Menyediakan alur kasir cepat untuk pencarian produk, pengelolaan cart, checkout, hold/resume, dan distribusi dokumen transaksi.

## Fitur Saat Ini

- cari produk via barcode / pencarian
- cart multi-item
- update qty cart
- hold transaction
- resume held cart
- clear held cart
- checkout tunai, split payment (maksimal dua tender), bank transfer, Midtrans, Xendit, pay later
- bagian transfer pada split payment menunggu konfirmasi seperti transfer tunggal: transaksi tetap `pending` ("Menunggu") sampai user dengan izin `transactions-confirm-payment` mengonfirmasi; konfirmasi menandai tender transfer dan transaksi `paid`. Bagian tunai langsung tercatat dan masuk ke kas laci shift
- print invoice / receipt / shipping label
- share invoice publik
- add customer langsung dari POS
- penjualan walk-in: tunai, QRIS, dan transfer selesai tanpa memilih pelanggan; pelanggan wajib hanya untuk pay later (nota barang) dan untuk memakai poin, voucher, atau harga member

## Halaman dan Route

- `dashboard/transactions`
- `dashboard/transactions/history`
- `transactions.searchProduct`
- `transactions.addToCart`
- `transactions.updateCart`
- `transactions.destroyCart`
- `transactions.hold`
- `transactions.resume`
- `transactions.clearHold`
- `transactions.held`
- `transactions.store`
- `transactions.print`
- `transactions.public`

## Permission

- `transactions-access`

Operasi transaksional tertentu juga mewajibkan middleware `active_shift`.

## Alur User

1. kasir membuka halaman transaksi
2. jika shift aktif, kasir dapat cari produk dan membangun cart
3. cart dapat di-hold lalu di-resume
4. checkout membuat transaksi, detail, profit, dan pengurangan stok
5. jika `pay_later`, sistem membuat receivable (pelanggan wajib; tanpa pelanggan checkout ditolak)
6. user diarahkan ke dokumen print / invoice

## Retry a failed payment

When an online payment (QRIS, Midtrans, Xendit, bank transfer) of a POS sale is `failed` or `expired`, the cashier
recovers the sale from its receipt (`transactions.print`) instead of cancelling it:

- **Coba lagi** starts the same method again. **Bayar dengan cara lain** offers cash, QRIS, a gateway or bank transfer.
- Requires `transactions-access` and an open cashier shift in the sale's outlet (route `transactions.retry-payment`,
  `TransactionPaymentRetryService`). The sale then counts in the shift where the retry happens.
- Each retry is a new *payment attempt* (`transactions.payment_attempt`). The gateway order id is `{invoice}-r{n}`,
  so the webhook can tell attempts apart. A callback for any attempt but the current one never changes the sale:
  a late *paid* one sets `late_superseded_payment_at`, writes the audit entry `payment.late_superseded` and shows
  "Bayar ganda — refund manual" in the transactions history (filter "Perlu refund"); the refund itself is manual.
- Stock is not touched by a retry. A sale that `ExpirePendingTransactionsCommand` already cancelled and restocked
  cannot be retried; the cashier is told to make a new sale.
- A retried sale expires by the age of its last retry (`updated_at`), not of the sale, so a retry late in a long shift is not restocked minutes later.
- A retry moves the sale to the retrying cashier's shift (`cashier_shift_id`) but keeps `cashier_id`: that is who made the sale.
- A split sale that already has a paid part is not retried (a retry replaces every tender).
- Web POS only; the mobile API does not retry.

## Integrasi Data

- `transactions`
- `transaction_details`
- `profits`
- `receivables`
- `bank_accounts`
- `payment_settings`

## Batasan Saat Ini

- split payment tidak tersedia saat offline; refund split pada versi ini dicatat sebagai refund tunai

- operasi cart dan checkout bergantung pada shift aktif
- payment gateway bergantung pada konfigurasi valid
- checkout masih menjadi pusat perubahan stok penjualan

## File Sentral

- `routes/web.php`
- `app/Http/Controllers/Apps/TransactionController.php`
- `resources/js/Pages/Dashboard/Transactions`
