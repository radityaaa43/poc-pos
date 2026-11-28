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
- `transactions.sync`, `transactions.offline-discard` (antrean offline: `docs/features/offline-mode.md`)

## Permission

- `transactions-access`

Operasi transaksional tertentu juga mewajibkan middleware `active_shift`.

## Alur User

1. kasir membuka halaman transaksi
2. jika shift aktif, kasir dapat cari produk dan membangun cart
3. cart dapat di-hold lalu di-resume
4. checkout membuat transaksi, detail, profit, dan pengurangan stok
5. jika `pay_later`: hanya tersedia bila outlet mengaktifkan **Bayar Belakangan** (Pengaturan → Outlet, izin `outlets-update`, tercatat di audit log) dan POS online; checkout ditolak bila setting mati. Transaksi tetap `unpaid` (`is_pay_later`) dan langsung masuk dapur. Receivable dibuat hanya untuk pelanggan dengan flag *Boleh piutang* (wajib tanggal jatuh tempo); tamu atau pelanggan tanpa flag tidak punya receivable, dan tamu wajib mengisi catatan. Antrean offline lama yang berisi pay later ditolak saat sinkronisasi
6. tagihan Bayar Belakangan tanpa piutang (tamu atau pelanggan tanpa flag) muncul di bagian **Belum Lunas** POS dengan label (catatan atau nama pelanggan), jumlah, dan umur. Kasir dengan `transactions-access` dan shift aktif di outlet tagihan melunasinya penuh dengan metode apa pun (`POST /dashboard/transactions/{transaction}/settle`); setelah retur sebagian jumlahnya sisa total, tagihan yang diretur penuh tidak tampil, dan bila dua kasir melunasi bersamaan yang pertama menang. Pelunasan dihitung di shift yang menerimanya dan tidak membuat item dapur kedua. A gateway method is charged after the payment commits, exactly as for a dine-in bill (`dine-in.md`, "Pembayaran tagihan belum lunas"): a failed charge leaves the bill payable, and one left without an answer for 120 s can be paid again
7. user diarahkan ke dokumen print / invoice

## Retry a failed payment

When an online payment (QRIS, Midtrans, Xendit, bank transfer) of a POS sale is `failed` or `expired`, the cashier
recovers the sale from its receipt (`transactions.print`) instead of cancelling it:

- **Coba lagi** starts the same method again. **Bayar dengan cara lain** offers cash, QRIS, a gateway or bank transfer.
- Requires `transactions-access` and an open cashier shift in the sale's outlet (route `transactions.retry-payment`,
  `TransactionPaymentRetryService`). The sale then counts in the shift where the retry happens.
- Each retry is a new *payment attempt* (`transactions.payment_attempt`). The gateway order id is `{invoice}-r{n}`,
  so the webhook can tell attempts apart. A callback for any attempt but the current one never overwrites the sale. A late *paid* one
  is booked as the payment only when the sale is not paid, no pending or paid tender holds it and the amount is what is still owed (audit
  `payment.late_after_failed`); otherwise it sets `late_superseded_payment_at`, writes the audit entry `payment.late_superseded`
  (or `payment.late_after_failed` with `booked: false`) and shows "Bayar ganda — refund manual" in the transactions history
  (filter "Perlu refund"); the refund itself is manual. History also marks a gateway sale that was paid after its shift closed
  with "Dibayar setelah shift ditutup" (filter chip of the same name). A sale that was restocked is never booked late. A failed sale of attempt 0 takes the late path when it has a gateway charge of its own (#380): a gateway tender row, a single-method
  QRIS, Midtrans or Xendit sale (the web POS writes no tender rows for it), or a credit sale charged through the customer portal (a receivable;
  its late payment settles the receivable, and the amount it must match is what the receivable still owes, so a partly paid one can be booked). A gateway payment on a credit sale the cashier already paid in full through the receivable is flagged (a second payment), and so is any part of a gateway payment beyond the receivable's balance: the balance is booked, the rest is flagged "Bayar ganda — refund manual" (audit `payment.late_superseded`, `excess`). A settlement that arrives later is booked when the sale is not paid, no pending or paid tender holds
  it, nothing was restocked and the amount equals the remaining total; otherwise it is flagged "Bayar ganda — refund manual" (audit
  `payment.late_after_failed`, `booked: true|false`). A sale `transactions:expire` already cancelled and restocked is always flagged. A sale
  settled by cash after the QR expired, or with a paid cash part in a split, is flagged too, its payment unchanged. Booked after the sale's shift
  closed, it is marked "Dibayar setelah shift ditutup" and the closed recap is unchanged; a credit sale paid late through the portal is never flagged paid after close, because the closed recap already counted it by method.
  A failed sale with no gateway charge of its own (no gateway tender, no receivable, a non-gateway method such as a pay-later guest bill) stays failed.
- **Second payment on a paid sale** (POS, customer portal or dine-in, #391): a gateway settlement whose reference the sale does not store (on the
  sale or a tender), such as the same QR paid twice, is flagged "Bayar ganda — refund manual" and audited (`payment.late_superseded`), never
  booked. The same reference delivered again changes nothing. A paid sale that stores no reference cannot be compared: unless something else
  shows a second payment (settled without a gateway, or another attempt's order id), it is not flagged, only audited once per reference
  (`meta.flagged: false`) for the owner to reconcile with the gateway statement.
- Stock is not touched by a retry. A sale that `ExpirePendingTransactionsCommand` already cancelled and restocked
  cannot be retried; the cashier is told to make a new sale.
- A retried sale expires by the age of its last retry (`updated_at`), not of the sale, so a retry late in a long shift is not restocked minutes later.
- A retry moves the sale to the retrying cashier's shift (`cashier_shift_id`) but keeps `cashier_id`: that is who made the sale.
- A split sale that already has a paid part is not retried (a retry replaces every tender).
- A retry asks what the sale still owes after returns (`Transaction::remainingTotal()`), not its total, and a fully
  returned sale is refused.
- A credit sale (it has a receivable) is never retried: the receipt offers neither **Coba lagi** nor **Bayar dengan cara
  lain**, and the route refuses with "Penjualan kredit dibayar lewat piutang". Its money is collected through the
  receivable, so it is charged once and for what it still owes (#441).
- When returns put an unpaid or pending sale below its total, the amount to ask (`Transaction::amountDue()`, the rule the
  shift recap counts by) is shown as *Sisa tagihan* on the print page, Riwayat (rows and the transfer confirmation), the
  public invoice page, thermal receipts, the PDF invoice and receipts and the shipping label. Confirming a transfer takes
  no amount: it only marks the sale paid. The mobile API still returns `grand_total` only.
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

- `routes/web/transactions.php`
- `app/Http/Controllers/Apps/TransactionController.php`
- `resources/js/Pages/Dashboard/Transactions`
