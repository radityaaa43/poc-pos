# Receivables

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Mencatat piutang pelanggan yang berasal dari transaksi `pay_later` dan menyediakan alur pelunasan bertahap.

## Fitur Saat Ini

- list piutang
- filter status, customer, invoice, due date
- batas piutang per pelanggan: kolom **Batas** dan **Terpakai %**, saringan **Mendekati batas (≥ 80%)** (termasuk yang melewati batas), **Melewati batas**, dan **Tanpa batas** (hanya pelanggan *Boleh piutang* tanpa batas)
- detail piutang
- pembayaran parsial
- status `unpaid`, `partial`, `paid`, `overdue`
- PDF receivable
- reminder jatuh tempo dan overdue melalui automation CRM
- pengiriman langsung melalui WhatsApp jika service aktif dan device terhubung

## Halaman dan Route

- `dashboard/receivables`
- `receivables.show`
- `receivables.pay`
- `pdf.receivables.show`

## Permission

- `receivables-access`
- `receivables-pay`

## Alur User

1. checkout `pay_later` membuat receivable
2. user memantau daftar piutang
3. user mencatat pembayaran
4. sistem memperbarui nilai `paid`, `remaining`, dan `status`

## Batas Piutang

- **Terpakai** adalah sisa semua piutang pelanggan yang belum lunas **di semua outlet**, walaupun baris daftar
  tetap dibatasi outlet pengguna. Dihitung saat dibaca (`Receivable::scopeOutstanding`), tidak disimpan.
- **Terpakai %** dibulatkan ke bawah. Batas Rp 0 menampilkan "—", dan utang berapa pun berarti **Melewati batas**.
- Baris tanpa pelanggan, atau pelanggan yang tidak boleh piutang dan tidak punya batas, menampilkan "—".
- **Batas piutang** diisi di halaman detail pelanggan (`customers-credit-approve`); lihat `member-management.md`.
- Checkout bayar belakangan (web dan API) menolak pelanggan **yang punya batas** bila Terpakai + total melewati
  batas (**Melebihi batas piutang — sisa Rp X**; tepat sebesar sisa masih boleh), lalu bila ada piutang lewat jatuh
  tempo yang masih bersisa (**Lunasi piutang yang lewat jatuh tempo dulu**). Pelanggan **Tanpa batas** tidak pernah
  ditolak. Penolakan membatalkan seluruh penjualan (stok, dapur, poin) dan keranjang tetap ada: kasir bisa menerima
  pembayaran sekarang. Batas yang diturunkan di bawah Terpakai langsung menolak utang baru.
- Pemeriksaan ada di `CustomerCreditService::guard`, dalam transaksi checkout yang membuat piutang, dengan baris
  pelanggan dikunci, sehingga dua kasir tidak bisa bersama-sama melewati satu batas.

### Pengecualian dengan persetujuan

- Penolakan di POS web membuka dialog: **Bayar sekarang**, **Minta pengecualian**, **Batal**. API tidak bisa mengajukan.
- **Minta pengecualian** menahan keranjang (**Menunggu persetujuan kredit**) dan membuat satu `credit_approval_requests`.
  Belum ada penjualan, stok keluar, piutang, atau pesanan dapur. Keranjang itu tidak bisa dilanjutkan, dihapus, atau
  diubah (web dan API) selama menunggu.
- Pemegang `customers-credit-approve` di outlet itu memutuskan di halaman Approval Diskon, dengan alasan wajib
  (3 sampai 500 karakter). Boleh memutuskan pengajuannya sendiri; kedua nama tercatat di audit.
- **Setujui** menjalankan checkout sekali atas nama kasir di shift yang sama: piutang dibuat, dapur menerima pesanan,
  batas tidak berubah. Ditolak sistem (pengajuan tetap menunggu) bila shift sudah ditutup, total berubah, stok kurang,
  izin piutang pelanggan sudah dicabut, atau pay later dimatikan untuk outletnya. Persetujuan kedua tidak mengubah apa pun. Diskon yang melewati ambang
  tetap menunggu approval diskon.
- **Tolak**: kasir melihat alasannya di daftar transaksi ditahan, lalu melanjutkan (bayar sekarang) atau menghapusnya.
- Audit: `credit_approval.requested`, `.approved`, `.denied` pada pelanggan.
- Urutan lock: shift kasir, pelanggan, pengajuan, keranjang, stok.

## Integrasi Data

- `receivables`
- `receivable_payments`
- `transactions`
- `customers`
- `bank_accounts`

## Efek Bisnis Penting

- pembayaran receivable ikut memengaruhi `payment_status` transaksi terkait, dan sebaliknya: notifikasi lunas dari gateway (Midtrans/Xendit) mencatat `receivable_payments` sebesar nominal yang dibayar (maksimal sisa piutang)
- sales return dapat mengoreksi total receivable jika retur berasal dari transaksi piutang

## Reminder dan Delivery

Reminder dapat dibuat untuk jatuh tempo, overdue, dan repeat order. Command scheduler
menghasilkan pekerjaan reminder secara berkala. Delivery dapat menggunakan WhatsApp
Gateway jika `wa_enabled=true` dan device sudah terhubung; jika tidak, campaign dapat
ditangani melalui link WhatsApp atau follow-up manual.

Belum ada approval flow terpisah untuk pencatatan pembayaran receivable.

## File Sentral

- `app/Http/Controllers/Apps/ReceivableController.php`
- `app/Services/ReceivableService.php`, `app/Services/CustomerCreditService.php`, `app/Services/CreditApprovalService.php`
- `resources/js/Pages/Dashboard/Receivables`
