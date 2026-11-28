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
- a pay-later or dine-in bill, and a sale whose payment was retried, counts what it was asked for, not its total, in the bucket it is counted in (cash, or non-cash for gateway, transfer and split). A counter payment asks the sum of its tenders, so a return recorded while it waits changes nothing until it is paid. A guest online payment or a payment retry writes no tenders: the returns that refunded nothing (recorded before payment) are taken off. A return after payment counts under refunds as before, and credit sales are unchanged. No sale takes off more than its total (returns are priced before a bill discount), and a bill booked as a shortfall by a forced close, which the sales totals leave out, takes off nothing. A payment of more than the sale owes after its returns (a return completed while it waited) counts only what was owed, for every sale, a checkout one too: the excess is stored in `transactions.overpaid_amount`, flagged for a manual refund and never a sale (#444). It comes off the bucket the sale is counted in; the part of a split's excess beyond its non-cash tenders comes off the cash sales (so off expected cash). A closed shift keeps its recap
- rekap shift yang sudah ditutup adalah snapshot tersimpan dan tidak pernah berubah. Pembayaran gateway yang masuk setelah shift pemilik tagihan ditutup (atau force close) menandai tagihan `paid_after_close_at` ("Dibayar setelah shift ditutup") dan audit `payment.paid_after_close`

## Closing a shift: hold, force close, auto-cancel

Everything below counts only the orders whose transaction is on the closing shift (`transactions.cashier_shift_id`; for dine-in, the shift that accepted the order). Other shifts never hold the close and are never touched.

- **What holds a close** (`ShiftCloseBlockerService`, listed on the shift page under "Belum selesai"). A held close is refused ("Shift tidak bisa ditutup: ...") and changes nothing; the API answers 422 with the list in `errors.blockers` (`/api/v1/cashier-shifts/{id}/close` and `/api/v1/pos/shift/close`). It is held by:
  - a kitchen item not handed over (`accepted`, `preparing` or `ready`);
  - an unpaid bill from the POS "Belum Lunas" list: a dine-in bill, or a guest's Bayar Belakangan sale, including one with a waiting or failed online attempt. A credit customer's receivable never holds the close.
- **Force close** (permission `cashier-shifts-force-close`, the owner of the shift included; password step-up as before). A held shift closes only when the request itself says `force: true`, a manager closing someone else's shift too: a page loaded before the hold appeared is refused with the list. When something holds the shift, a note of at least 3 characters is required, and:
  - the held items become `not_collected` (Tidak diambil);
  - the unpaid bills are booked in `cashier_shifts.unpaid_bills_total` as a shortfall: `cash_difference = actual_cash - expected_cash - unpaid_bills_total`. They are left out of non-cash sales, so the recap counts them once. A partly paid bill books only what is still owed;
  - the bills stay payable; a later payment counts in the shift that takes it, and the closed recap does not change;
  - audit: `cashier_shift.hold_overridden` (note, items, bills, total, cancelled sales) beside `cashier_shift.force_closed`.
- **Auto-cancel of unpaid Bayar sekarang sales.** Every close that goes through cancels the shift's sales with no money received (pending, failed or expired): stock goes back to `product_warehouse` and `products`, the sale is marked failed, and `transaction.cancelled_on_shift_close` is audited. It uses the same routine as `transactions:expire` (`UnpaidSaleCancellationService`). Not cancelled: bills, dine-in orders, a split with a paid part, and a sale with a completed sales return. An unconfirmed bank transfer is unpaid and is cancelled too: confirm it before closing. A cancelled sale leaves every recap total and the transaction count.

## Batasan Saat Ini

- shift tidak dimaksudkan sebagai workflow approval
- force close dibatasi untuk user tertentu

## File Sentral

- `app/Http/Controllers/Apps/CashierShiftController.php`
- `app/Services/CashierShiftService.php`
- `app/Http/Middleware/EnsureActiveCashierShift.php`
