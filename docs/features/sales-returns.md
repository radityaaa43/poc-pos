# Sales Returns

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Mengoreksi transaksi penjualan yang sudah terjadi melalui retur parsial atau penuh yang tetap menjaga stok, profit, dan piutang tetap sinkron.

## Fitur Saat Ini

- create draft sales return dari histori transaksi
- update draft retur
- complete retur
- refund tunai
- store credit
- restock ke inventory
- koreksi receivable untuk transaksi `pay_later`
- histori retur penjualan

## Halaman dan Route

- `dashboard/transactions/history`
- `dashboard/sales-returns`
- `sales-returns.create`
- `sales-returns.store`
- `sales-returns.show`
- `sales-returns.update`
- `sales-returns.complete`

## Permission

- `sales-returns-access`
- `sales-returns-create`
- `sales-returns-complete`

## Alur User

1. user membuka histori transaksi
2. jika transaksi masih punya qty yang returnable, tombol retur tampil
3. user membuat draft retur dari transaksi asal
4. user memilih qty retur, alasan, dan opsi restock
5. user menyimpan draft
6. user menyelesaikan retur
7. sistem memperbarui stok, profit, dan receivable bila relevan

## Integrasi Data

- `sales_returns`
- `sales_return_items`
- `customer_credits`
- `transactions`
- `transaction_details`
- `profits`
- `receivables`
- `stock_mutations`

## Restock

Completing a return puts every line with restock ticked back into the sale's warehouse, in `product_warehouse.stock` and `products.stock` (`SalesReturnRestockService`).

- A plain product gets the returned quantity × conversion factor back, with one stock mutation.
- A recipe (composite) menu holds no stock: each component gets recipe quantity × returned quantity × conversion factor back, rounded to whole base units per return, with one mutation per component (warehouse stock before and after). The menu's stock is untouched. The same routine restocks a cancelled unpaid sale.
- The recipe is read at completion, not as it was at the sale.
- Returns of recipe menus completed before #486 restocked the menu itself and are not corrected (no backfill).

## Kitchen items

Completing a return (`sales-returns-complete`, web and API, in the return's own transaction) takes the returned lines off the kitchen (`KitchenItemService::cancelForReturn`). A draft touches nothing.

- A partial return lowers the kitchen item's quantity; the item is *Dibatalkan* (cancelled) only when its whole quantity is returned, and keeps its last quantity.
- A served item is a normal return and the kitchen queue is not touched.
- The kitchen row is locked before any stock row, so a return and a start tap on the same item cannot deadlock: the return wins and the tap is told "Sudah dibatalkan".
- The restock default of a line follows its kitchen status when the form opens: on for *accepted* (and served or without a kitchen item), off for *preparing* or *ready* (with a hint). The person can change it; a saved draft keeps its own choice.
- Money and stock code are unchanged: a refund for a paid sale, a lower receivable for pay later.
- A dine order becomes cancelled only when every sale line is fully returned. Returning just the dishes cancels their kitchen items, but the order still owes its plain lines, so it is served and completed as usual (BR-11, Business 2026-11-26).

## Efek Bisnis Penting

- retur completed bisa menambah stok kembali
- retur pada transaksi piutang bisa mengurangi total receivable
- overpayment dari piutang dapat berubah menjadi refund atau customer credit
- completing a return locks the sale (and its receivable) before working out the refund, so it is serialised with a payment of that sale: a payment committed first is refunded, a return completed first lowers what the payment asks. Two returns of one sale are serialised the same way: the second one checks the remaining quantity with locking reads of the completed returns, so a line is never returned (or restocked) beyond what was sold. Lock order: shift, return, kitchen items, dine order, receivable, sale, the sale's completed returns, stock

## Batasan Saat Ini

- fitur ini bergantung pada migration tabel retur
- flow tukar barang tidak termasuk
- shipping cost tidak menjadi bagian nominal retur

## File Sentral

- `app/Http/Controllers/Apps/SalesReturnController.php`
- `app/Services/SalesReturnRestockService.php`
- `resources/js/Pages/Dashboard/SalesReturns`
