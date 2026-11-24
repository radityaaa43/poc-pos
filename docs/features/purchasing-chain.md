# Purchasing Chain

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Modul pembelian formal dari supplier: Purchase Order, Goods Receiving, dan Supplier Return. Melengkapi siklus inventory dari pembelian sampai stok masuk.

## Fitur Saat Ini

### Purchase Order (PO)
- Buat PO dengan pilih supplier + item produk
- Qty dan harga beli per item
- Satuan beli per item (default: satuan beli utama produk, else satuan dasar); qty dan harga per satuan itu. Faktor konversi dibaca server dari `product_units` dan disimpan di item (`unit_id`, `conversion_factor`); tampil ekuivalen satuan stok ("5 kg = 5.000 g")
- Auto-generate nomor dokumen (`PO-YYYYmmdd-XXXX`)
- Draft PO otomatis (`reorder:generate`): per gudang, bahan baku hanya yang `purchased_by`-nya cocok (gudang pusat = outlet `is_sales_enabled` false → `central`; lainnya → `outlet`); produk non-bahan baku tidak berubah. Kebutuhan (max - stok) dikonversi ke satuan beli default, dibulatkan ke atas, dengan harga satuan beli itu (kosong → `buy_price` produk × faktor); tanpa satuan beli tetap satuan stok
- Status lifecycle: `draft` → `ordered` → `partial_received` / `completed` → `cancelled`
- Filter by status, supplier, nomor dokumen

### Goods Receiving (GR)
- Terima barang dari PO (full atau partial)
- Qty diterima per item, catatan selisih
- Auto-update status PO (partial/completed)
- Input **batch number** + **expired date** per item (untuk batch tracking)
- Increment stok di warehouse tujuan (dari PO)
- Qty diterima dalam satuan item PO; stok, batch, dan mutasi masuk dalam satuan stok (qty x faktor). Ditolak jika hasilnya bukan bilangan bulat
- Auto-create payable (30 days due date) pada GR pertama; GR berikutnya menghitung ulang `total` = Σ(qty diterima x harga item PO) semua GR PO itu − retur supplier `completed` pada payable tersebut. `paid` dan `due_date` dipertahankan, status dihitung ulang (paid ≥ total → `paid`)

### Supplier Return
- Retur barang ke supplier dari GR
- Status lifecycle: `draft` → `completed` / `cancelled`
- Koreksi stok (decrement di warehouse asal)
- Koreksi payable jika ada
- Alasan retur per item

## Halaman dan Route

| Halaman | Route | Method |
|---------|-------|--------|
| Daftar PO | `purchase-orders.index` | GET |
| Buat PO | `purchase-orders.create` | GET |
| Simpan PO | `purchase-orders.store` | POST |
| Detail PO | `purchase-orders.show` | GET |
| Place PO | `purchase-orders.place` | POST |
| Cancel PO | `purchase-orders.cancel` | POST |
| Daftar GR | `goods-receivings.index` | GET |
| Buat GR | `goods-receivings.create` | GET |
| Simpan GR | `goods-receivings.store` | POST |
| Detail GR | `goods-receivings.show` | GET |
| Daftar SR | `supplier-returns.index` | GET |
| Buat SR | `supplier-returns.create` | GET |
| Simpan SR | `supplier-returns.store` | POST |
| Detail SR | `supplier-returns.show` | GET |
| Complete SR | `supplier-returns.complete` | POST |
| Cancel SR | `supplier-returns.cancel` | POST |

## Permission

| Permission | Untuk apa |
|-----------|-----------|
| `purchase-orders-access` | Lihat daftar & detail PO |
| `purchase-orders-create` | Buat PO |
| `purchase-orders-update` | Place/cancel PO |
| `goods-receivings-access` | Lihat daftar & detail GR |
| `goods-receivings-create` | Buat GR |
| `supplier-returns-access` | Lihat daftar & detail SR |
| `supplier-returns-create` | Buat SR |
| `supplier-returns-update` | Complete/cancel SR |

## Alur User

1. **Buat PO** → pilih supplier, tambah item, simpan draft
2. **Place PO** → ubah status draft jadi ordered
3. **Goods Receiving** → pilih PO yang ordered, input qty diterima + batch, simpan
4. **Supplier Return** (jika perlu) → pilih GR, pilih item retur, complete
5. **Payable** otomatis terbentuk saat GR — selesaikan di modul Payables

## Integrasi Data

- `purchase_orders` → `purchase_order_items` → di-GR, qty_received increment
- `goods_receivings` → `goods_receiving_items` → increment stok + stock mutation
- `supplier_returns` → `supplier_return_items` → decrement stok + stock mutation
- `payables` auto-created dari GR dengan due_date = 30 hari
- `product_batches` auto-created dari GR jika batch_number diisi
- `warehouse_id` di PO → diturunkan ke GR
- Audit log untuk setiap transisi status
