# Inventory & Stock

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Menjaga akurasi stok melalui master produk, stock opname, dan histori mutasi stok.

## Fitur Saat Ini

- CRUD produk
- initial stock saat create product
- stock tidak bisa diubah langsung dari edit product
- stock opname draft → finalized
- stock mutation list
- low stock notification
- multi-warehouse stock melalui `product_warehouse`
- batch/expiry tracking dan alokasi FEFO
- composite products / kits
- reorder point dan rekomendasi purchase order
- waste bahan baku dan menu resep (REQ-011)

## Halaman dan Route

- `dashboard/products`
- `dashboard/stock-opnames`
- `dashboard/stock-mutations`
- `dashboard/stock-waste`, `dashboard/stock-waste/create`

## Permission

- `products-access`, `products-create`, `products-edit`, `products-delete`
- `stock-opnames-access`, `stock-opnames-create`, `stock-opnames-finalize`
- `stock-mutations-access`
- `stock-waste-create`, `stock-waste-approve` (diberikan ke `manager` dan `super-admin` oleh seeder)

## Alur User

1. produk dibuat dengan initial stock
2. initial stock menghasilkan stock mutation awal
3. stock opname dibuat sebagai draft
4. produk ditambahkan ke sesi opname
5. stok fisik diisi per item
6. finalize mengubah stok produk dan membuat stock mutation adjustment

## Waste

- Hanya bahan baku dan menu resep. Menu memotong bahan resepnya: jumlah resep x jumlah menu, dibulatkan ke satuan stok (sama seperti penjualan); bahan yang jadi 0 dilewati.
- Nilai = HPP rata-rata saat stok dipotong x jumlah. Stok yang tidak cukup ditolak.
- Sampai batas outlet (`settings.waste_approval_limit`, default Rp 100.000) status *Tercatat* dan `product_warehouse.stock` serta `products.stock` dipotong dalam satu transaksi (mutasi `stock_waste`). Di atas batas status *Menunggu persetujuan* dan stok tidak berubah; belum ada layar persetujuan (#459).
- Produk, gudang, atau outlet yang punya catatan waste tidak bisa dihapus.
- Setelah deploy: `php artisan migrate`, lalu `db:seed --class=PermissionSeeder` dan `--class=RoleSeeder`.

## Integrasi Data

- `products`
- `stock_opnames`
- `stock_opname_items`
- `stock_mutations`
- `product_notification_reads`
- `stock_wastes`, `stock_waste_lines`, `stock_waste_moves`

## Efek Bisnis Penting

- edit product tidak lagi menjadi jalur mutasi stok
- sales return dan stock opname dapat menambah stok kembali
- histori mutasi adalah audit trail inventory utama

## Batasan Saat Ini

- `products.stock` tetap dipelihara sebagai aggregate compatibility; stok operasional per gudang berada di `product_warehouse`
- data historis tertentu dapat memiliki `warehouse_id` nullable
- tidak semua sumber mutasi lama direpresentasikan dengan struktur pivot yang sama

## File Sentral

- `app/Http/Controllers/Apps/ProductController.php`
- `app/Http/Controllers/Apps/StockOpnameController.php`
- `app/Http/Controllers/Apps/StockMutationController.php`
- `app/Services/StockMutationService.php`
- `app/Services/StockWasteService.php`

## Selisih Opname per Bulan

`StockOpnameVarianceService` menampilkan, untuk outlet aktif, 12 bulan terakhir rata-rata nilai selisih absolut dan rata-rata item selisih dari stock opname final, dengan penanda bulan mulai pencatatan waste. Nilai memakai biaya per unit yang disimpan saat opname difinalisasi; opname lama tanpa biaya tersimpan memakai HPP rata-rata hari ini dan ditandai estimasi.
