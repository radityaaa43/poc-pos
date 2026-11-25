# Reports & Documents

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Menyediakan visibilitas operasional melalui laporan dan dokumen siap cetak / share.

## Fitur Saat Ini

- laporan penjualan
- laporan profit
- Advanced Sales Insights
- invoice transaksi publik dan internal
- receipt thermal 58mm / 80mm
- shipping label
- PDF receivable
- PDF payable

## Halaman dan Route

- `dashboard/reports/sales`
- `dashboard/reports/profits`
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
- `receivables`
- `payables`
- `settings` untuk identitas toko

## Advanced Sales Insights

Dashboard insights menyediakan metrik operasional seperti penjualan per jam,
performa kasir, repeat customer, dan ringkasan tren yang membantu pemilik mengambil
keputusan berbasis data.

## Kitchen Speed Report

Read-only report **Laporan Kecepatan Dapur** (`reports.kitchen-speed.index`, menu Reports, permission `reports-access`).

- Filters: outlet (only the outlets the user may see) and a date range; the default is the current month in the app timezone.
- The period counts a kitchen item on the day it was accepted (paid), in the app timezone. Only `ready` and `served` items count; cancelled, *tidak diambil* and release-carried items are left out.
- Per menu item: *Jumlah pesanan* (kitchen item rows), *Jumlah porsi* (summed quantity), median and average of *waktu buat* (preparing to ready) and of *waktu tunggu + buat* (accepted to ready), the product's `prep_target_minutes` and the share of items made within it (current target, so changing a target changes old numbers). An item without a step time counts only where both ends exist.
- One card with the average time from payment to the last item served, over orders whose live items are all served.
- Medians are computed in PHP (`KitchenSpeedReportService`); minutes show with one decimal ("4,5 mnt"). An empty period says so instead of showing zeros.

## Efek Bisnis Penting

- laporan sales dan profit bergantung pada kualitas data transaksi
- sales return dan inventory correction dapat memengaruhi pembacaan operasional pada laporan terkait

## Batasan Saat Ini

- cakupan metrik mengikuti data transaksi, customer, dan shift yang tersedia
- analitik tidak menggantikan laporan akuntansi atau forecasting penuh

## File Sentral

- `app/Http/Controllers/Reports/SalesReportController.php`
- `app/Http/Controllers/Reports/ProfitReportController.php`
- `app/Http/Controllers/DocumentController.php`
