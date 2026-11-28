# Discount Approval

Discount approval mengontrol diskon yang melewati batas kebijakan sebelum checkout diselesaikan.

## Alur

1. Kasir membuat permintaan diskon pada transaksi.
2. Sistem menyimpan status `pending` dan detail nilai diskon.
3. User dengan permission approval meninjau permintaan.
4. Permintaan dapat `approved` atau `rejected` dengan catatan.
5. Checkout hanya memakai diskon yang sudah disetujui.

## Pengecualian Kredit

Halaman yang sama (`/dashboard/discount-approvals`) juga memuat pengajuan pengecualian kredit. Halaman terbuka untuk
`discounts-approve` atau `customers-credit-approve`; tiap daftar hanya tampil bagi pemegang izinnya, dan hanya untuk
outletnya. Aturannya di `receivables.md` (Batas Piutang).

## Audit dan Permission

Approval menyimpan perubahan status dan aktor pada audit log. Akses kasir dan approver dipisahkan melalui permission discount approval.

## File Sentral

- `app/Http/Controllers/Apps/DiscountApprovalController.php`
- `app/Models/DiscountApprovalLog.php`
- `app/Services/CreditApprovalService.php`, `app/Http/Controllers/Apps/CreditApprovalController.php`
- `docs/features/pos-transactions.md`
