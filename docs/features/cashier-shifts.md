# Cashier Shifts

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Mengontrol sesi kerja kasir agar transaksi kasir memiliki konteks shift yang jelas, termasuk cash opening, cash closing, dan summary operasional.

## Fitur Saat Ini

- open shift
- list shift
- detail shift
- close shift
- force close untuk user berwenang
- ringkasan expected cash, actual cash, selisih, transaksi, dan sales return

## Halaman dan Route

- `dashboard/cashier-shifts`
- `cashier-shifts.store`
- `cashier-shifts.show`
- `cashier-shifts.close`

## Permission

- `cashier-shifts-access`
- `cashier-shifts-open`
- `cashier-shifts-close`
- `cashier-shifts-force-close`

## Alur User

1. kasir membuka shift dengan opening cash
2. route transaksi tertentu mewajibkan shift aktif
3. selama shift aktif, transaksi dan sales return tercatat dalam konteks shift
4. saat penutupan, kasir mengisi actual cash
5. sistem menghitung selisih dan summary shift

## Integrasi Data

- `cashier_shifts`
- transaksi cash / non-cash
- sales returns
- middleware `active_shift`

## Efek Bisnis Penting

- tanpa shift aktif, operasi inti transaksi diblokir
- close shift dipakai untuk rekonsiliasi cash operasional
- rekap non-cash hanya menghitung uang yang sudah diterima: penjualan gateway (`qris`, `midtrans`, `xendit`) yang belum `paid` (pending atau gagal) tidak ikut, dan masuk begitu lunas. Tagihan terbuka (`open_bill`) belum menghitung apa pun. Kredit, split, dan transfer tetap dihitung menurut metodenya
- rekap shift yang sudah ditutup adalah snapshot tersimpan dan tidak pernah berubah. Pembayaran gateway yang masuk setelah shift pemilik tagihan ditutup (atau force close) menandai tagihan `paid_after_close_at` ("Dibayar setelah shift ditutup") dan audit `payment.paid_after_close`

## Batasan Saat Ini

- shift tidak dimaksudkan sebagai workflow approval
- force close dibatasi untuk user tertentu

## File Sentral

- `app/Http/Controllers/Apps/CashierShiftController.php`
- `app/Services/CashierShiftService.php`
- `app/Http/Middleware/EnsureActiveCashierShift.php`
