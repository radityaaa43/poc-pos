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
- penjualan walk-in: tunai, QRIS, dan transfer selesai tanpa memilih pelanggan; pelanggan wajib hanya untuk memakai poin, voucher, atau harga member; pay later (Bayar Belakangan) tidak lagi butuh pelanggan: tamu cukup mengisi catatan minimal 2 karakter

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
5. jika `pay_later`: hanya tersedia bila outlet mengaktifkan **Bayar Belakangan** (Pengaturan → Outlet, izin `outlets-update`, tercatat di audit log) dan POS online; checkout ditolak bila setting mati. Transaksi tetap `unpaid` (`is_pay_later`) dan langsung masuk dapur. Receivable dibuat hanya untuk pelanggan dengan flag *Boleh piutang* (wajib tanggal jatuh tempo); tamu atau pelanggan tanpa flag tidak punya receivable, dan tamu wajib mengisi catatan. Antrean offline lama yang berisi pay later ditolak saat sinkronisasi
6. tagihan Bayar Belakangan tanpa piutang (tamu atau pelanggan tanpa flag) muncul di bagian **Belum Lunas** POS dengan label (catatan atau nama pelanggan), jumlah, dan umur. Kasir dengan `transactions-access` dan shift aktif di outlet tagihan melunasinya penuh dengan metode apa pun (`POST /dashboard/transactions/{transaction}/settle`); setelah retur sebagian jumlahnya sisa total, tagihan yang diretur penuh tidak tampil, dan bila dua kasir melunasi bersamaan yang pertama menang. Pelunasan dihitung di shift yang menerimanya dan tidak membuat item dapur kedua
7. user diarahkan ke dokumen print / invoice

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
