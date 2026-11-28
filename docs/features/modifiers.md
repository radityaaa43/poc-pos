# Modifier (REQ-008)

Pilihan per menu (Size, Level gula, Topping) tanpa satu produk per variasi: **pengaturan** (J1) dan penjualan
online di POS (J2); retur stok opsi, QR menu, dapur dan laporan menyusul. Menu tanpa grup terjual seperti sebelumnya.

- **Settings → Modifier** (`/dashboard/settings/modifiers`, `modifiers-manage`): tambah, ubah, hapus grup.
- **Grup**: wajib atau opsional. Ditolak: minimum > maksimum, maksimum di luar 1–50, grup wajib dengan minimum 0,
  minimum > jumlah opsi.
- **Opsi** (1–50): nama unik dalam grup, tambahan harga bulat >= 0 (sama di semua outlet), default (maksimal satu;
  grup wajib tepat satu), resep opsional (produk ber-stok, bukan resep; bulat >= 1 dalam satuan stok).
- **Per outlet**: saklar per opsi; hanya yang dimatikan disimpan, jadi outlet baru otomatis aktif.
- **Pasang ke menu**: bagian Modifier di form produk, diurutkan dengan tombol naik/turun. Bahan baku tidak bisa, dan
  produk yang dijadikan bahan baku kehilangan pasangannya. Tanpa `modifiers-manage` bagian ini tersembunyi dan request
  berisi `modifier_group_ids` ditolak (403).
- Bahan pada opsi yang masih ada tidak bisa dihapus atau dijadikan komposit; pesannya menyebut opsi dan grupnya.
- **Hapus**: grup dan opsi di-soft-delete, grup dilepas dari menu, penjualan lama tidak berubah. Resep opsi terhapus
  disimpan untuk retur; bahan yang kemudian dihapus keluar dari resep itu.
- **Audit** (modul `modifiers`): `modifier_group.created/.updated/.deleted` dengan nilai lama dan baru (juga opsi, resep,
  outlet mati); pemasangan ke menu ada di `product.created` / `product.updated` (`modifier_group_ids`).

## Kontrak untuk penjualan

`ModifierCatalog::forOutlet(?Outlet, $productIds)` satu-satunya jalur baca POS, checkout, cache offline dan QR menu
(empat query). Grup urut sesuai menu, opsi sesuai grup: `product_id => [{id, name, is_required, min_select,
max_select, options: [{id, name, price_delta, is_default, components: [{product_id, qty}]}]}]`. Opsi terhapus atau mati
di outlet itu tidak ada; grup tanpa sisa opsi tetap ada (pemanggil memblokir menu bila wajib). Stok tidak diperiksa.
`components` internal: jangan ke tamu.

## Penjualan di POS (online)

- Menu bergrup membuka panel: default terpilih, harga porsi = menu + opsi, catatan maks. 100 karakter (tidak mengubah
  harga atau stok). Opsi yang bahannya kurang untuk satu porsi di gudang shift berlabel **Habis**.
- Menu **Habis** dan ditolak server bila grup wajib punya opsi tersedia (aktif di outlet, bahan cukup) kurang dari
  minimumnya.
- Pilihan divalidasi dan dihargai server (`ModifierSelection`) saat masuk keranjang dan lagi saat checkout; harga dari
  client diabaikan. Opsi yang sejak itu dihapus, dimatikan atau habis menolak checkout (menyebut menu dan opsi).
- Baris keranjang digabung hanya bila opsi dan catatannya sama (`carts.line_key`).
- Diskon persen/nominal, voucher, pajak dan poin atas baris penuh; harga tetap, qty break dan bundle hanya mengganti
  harga menu; "gratis" pada beli X gratis Y hanya harga menu.
- Checkout menyimpan opsi per baris (`transaction_detail_modifiers`: nama, harga, `components` =
  `[{product_id, qty_per_unit}]`) serta `note` dan `modifier_total`; struk membaca salinan ini. Bahan opsi diambil dalam
  transaksi yang sama dengan stok menu (`ComponentStockService`, kunci urut `product_id`).
- Pembayaran kedaluwarsa mengembalikan bahan sesuai `components`, bukan resep sekarang; bahan terhapus dilewati.
- Belum: retur penjualan **tidak** mengembalikan bahan opsi; baris beropsi atau bercatatan tidak bisa diantre offline;
  API mobile tidak memeriksa pilihan; layar dapur dan tiket belum menampilkannya (dirilis bersama #419).

## Deploy

Migrasi, lalu `db:seed --class=PermissionSeeder` dan `--class=RoleSeeder`. `modifiers-manage` diberikan ke role
ber-`products-edit` (role buatan toko hanya pertama kali, jadi izin yang dicabut tidak kembali); login ulang.
