# Modifier (REQ-008)

Satu menu dengan pilihan (Size, Level gula, Topping) tanpa membuat satu produk per variasi. Versi ini baru
**pengaturan** (journey J1): memilih opsi saat menjual, stok opsi, QR menu, dapur dan laporan datang di tiket
REQ-008 berikutnya. Menu tanpa grup terjual seperti sebelumnya.

## Mengatur

- **Settings → Modifier** (`/dashboard/settings/modifiers`, permission `modifiers-manage`): daftar grup, **Tambah
  grup**, ubah, hapus.
- **Grup**: nama, **Wajib** atau **Opsional**, minimum dan maksimum pilihan. Ditolak: minimum > maksimum, maksimum
  < 1 (batas atas 50), grup wajib dengan minimum 0.
- **Opsi** (1–50 per grup): nama (unik dalam grup), tambahan harga bulat >= 0 (sama di semua outlet), penanda
  default (maksimal satu; grup wajib harus punya tepat satu), resep opsional (produk ber-stok, bukan resep;
  jumlah bulat >= 1 dalam satuan stok bahan).
- **Per outlet**: saklar per opsi, default aktif. Yang disimpan hanya yang dimatikan, jadi outlet baru otomatis
  aktif.
- **Pasang ke menu**: bagian **Modifier** di form produk (Tambah/Edit), urutan diatur dengan tombol naik/turun.
  Bahan baku tidak bisa diberi modifier, dan produk yang dijadikan bahan baku (dari layar atau API mana pun)
  kehilangan pasangannya. Tanpa `modifiers-manage` bagian ini tersembunyi dan server mengabaikan
  `modifier_group_ids`.
- **Hapus**: grup dan opsi di-soft-delete (opsi yang hilang saat grup disimpan juga), grup dilepas dari semua menu
  dan resep opsi terhapus dilepas (tercatat di audit), jadi bahannya bisa dihapus; penjualan lama tidak berubah.
- **Audit log** (modul `modifiers`): `modifier_group.created`, `.updated`, `.deleted` dengan nilai lama dan baru,
  termasuk opsi, resep dan outlet yang dimatikan. Pemasangan ke menu ikut di `product.created` / `product.updated`
  (`modifier_group_ids`).

## Kontrak untuk penjualan

`App\Support\Modifiers\ModifierCatalog::forOutlet(?Outlet $outlet, Collection $productIds)` adalah satu-satunya
jalur baca untuk POS, checkout, cache offline dan QR menu (empat query berapa pun jumlah produknya):

```
product_id => [ { id, name, is_required, min_select, max_select,
                  options: [ { id, name, price_delta, is_default, components: [ { product_id, qty } ] } ] } ]
```

Grup urut sesuai menu, opsi sesuai grup; opsi terhapus atau dimatikan di outlet itu tidak ada. Grup yang semua
opsinya mati tetap ada dengan `options` kosong (pemanggil memblokir menu bila grup itu wajib). Ketersediaan stok
**tidak** diperiksa di sini. `components` adalah resep internal: jangan kirim ke tamu.

## Data

`modifier_groups`, `modifier_options` (keduanya soft delete), `modifier_option_components` (resep opsi),
`product_modifier_group` (urutan di `sort`), `modifier_option_outlet` (baris = dimatikan). Tidak ada backfill.

## Deploy

Jalankan migrasi, lalu `php artisan db:seed --class=PermissionSeeder` dan `--class=RoleSeeder`. `modifiers-manage`
diberikan ke role yang punya `products-edit`; role buatan toko hanya pada pemberian pertama, jadi menjalankan ulang
seeder tidak mengembalikan izin yang sudah dicabut pemilik (role bawaan disinkron ulang seperti biasa). Pengguna yang
sedang login melihat menunya setelah login ulang.
