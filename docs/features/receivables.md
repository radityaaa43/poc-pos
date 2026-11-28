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
- Pengecualian dengan persetujuan manajer belum ada: dikerjakan terpisah (REQ-007 AC-06).

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
- `app/Services/ReceivableService.php`, `app/Services/CustomerCreditService.php`
- `resources/js/Pages/Dashboard/Receivables`

## Tren piutang macet

Laporan aging menampilkan tren 12 bulan terakhir: total piutang macet pada akhir tiap bulan (dan hari ini untuk bulan berjalan), untuk satu outlet atau semua outlet yang boleh dilihat pengguna.

- Piutang dihitung pada sebuah bulan bila `due_date`-nya sudah lewat sebelum akhir bulan itu, catatannya sudah ada saat itu, dan sisa saat itu (`total - paid + pembayaran yang bertanggal setelah akhir bulan`) masih di atas nol. Pembayaran setelah akhir bulan tidak mengurangi angka bulan itu.
- Jatuh tempo hari ini belum termasuk macet.
- Bulan berjalan memakai hari ini sebagai acuan dan sama dengan bucket `0-30` + `31-60` + `61-90` + `90+` di laporan aging.
- Angka bulan lalu bisa berubah bila penjualan lama dikoreksi (retur, diskon ditolak, pembayaran bertanggal mundur).
