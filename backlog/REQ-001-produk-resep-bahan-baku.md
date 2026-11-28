# REQ-001: Produk resep dan penanda bahan baku

- **Status:** done
- **Owner:** @radityaaa43
- **Module:** module:inventory
- **Updated:** 2026-11-25
- **Renamed:** in #9 (the former ID is recorded there; closed issue #7 and PR #8 use it)

## User story

Sebagai pemilik kedai, saya ingin menjual menu minuman (mis. Americano) yang tersusun dari bahan
baku (biji kopi, air, gelas), sehingga setiap penjualan otomatis mengurangi stok bahan dan HPP-nya
terhitung, sementara bahan baku itu sendiri tidak muncul di kasir.

## Context

Produk komposit (`products.is_composite`, `composite_product_items`) sudah ada. Item ini menambahkan
penanda bahan baku (`products.is_raw_material`) dan memakai resep sebagai produk jual: harga jual
memakai harga menu, stok tersedia dihitung dari bahan yang paling terbatas, dan penjualan mengurangi
stok bahan. Dasar desain dan keputusan: `ARCHITECTURE.md` bagian 7.5a. Pembelian bahan baku per
satuan supplier dan HPP rata-rata per gudang adalah item terpisah (belum dibuat).

## Acceptance criteria

- **AC-01:** Given resep dengan harga menu dan bahan di gudang kasir, when kasir menjual 2 porsi
  lalu membayar, then total memakai harga menu, stok tiap bahan berkurang sesuai jumlah resep
  dikali porsi, dan laba = penjualan dikurangi HPP bahan.
- **AC-02:** Given produk komposit tanpa harga jual sendiri, when harganya dibaca, then harga
  jualnya sama dengan total harga komponen (dikali jumlah per komponen).
- **AC-03:** Given resep dan produk biasa, when kasir membuka daftar produk POS, then resep tampil
  dengan stok = jumlah porsi yang bisa dibuat dari bahan yang tersedia, produk biasa tampil dengan
  stoknya, dan bahan baku tidak tampil.
- **AC-04:** Given salah satu bahan resep kurang dari jumlah untuk satu porsi, when daftar produk
  POS dibaca, then resep itu tidak tampil.
- **AC-05:** Given produk bahan baku, when ditambahkan ke keranjang atau saat checkout
  keranjang yang sudah berisi bahan baku, then ditolak, di POS web maupun API POS.
- **AC-06:** Given resep di keranjang, when jumlahnya diubah, then keranjang memperbarui jumlah dan
  total.
- **AC-07:** Given menu dine-in, when halaman menu dibuka, then resep tampil dan bahan baku tidak.
- **AC-08:** Given form produk, when penanda bahan baku diisi dan produk disimpan, then produk
  tersimpan dengan penanda bahan baku aktif.

## Out of scope

- Pembelian bahan baku dengan satuan supplier (kg, liter) dan konversi satuan.
- HPP rata-rata per gudang, batch dan tanggal kedaluwarsa, distribusi pusat ke cabang, pembuangan
  bahan. Desainnya masih draf dan akan menjadi item backlog berikutnya.

## Open questions

- Resep yang dijual dari gudang tanpa stok bahan: saat ini tidak tampil (AC-04); belum ada
  pesan khusus ke kasir. — **Answered** (Business/PM, 2026-11-28): menu tampil **Habis** dan tidak
  bisa ditambahkan, sama seperti opsi REQ-008 (#512).

## Delivered by

- Issue: #7 · PR: #8 (semua AC; dibangun sebelum proses backlog).
- Bukti e2e per AC (`tests/e2e/features/`): AC-01, AC-06 `42-recipes/03` · AC-02 `42-recipes/01` ·
  AC-03, AC-04 `42-recipes/02` · AC-05 `42-recipes/04` (#246) · AC-07 `42-recipes/05` · AC-08
  `43-raw-materials/03` (#186).
- Ditutup oleh #269 (Business/PM, didelegasikan pemilik, 2026-11-25).
