# Discount Approval

Discount approval mengontrol diskon yang melewati batas kebijakan sebelum checkout diselesaikan.

## Alur

1. Kasir membuat permintaan diskon pada transaksi.
2. Sistem menyimpan status `pending` dan detail nilai diskon.
3. User dengan permission approval meninjau permintaan.
4. Permintaan dapat `approved` atau `rejected` dengan catatan.
5. Checkout hanya memakai diskon yang sudah disetujui.

### Shift ditutup

Penjualan dengan diskon `pending` menahan penutupan shift: barisnya muncul di daftar "Belum selesai" pada halaman shift, dan pemegang permission approval dapat menyetujui atau menolaknya langsung dari daftar itu (kasir tanpa permission hanya melihat barisnya). Close paksa tetap boleh dengan catatan: penjualan dan permintaannya dibiarkan `pending`, uang yang sudah diterima tetap dihitung di rekap shift itu, dan rekap yang tersimpan tidak berubah saat keputusan menyusul. Penolakan setelah shift ditutup ditagih dengan Bayar selisih pada shift yang menerima pembayaran (#509).

## Audit dan Permission

Approval menyimpan perubahan status dan aktor pada audit log. Akses kasir dan approver dipisahkan melalui permission discount approval.

## File Sentral

- `app/Http/Controllers/Apps/DiscountApprovalController.php`
- `app/Models/DiscountApprovalLog.php`
- `docs/features/pos-transactions.md`
