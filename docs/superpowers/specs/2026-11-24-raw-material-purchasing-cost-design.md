# Design: raw material purchasing in purchase units, and HPP per warehouse

- **Date:** 2026-11-24
- **Backlog:** REQ-002
- **Status:** draft, for owner approval
- **Builds on:** REQ-001 (recipe products and the raw material flag, merged in PR #8)

## 1. Intent

The app runs the daily operations of a multi-outlet food business. For example, a coffee chain
with a central warehouse and two outlets. Menu items are recipes over raw materials (an
Americano = 18 g coffee beans + 200 ml water + 1 cup).

- Suppliers sell in **purchase units** (kg, litre, pack). Stock and recipes use the **smallest
  unit** (g, ml, pcs).
- Purchasing is **mixed**: the central warehouse buys main ingredients (beans, milk) and each
  outlet buys small items (ice, lemons). Prices differ by warehouse.
- The owner needs **HPP and profit per outlet** they can trust.

This design adds purchase units to the purchasing chain. HPP becomes a weighted average **per
warehouse**, driven by the price typed on each purchase document (owner's decision: prices are
entered per purchase and the system computes HPP). HPP is frozen on every sale line.

## 2. Goals, non-goals, anti-goals

**Goals**

1. A **Raw Materials** screen to manage raw materials: stock unit, purchase units with conversion
   and price, minimum stock, "purchased by" (central or outlet), and opening stock.
2. Purchase orders, goods receiving, supplier returns and auto-generated draft POs work in purchase
   units. Stock, batches and stock mutations are always recorded in the stock unit.
3. **Weighted-average HPP per warehouse**, updated on every goods receiving from the PO price.
4. **HPP frozen per sale line** (web POS, POS API and offline sync, dine-in). The profit report,
   Insights and sales returns read the frozen value, so past profit never moves.
5. Recipe components show their quantity with the stock unit.
6. Fix the existing purchasing bugs that break this chain (§6.8).

**Non-goals** (other backlog items or out of scope)

- Central-to-outlet distribution with requests, approval and receipt variances, and the stock
  monitoring screen (next item). Here, the existing stock transfer only carries HPP (§6.5).
- Batch and expiry consumption for recipe components (next item).
- Waste recording, stock opname in purchase units, opname variance value, recipe sales-return
  restock, and shrinkage lines in the profit report (next item).
- Units or prices per supplier, decimal quantities, freight or discount allocation into HPP,
  transfer markup, a full P&L with operating expenses, per-warehouse minimum stock, and rebuilding
  the demo data.

**Anti-goals** (a failure even if it "works")

- Stock or HPP silently off: receiving "5 kg" adds 5 to stock.
- Profit of a past period changes after a new purchase.
- Staff must type the smallest unit when purchasing (5000 for 5 kg).
- Bundle behaviour breaks: a composite without its own price must still cost the sum of its
  components.
- Existing API clients that send PO or receiving items without a unit break.
- Outlet or warehouse access checks get weaker.

## 3. Constraints

- Laravel 13, Inertia 3 + React 19. Tests run on in-memory SQLite, so migrations and backfills
  must not use MySQL-only SQL.
- `stock` and every quantity are **integers**. Product and transaction money is whole rupiah. The
  new average cost column may be decimal.
- Every stock change runs inside `DB::transaction` with `lockForUpdate()`. `product_warehouse.stock`
  is the source of truth, and `products.stock` changes in the same transaction.
- `OutletAccessService` checks stay on every path.
- The `Api/V1/*` controllers extend the web controllers but **copy** their store and complete logic
  (purchase orders, goods receiving, supplier returns, sales returns). Change both or centralise.
- Purchasing pages hardcode Indonesian UI text without i18n. The sidebar uses i18n keys in
  `resources/js/i18n/locales/{id,en}.json`.
- Hotspot files: `routes/web.php`, `routes/api.php`, `resources/js/Utils/Menu.jsx` and the locale
  files. Append, don't reorder, and name them in the PR.
- PRs should stay under about 300 counted lines, so this item ships as several issues (§9).

## 4. Approaches

| Area | Chosen | Rejected |
|---|---|---|
| Where HPP lives | **A1:** weighted average per warehouse in `product_warehouse.avg_cost` (decimal), and the HPP of each sale line frozen in `transaction_details.cost_total` | A2, cost per batch: depends on the batch item and departs from the agreed average method. A3, one global average: wrong under mixed purchasing |
| Raw material model | **B1:** stay `products` with `is_raw_material`, plus a dedicated screen and a lean form | B2, a separate table: would duplicate PO, receiving, opname, transfer and batches. B3, a filter on Products: the product form is too heavy for warehouse staff |
| Purchase units | **C1:** reuse `product_units` with a default-purchase-unit flag. Purchasing documents snapshot `unit_id` and `conversion_factor` | C2, units or prices per supplier: too large now, and can be built on C1 later |
| Price source | **X:** price typed on each PO (default from the purchase unit price), HPP computed by the system | Y, a manual master price used as HPP: profit misleads when supplier prices rise |

## 5. Decided by the owner

- Daily operations across outlets. Mixed purchasing: central buys main ingredients, outlets buy
  small items. Distribution follows in a later item.
- Prices are typed per purchase, and HPP is computed automatically (X). Approach A1 + B1 + C1.
- Weighted-average HPP **per warehouse**, transfers at cost without markup, and integer quantities
  in the smallest unit.
- Each raw material has **purchased by: central / outlet**, which scopes auto-generated draft POs.
- The payable equals all receipts of the PO minus completed supplier returns, and payments are kept.
- Stored per-unit buy prices on existing non-base units are corrected to base price × conversion.
- Each receiving updates the purchase unit price and the reference buy price to the latest price.
- A raw material with only an opening-stock mutation may be deleted. Recipe use or any other
  history blocks deletion.

## 6. Design

### 6.1 Data (new migrations)

| Table | Change | Notes |
|---|---|---|
| `units` | Insert `GRAM` / Gram / `g` and `ML` / Mililiter / `ml` when missing | Idempotent by `code` |
| `product_units` | `is_purchase_default` boolean, default false | At most one per product, enforced in the app |
| `product_units` (data) | Non-base rows with `conversion_factor > 1` and `buy_price` at or below the product's base `buy_price` become `round(base buy_price × conversion_factor)` | The form never exposed this field, so those rows hold a base price (equal or older and lower). Rows already priced per pack (e.g. the demo seeder's) stay unchanged |
| `products` | `purchased_by` string nullable: `central` / `outlet` | Required for raw materials; null keeps today's reorder behaviour |
| `product_warehouse` | `avg_cost` decimal(15,4) nullable | Rupiah per **stock unit** in that warehouse. Backfill from `products.buy_price` |
| `purchase_order_items` | `unit_id` nullable FK, `conversion_factor` decimal(15,4) default 1 | `qty_ordered`, `qty_received` and `unit_price` are in this unit |
| `goods_receiving_items` | `unit_id`, `conversion_factor` | Copied from the PO item; `qty_received` in this unit |
| `supplier_return_items` | `unit_id`, `conversion_factor` | `qty_returned` and `unit_price` in this unit |
| `stock_transfer_items` | `unit_cost` decimal(15,4) nullable | Source warehouse average cost at send time |
| `transaction_details` | `cost_total` bigInteger nullable | Line HPP in whole rupiah. Backfill in §6.6 |

Existing rows without `unit_id` mean the stock unit with factor 1. Each migration ships in the
issue that first uses it (§9), not all up front.

### 6.2 Units and purchase units

- Every product has exactly one base unit (`is_base`), which is its stock unit. Products without
  units keep today's behaviour: the form seeds PCS as base.
- The **default purchase unit** is the `product_units` row with `is_purchase_default = true`. When
  there is none, the base unit is used.
- `product_units.buy_price` is the price **per that unit** (Rp 250,000 per kg). It is the default
  price on PO lines.
- The Products form units block gets a buy price per unit and a "default purchase unit" choice. The
  server rejects more than one default.
- `products.buy_price` becomes the **reference buy price per stock unit**. It is a default and the
  HPP fallback when `avg_cost` is empty. For raw materials it is derived on save from the default
  purchase unit price ÷ conversion, rounded to whole rupiah. Receiving updates it (§6.4).

### 6.3 Raw Materials screen

- **Sidebar:** Master Data → "Bahan Baku", with new key `sidebar.items.rawMaterials` (id
  "Bahan Baku", en "Raw Materials").
- **Routes:** `/dashboard/raw-materials` (index, create, store, edit, update, destroy). They reuse
  the `products-access`, `products-create`, `products-edit` and `products-delete` permissions
  (super-admin and manager have them; cashier does not).
- **List:** only `is_raw_material = true`. Search by name or SKU. Columns: name, category, stock
  unit, purchased by, stock and average cost per warehouse the user can access, minimum stock, and
  default purchase unit.
- **Form:**
  - Name.
  - Category: defaults to a "Bahan Baku" category, created when missing.
  - Stock unit: required.
  - Purchased by: required.
  - Minimum stock, in stock units.
  - Purchase units (zero or more rows): unit, content per unit in stock units, price per unit, and
    default flag.
  - Opening stock (create only, optional): an accessible warehouse, a quantity and its unit.
  - Barcode and SKU are optional and auto-generated (`RM-xxxxx`) when empty. No sell price (saved
    as 0), and `is_raw_material = true` is forced.
- **Delete:** rejected with a clear message when the material is a recipe component (today the
  `composite_product_items.component_product_id` foreign key without cascade makes this a 500) or
  has stock mutations other than its opening stock. Otherwise the existing product delete runs.
- The Products page still lists raw materials, with the "Bahan Baku" badge.

### 6.4 Purchasing in purchase units

**Purchase order (web and API V1)**

- Item fields: `product_id`, optional `unit_id`, `qty_ordered` (integer ≥ 1) and `unit_price` (per
  item unit).
- `unit_id` must belong to the product. When missing, the base unit is used. The server reads
  `conversion_factor` from `product_units` and never takes it from the request.
- Create page: each row has a unit select (default: default purchase unit). The default price is
  that unit's `buy_price`, falling back to `products.buy_price × conversion`. The row shows the
  stock-unit equivalent ("= 5.000 g").
- Show page: ordered, received, outstanding and price in the item unit, plus the stock-unit
  equivalent.

**Goods receiving (web and API V1)**

- Received and outstanding quantities are in the PO item unit. `unit_id` and `conversion_factor`
  are copied to the receiving item.
- Stock quantity = `qty_received × conversion_factor`. If that is not an integer, reject with a
  message.
- It increases the PO warehouse stock and `products.stock`, and creates the batch in stock units.
  The "in" stock mutation is in stock units, with a note such as "Penerimaan 5 kg dari PO …".
- It updates the warehouse average cost (§6.5).
- It updates that purchase unit's `buy_price` to the received `unit_price`, and
  `products.buy_price` to `unit_price ÷ conversion` (rounded).
- Payable: see §6.8 (b).

**Supplier return (web and API V1)**

- An item from a receiving inherits the receiving item's unit. A manual item picks a unit as on the
  PO. Quantity and price are per item unit. Stock decreases by qty × conversion.
- The average cost **does not change** on a return. This is a deliberate, documented
  simplification.
- For a receiving item, the returned quantity may not exceed received minus already returned
  (returns that are not cancelled).

**Auto-generated draft PO (`reorder:generate`)**

- A warehouse is **central** when its outlet has `is_sales_enabled = false` (`PUSAT`). Otherwise
  it is an **outlet** warehouse.
- A central warehouse gets raw materials with `purchased_by = central`. An outlet warehouse gets
  raw materials with `purchased_by = outlet`. Non-raw products (`purchased_by` null) behave as
  today.
- The stock-unit need (`suggestedOrderQty`) converts to the default purchase unit, **rounded up**.
  The price is that unit's `buy_price` (fallback `products.buy_price × conversion`).

### 6.5 Weighted-average HPP per warehouse

One cost component (for example `InventoryCostService`; the name is the builder's call):

- `unitCost(product, warehouseId)` returns the warehouse row's `avg_cost`, falling back to
  `products.buy_price`.
- **Inbound with cost**, called inside the same transaction on the already-locked pivot row:
  `new_avg = (stock_before × old_avg + qty_in × cost_in) ÷ (stock_before + qty_in)`.
  When `stock_before ≤ 0` or `old_avg` is empty, `new_avg = cost_in`. `stock_before` is the stock
  of **that warehouse**.
- **Callers:**
  - Goods receiving: `cost_in = unit_price ÷ conversion_factor`.
  - Opening stock (web product form, Raw Materials form, API product store):
    `cost_in = products.buy_price`.
  - Stock transfer (existing feature):
    - `send` stores `unit_cost` = source warehouse cost per item.
    - `receive` merges that cost into the destination.
    - `cancel` from in transit merges it back into the source.
- **No change to the average:** sales, supplier returns, stock opname, sales returns and gateway
  expiry. A new pivot row created without a cost (opname, sales return, other `firstOrCreate`
  calls) gets `avg_cost = products.buy_price`.
- **Rounding:** `avg_cost` keeps 4 decimals. A line's `cost_total` is rounded to whole rupiah once.

### 6.6 HPP frozen per sale line

- `CheckoutService` (web POS, POS API, offline sync) and `DineOrderService::accept` compute line
  HPP from the transaction warehouse's `unitCost`:
  - Plain product: `unitCost × qty × conversion_factor`.
  - Recipe or composite: `Σ unitCost(component) × recipe qty × qty`.
  - The result is stored in `transaction_details.cost_total`. Line profit = net line price −
    `cost_total` (the existing manual-discount allocation stays).
- **Sales return** (web and V1, which currently copy the same block): profit reversal =
  (unit sell price − `cost_total ÷ qty`) × returned qty. Restock is unchanged; the recipe restock
  fix is in a later item.
- **Insights:** the four `p.buy_price * td.qty` expressions become `td.cost_total`. This also fixes
  the ignored `conversion_factor` and the wrong recipe HPP.
- **Backfill of existing lines** (PHP, chunked, SQLite-safe):
  - Plain product: `products.buy_price × qty × conversion_factor`.
  - Composite: `Σ component.buy_price × recipe qty × qty`.
  - This is an estimate, close to what Insights showed before, so old reports do not jump.

### 6.7 Recipes

- Component rows show the material's stock-unit symbol ("18 g").
- The component picker marks raw materials and lists them first.
- The cost estimate on the form is labelled "estimasi (harga referensi)".
- Everything REQ-001 delivered stays as is.

### 6.8 Existing purchasing bugs fixed here

- **a) Supplier returns lose data.** The rules list neither `warehouse_id` nor
  `items.*.goods_receiving_item_id`, so Laravel drops both (verified). A return without a linked
  receiving does not reduce warehouse stock, and item links are lost.
  - Add both rules on web and V1.
  - `warehouse_id` is access-checked. When missing, it is derived from the receiving as today.
  - `payable_id`, when missing and a receiving is given, is derived from the payable of the
    receiving's PO. Otherwise a return from a receiving never reduces what is owed, because the
    page does not fill `payable_id`.
- **b) The payable is overwritten on each partial receipt.** `createOrUpdatePayable` rewrites
  `total` from the latest receipt only and resets `paid = 0`.
  - New rule: total = Σ(received qty × PO item price) over **all** receipts of the PO, minus
    completed supplier returns linked to that payable.
  - `paid` is kept. Status becomes unpaid, partial or paid (paid when `paid ≥ total`).
- **c) The Supplier Return page never loads receivings.** Data is only sent when `?supplier_id=` is
  in the URL, and choosing a supplier does not reload. Choosing a supplier must reload that
  supplier's receivings (Inertia partial reload).

## 7. Tests (minimum)

- **Units and raw materials:**
  - Create a material with stock unit g and purchase unit kg (default); the reference price is right.
  - Only one default purchase unit is allowed.
  - Deleting a recipe component is rejected; a material with only opening stock deletes.
- **PO:**
  - `unit_id` (kg) stores the server-side conversion.
  - No `unit_id` behaves as before, and existing tests stay green.
  - Another product's unit is rejected.
- **Goods receiving:**
  - 2 kg adds 2,000 to warehouse stock, the mutation and the batch.
  - Average cost: 4,950 @250 + 5,000 g @280,000/kg ≈ 265.08.
  - The unit price and reference price update.
  - A second partial receipt neither overwrites the payable nor resets `paid`.
- **Supplier return:**
  - The unit is inherited and stock drops by qty × conversion.
  - `warehouse_id` and `goods_receiving_item_id` are stored.
  - Over-return is rejected.
  - The payable drops by qty × price.
  - The page reloads receivings for the chosen supplier.
- **Transfer:** the source cost merges into the destination, and cancel returns it at the same cost.
- **Sales:**
  - `cost_total` uses the selling warehouse's `avg_cost` for plain, multi-unit and recipe lines.
  - The API, offline sync and dine-in paths set it too.
  - Old profit is unchanged after a new receipt.
- **Sales return:** the reversal uses `cost_total` (web and V1).
- **Insights:** product profit = price − `cost_total`.
- **Reorder:**
  - Central versus outlet scoping works.
  - A need of 2,300 g becomes a PO of 3 kg at the kg price.
- **Migrations:** the `avg_cost`, `cost_total` and non-base unit price backfills run on SQLite.
- **Whole build:** the whole suite, Pint on changed files, and the frontend tests and build.

## 8. Left to the builder

- Class, method, component and file names. Centralising the copied web and V1 validation is allowed
  when it lowers risk.
- Raw Materials page layout, how stock-unit equivalents are shown, and message text (Indonesian,
  matching nearby pages).
- How issues are sliced and ordered, within §9.

## 9. Delivery

The item ships as thin issues, each aiming under about 300 counted lines, with its own migrations
and tests. Order and dependencies:

| # | Issue | REQ-002 criteria | Needs |
|---|---|---|---|
| 1 | Units and purchase units: Gram and ml, `is_purchase_default`, the non-base unit price backfill, and buy price + default purchase unit on the Products form | AC-01, AC-17 | — |
| 2 | Raw Materials screen: `purchased_by`, list, lean form with opening stock, delete guard, sidebar | AC-02, AC-03, AC-20, AC-21 | 1 |
| 3 | PO and goods receiving in purchase units (web, V1, pages) | AC-04, AC-05, AC-19 | 1 |
| 4 | Average cost per warehouse: `avg_cost`, its backfill, inbound on receiving and opening stock, and the latest-price update | AC-06, AC-07, AC-21 | 3 |
| 5 | Payable recomputed from all receipts minus completed returns | AC-08 | 3 |
| 6 | Supplier returns in purchase units, the dropped-field fixes, the over-return guard, and the page reload | AC-09, AC-10, AC-11 | 3, 5 |
| 7 | HPP frozen per sale line: `cost_total` + backfill, set by checkout and dine-in from the warehouse average | AC-13, AC-14 | 4 |
| 8 | Sales return reversal and Insights read `cost_total` | AC-14, AC-15 | 7 |
| 9 | Stock transfer carries cost (send, receive, cancel) | AC-12 | 4 |
| 10 | Reorder scoped by purchased-by and converted to the purchase unit | AC-16 | 2 |
| 11 | Recipe form shows component units and lists raw materials first | AC-18 | 1 |

Issue 4 lists AC-21 because opening stock sets the warehouse average. Issue 2 delivers the opening
stock quantity, and issue 4 adds its cost.

## 10. Risks

- **Many stock write points.** `avg_cost` must update on every costed inbound path. A missed path
  falls back to `products.buy_price`, which is safe, but tests must cover each path.
- **Copied web and V1 logic** can drift. API tests are required for PO, receiving and returns with
  units.
- **The `cost_total` backfill is an estimate** for old transactions, which this design documents.

## 11. Noted, out of scope

- Expired gateway transactions (`transactions:expire`) restock but do not reverse profit rows.
  Check whether reports filter by payment status.
- `product_warehouse.min_stock` and `max_stock` exist but are never used (relevant to distribution).
- Profit report revenue is not reduced by sales returns.
