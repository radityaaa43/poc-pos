# Reports & Documents

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Menyediakan visibilitas operasional melalui laporan dan dokumen siap cetak / share.

## Fitur Saat Ini

- laporan penjualan
- laporan profit (termasuk biaya waste dan profit setelah waste)
- laporan waste
- Advanced Sales Insights
- invoice transaksi publik dan internal
- receipt thermal 58mm / 80mm
- shipping label
- PDF receivable
- PDF payable

## Halaman dan Route

- `dashboard/reports/sales`
- `dashboard/reports/profits`
- `dashboard/reports/waste`
- `dashboard/reports/insights`
- `pdf.transactions.invoice`
- `pdf.transactions.receipt`
- `pdf.transactions.shipping`
- `pdf.receivables.show`
- `pdf.payables.show`

## Permission

- `reports-access`
- `profits-access`
- `reports-insights-access`
- akses dokumen mengikuti modul asal seperti `transactions-access`, `receivables-access`, dan `payables-access`

## Alur User

1. user membuka laporan sales atau profit
2. user memfilter data
3. user membuka dokumen transaksi atau finansial terkait
4. dokumen bisa dipakai untuk print/share

## Integrasi Data

- `transactions`
- `transaction_details`
- `profits`
- `stock_wastes` untuk biaya waste (lihat bagian Waste di bawah)
- `receivables`
- `payables`
- `settings` untuk identitas toko

## Advanced Sales Insights

Dashboard insights menyediakan metrik operasional seperti penjualan per jam,
performa kasir, repeat customer, dan ringkasan tren yang membantu pemilik mengambil
keputusan berbasis data.

## Kitchen Speed Report

Read-only report **Laporan Kecepatan Dapur** (`reports.kitchen-speed.index`, menu Reports, permission `reports-access`).

- Filters: outlet (only the outlets the user may see), **Stasiun** and a date range; the default is the current month in the app timezone and *Semua stasiun*.
- **Stasiun**: a menu item gets one row per kitchen station, shown in the *Stasiun* column after the menu, because an item keeps the station it had at intake (a menu moved to another station mid-period shows two rows). With a station chosen (`station_id`) the per-menu figures count only that station's items; an item without a station counts as the default station (Dapur). Deleted stations keep their name on past items, are listed after the active stations as `<name> (dihapus)`, and can still be chosen; a renamed station shows its current name.
- The period counts a kitchen item on the day it was accepted (paid), in the app timezone. Only `ready` and `served` items count; cancelled, *tidak diambil* and release-carried items are left out.
- Per menu item: *Jumlah pesanan* (kitchen item rows), *Jumlah porsi* (summed quantity), median and average of *waktu buat* (preparing to ready) and of *waktu tunggu + buat* (accepted to ready), the product's `prep_target_minutes` and the share of items made within it (current target, so changing a target changes old numbers). An item without a step time counts only where both ends exist.
- One card with the average time from payment to the last item served, over orders whose live items are all served. It is order level, so the station filter does not narrow it; with a station chosen the card carries the caption *Semua pesanan dari semua stasiun*.
- Medians are computed in PHP (`KitchenSpeedReportService`); minutes show with one decimal ("4,5 mnt"). An empty period says so instead of showing zeros.

## Profit Report: Biaya Waste dan Waste Report

Laporan profit menampilkan **Biaya waste** sebagai baris sendiri dan **Profit setelah waste** (profit penjualan dikurangi biaya waste), dihitung dari `stock_wastes` yang berstatus `recorded`/`cancelled` pada `recorded_at` di periode itu, dikurangi nilai yang dibatalkan pada `cancelled_at` di periode itu. Jadi pembatalan mengurangi periodenya sendiri; `pending` dan `rejected` tidak pernah dihitung. Nilai waste tidak masuk ke HPP penjualan: `profit_total` dan `margin` tetap penjualan saja. Kedua baris disembunyikan (dengan catatan penjelasan) saat filter faktur, kasir atau pelanggan aktif, karena waste tidak punya ketiganya. Nilainya juga ada di `GET /api/v1/reports/profits` (`summary.waste_cost_total`, `summary.profit_after_waste`).

## Profit Report: how a sale's profit is worked out

One `profits` row per sale line, written at checkout by `ProfitLedgerService`: the line's price, less its share of every bill-level discount (manual, redeemed points, voucher) and less the PPN its price includes, less its frozen HPP. A promotion is already inside the line price, so it is never taken off twice; service charge, tax on top (PB1, exclusive PPN) and shipping are added to the bill but are not revenue, so they never enter profit. The shares use cumulative rounding, so the rows add up to the sale's net revenue less HPP to the rupiah. Denying a manual discount restores the bill and rebuilds the rows; approving keeps them.

This is forward only: sales recorded before this rule keep their stored profit, so a voucher or points sale from before it still shows the overstatement it recorded. The report has no before/after marker.


**Laporan Waste** (`reports.waste.index`, menu Reports, permission `profits-access`) menampilkan satu outlet dan satu periode (default bulan berjalan di zona waktu aplikasi): total biaya waste, jumlah dan nilai per item (menu tampil sebagai menu, nilainya dari harga pokok bahan resep) dan jumlah catatan serta nilai per alasan. Pembatalan mengurangi nilainya; sebuah item yang bersih nol pada periode itu tidak ditampilkan.

## Efek Bisnis Penting

- laporan sales dan profit bergantung pada kualitas data transaksi
- sales return dan inventory correction dapat memengaruhi pembacaan operasional pada laporan terkait
- laporan sales punya baris sendiri "Dibayar setelah shift ditutup" (`summary.paid_after_close_count` dan `summary.paid_after_close_total`, juga di `GET /api/v1/reports/sales`): jumlah dan total penjualan yang lunas setelah shift pemiliknya ditutup dengan uang yang belum ada di rekap shift itu (daftar jalurnya di `cashier-shifts.md`, tagihan dine-in di kasir dan retry tunai termasuk; penjualan split dihitung sebesar total penjualannya), dihitung pada tanggal penjualannya dan sudah termasuk dalam total periode. Baris tersembunyi bila 0, dan barisnya diberi lencana yang sama. Rekap shift yang sudah ditutup tidak berubah (lihat `cashier-shifts.md`)

## Batasan Saat Ini

- cakupan metrik mengikuti data transaksi, customer, dan shift yang tersedia
- analitik tidak menggantikan laporan akuntansi atau forecasting penuh

## File Sentral

- `app/Http/Controllers/Reports/SalesReportController.php`
- `app/Http/Controllers/Reports/ProfitReportController.php`
- `app/Http/Controllers/Reports/WasteReportController.php`
- `app/Services/StockWasteReportService.php`
- `app/Http/Controllers/DocumentController.php`
