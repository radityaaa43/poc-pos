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
(empat query). Grup urut sesuai menu, opsi sesuai grup: `product_id => {orderable, groups: [{id, name, is_required,
min_select, max_select, options: [{id, name, price_delta, is_default, components: [{product_id, qty}]}]}]}`. Opsi
terhapus atau mati di outlet itu tidak ada; grup tanpa sisa opsi tetap ada. `orderable: false` bila opsi aktif suatu
grup kurang dari minimumnya (pemanggil memblokir menu). Stok tidak diperiksa. `components` internal: jangan ke tamu.

## Penjualan di POS (online)

- Menu bergrup membuka panel: default terpilih, harga porsi = menu + opsi, catatan maks. 100 karakter. Opsi yang
  bahannya kurang di gudang shift berlabel **Habis**; menu juga bila grup wajib tak bisa mencapai minimumnya.
- Server (`ModifierSelection`) memvalidasi dan menghargai pilihan saat masuk keranjang dan saat checkout; harga client
  diabaikan; penolakan menyebut menu dan opsi atau grupnya. Baris digabung hanya bila opsi dan catatan sama.
- Diskon, voucher, pajak dan poin atas baris penuh; harga tetap, qty break (menghitung semua baris menu yang sama,
  satuan non-dasar tidak), bundle dan "gratis" beli X gratis Y hanya mengenai harga menu.
- Checkout menyimpan nama, harga dan resep opsi (`transaction_detail_modifiers`), `note` dan `modifier_total`; struk
  membaca salinan ini. Bahan opsi diambil satu transaksi dengan stok menu (`ComponentStockService`, urut `product_id`).
- Bahan opsi kembali saat pembayaran kedaluwarsa atau tutup shift, **belum** saat retur. Baris beropsi tak bisa
  diantre offline; baris tanpa pilihan (API mobile, sinkron offline) hanya diperiksa BR-6.

## Deploy

Migrasi, lalu `db:seed --class=PermissionSeeder` dan `--class=RoleSeeder`. `modifiers-manage` diberikan ke role
ber-`products-edit` (role buatan toko hanya pertama kali, jadi izin yang dicabut tidak kembali); login ulang.
