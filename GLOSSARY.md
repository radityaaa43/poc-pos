# Glossary

The domain vocabulary of poc-pos. Code, docs, issues and UI use these terms exactly; when
two words compete for one concept, pick one here and use it everywhere. `/grill-with-docs` and
`/domain-modeling` add terms as they get resolved.

| Term | Meaning | Not to be confused with | In code |
|---|---|---|---|
| Outlet | A branch of the business (e.g. one coffee shop of a chain) that can sell when sales are enabled | Warehouse: an outlet owns one or more warehouses | `Outlet` / `outlets` (`code`, `is_sales_enabled`) |
| Warehouse | A stock location. `PUSAT` is the central, non-sales warehouse (`type = main`) | Outlet: stock is held per warehouse, not per outlet | `Warehouse` / `warehouses` |
| Warehouse stock | Operational stock of one product in one warehouse; the source of truth | Global stock | `ProductWarehouse` / `product_warehouse.stock` |
| Global stock | Sum of a product's warehouse stock, kept in sync in the same transaction | Warehouse stock | `products.stock` |
| Product | Anything sold or stocked, with buy and sell price in whole rupiah | Unit: a product can be sold in several units | `Product` / `products` |
| Composite product (recipe) | A product made of component products; selling it deducts its components' stock | Bundle pricing rule: a price, not a stock recipe | `products.is_composite`, `composite_product_items` |
| Unit / conversion factor | A unit a product is stocked or sold in, and how many base units it equals | Unit of a raw material in a recipe | `Unit`, `ProductUnit` / `product_units.conversion_factor` |
| Raw material | A product flagged `is_raw_material`: stocked and bought, never sold on the POS. Managed on Master Data → Bahan Baku, where "purchased by" says whether the central warehouse (`central`) or each outlet (`outlet`) buys it | Composite product: made of components and sold | `products.is_raw_material`, `products.purchased_by` |
| Needs preparation | A product flagged `needs_preparation`: the kitchen makes it to order, with an optional prep target in minutes (`prep_target_minutes`). A composite product starts flagged on release, but the two are independent and either can be changed per product | Composite product: that is a stock recipe, this is a kitchen flag | `products.needs_preparation`, `products.prep_target_minutes` |
| Kitchen item | A sale line whose product needs preparation, with its status trail (accepted, preparing, ready, served) and who moved it and when. Created once the sale is paid | Dine order: that is the QR order, this is one line of a paid sale | `KitchenItem` / `kitchen_items` |
| Kitchen display | The read-only screen (`/dashboard/kitchen`) listing the open kitchen items of the current outlet, grouped per order, oldest first, refreshed every 5 seconds. Needs `dine-orders-prepare` or `dine-orders-handover` | Dine orders page: that is where staff accept and settle QR orders | `Kitchen/Index.jsx`, `KitchenController`, route `kitchen.index` |
| Purchase unit | The unit a product is bought in. The default is the `product_units` row with `is_purchase_default`; its `buy_price` is the price per that unit | Base unit: the unit stock is kept in | `product_units.is_purchase_default`, `product_units.buy_price` |
| Batch | A received lot of a product in a warehouse, with optional expiry; consumed first-expiry-first | Stock mutation | `ProductBatch` / `product_batches` |
| Stock mutation | One logged stock change (sale, receiving, transfer, opname, return) with before/after quantities | Batch | `StockMutation` / `stock_mutations` |
| Stock opname | A physical stock count that sets warehouse stock to the counted quantity | Stock mutation: opname creates adjustment mutations | `StockOpname` / `stock_opnames` |
| Stock transfer | Moving stock between warehouses: draft → in transit → completed or cancelled | Goods receiving | `StockTransfer` / `stock_transfers` |
| Purchase order (PO) | An order to a supplier | Goods receiving: what actually arrived | `PurchaseOrder` / `purchase_orders` |
| Goods receiving | Recording what arrived from a supplier; adds stock and creates a payable | Purchase order | `GoodsReceiving` / `goods_receivings` |
| Payable / receivable | Money the business owes a supplier / is owed by a customer | Transaction | `Payable`, `Receivable` |
| May buy on credit ("Boleh piutang") | A customer flag: pay later may become a receivable with a due date. Only users with `customers-credit-approve` change it; each change is audit-logged | Receivable: the debt itself | `customers.may_buy_on_credit` |
| Pay later ("Bayar Belakangan") | An outlet setting, off until the outlet turns it on: a sale is made now and paid later, marked "Belum lunas", and goes to the kitchen at once. A receivable with a due date is created only for a customer who may buy on credit; a guest bill needs a note of at least 2 characters. Not available offline. Changed on Settings → Outlets with `outlets-update`, audit-logged | Open bill: a dine-in order accepted but not yet paid | `settings.pay_later_enabled` (per `outlet_id`), `transactions.is_pay_later` |
| Transaction | A completed sale (invoice) with its lines and tenders | Dine order: its transaction is created at accept and paid later (open bill) | `Transaction`, `TransactionDetail` |
| Dine order | A dine-in table order, paid at the counter | Transaction | `DineOrder` / `dine_orders` |
| Open bill | An accepted dine order whose transaction is still `unpaid`; settled from the POS "Tagihan Terbuka" or Transactions History ("Belum Lunas"). Not a credit sale: no receivable. A dine order has its own fulfillment status (`accepted`, `preparing`, `ready`, `served`) apart from the payment status of its transaction; payment comes first (the kitchen starts only on a paid bill, "Menunggu Pembayaran" until then) and the order is `completed` when it is `served` | Receivable (pay later) | `Transaction::scopeOpenBills`, `payment_method = open_bill` |
| Cashier shift | A cashier's open-to-close session with opening and closing cash | | `CashierShift` |
| HPP (cost of goods sold; "harga pokok penjualan") | Cost of what was sold, frozen on each sale line from the selling warehouse's average cost; sale minus HPP is profit | Buy price: the current price, not the cost at sale time | `transaction_details.cost_total` |
| Average cost ("harga pokok persediaan" / "biaya rata-rata") | Weighted-average cost per stock unit of one product in one warehouse; moves on goods receiving (and starts at the reference price on opening stock), other warehouses do not change | Buy price: the reference price, the latest price paid, also the fallback until a warehouse has an average | `product_warehouse.avg_cost`, `InventoryCostService` |
| Backlog item | A requirement file `backlog/REQ-<n>-<slug>.md` with `AC-<n>` acceptance criteria | Issue: the unit of work built from a backlog item | `backlog/` |
| Payment attempt | One try to collect the online payment of a sale; a retry after a failed or expired payment starts the next attempt, with gateway order id `{invoice}-r{n}`. Callbacks of an older attempt never change the sale | Payment status: the sale's state (`pending`, `paid`, `failed`, ...), not one try | `transactions.payment_attempt` |
| Kitchen speed report | Read-only report "Laporan Kecepatan Dapur": per menu item the orders, portions, medians and averages of the make time and the wait + make time, and the share within the target; plus the average time from payment to the last item served | Kitchen display: the live queue of items to make | `KitchenSpeedReportService` |
| Waktu buat | Make time: from *preparing* to *ready* of one kitchen item; the prep target is compared with it | Waktu tunggu + buat | `kitchen_items.preparing_at` to `ready_at` |
| Waktu tunggu + buat | Wait plus make time: from *accepted* (paid) to *ready* of one kitchen item | Waktu buat | `kitchen_items.accepted_at` to `ready_at` |
