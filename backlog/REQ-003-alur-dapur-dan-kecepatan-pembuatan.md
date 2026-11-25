# REQ-003: Alur dapur untuk pesanan POS, layar dapur, dan kecepatan pembuatan menu

- **Status:** draft
- **Owner:** @radityaaa43
- **Module:** module:sales
- **Updated:** 2026-11-25

## User story

Sebagai staf dapur, saya ingin melihat pesanan yang harus dibuat dan menandainya sedang dibuat
lalu siap, agar pesanan tidak terlewat dan kasir tahu kapan pesanan bisa diserahkan.

Sebagai kasir atau pelayan, saya ingin melihat pesanan yang sudah siap dan menandainya diserahkan
ke pembeli, agar pesanan selesai tercatat.

Sebagai pemilik usaha, saya ingin melihat berapa lama dapur membuat setiap menu, agar saya bisa
menilai kecepatan dapur dan menemukan menu atau jam yang lambat.

## Context

#128 memberi pesanan dine-in dua siklus terpisah (keputusan pemilik, 2026-11-25):

- **Status pesanan (penyajian):** submitted → accepted → preparing → ready → served → completed.
- **Status bayar:** unpaid → paid, bisa sebelum atau sesudah disajikan.

Keputusan pemilik (2026-11-25): **semua jalur pesanan (dine-in QR, POS di tempat, bawa pulang,
diantar) memakai alur yang sama, dan pembayaran harus lunas dulu baru pesanan diproses.**
Pembayaran boleh lewat semua channel (tunai, transfer yang sudah dikonfirmasi, QRIS, Midtrans,
Xendit). Transfer atau pembayaran gateway yang masih menunggu belum dihitung lunas. Pesanan selesai
(`completed`) saat diserahkan, karena sudah pasti lunas. Setiap perpindahan status
mencatat waktu dan pelakunya. Siapa yang boleh melakukan setiap langkah diatur per toko lewat izin
(`dine-orders-prepare`, `dine-orders-handover`), bukan dikunci ke satu peran.

Penjualan dari POS (bawa pulang atau makan di tempat tanpa QR) belum punya alur ini: transaksi
langsung selesai, dapur tidak melihat apa pun, dan tidak ada data berapa lama pembuatannya.

## Acceptance criteria

- **AC-01:** Given penjualan POS berisi menu yang perlu dibuat, when transaksinya lunas (langsung
  untuk tunai, setelah konfirmasi transfer atau webhook QRIS/gateway), then pesanan muncul di
  layar dapur dengan status *accepted*; sebelum lunas pesanan tidak muncul di dapur.
- **AC-02:** Given pesanan di layar dapur, when staf dapur menekan *Mulai Buat* lalu *Siap*, then
  status berubah menjadi *preparing* lalu *ready*, dan waktu serta pelakunya tercatat.
- **AC-03:** Given pesanan *ready*, when kasir atau peran lain yang berizin menekan *Serahkan*,
  then status menjadi *served* lalu *completed*.
- **AC-04:** Given pesanan dine-in (#128) dan pesanan POS, when staf dapur membuka layar dapur,
  then keduanya tampil dalam satu antrean, urut dari yang paling lama menunggu, dengan nomor meja
  atau nama/nomor pesanan.
- **AC-05:** Given layar dapur terbuka, when pesanan baru masuk atau status berubah, then layar
  diperbarui tanpa dimuat ulang manual.
- **AC-06:** Given pesanan yang sudah selesai, when pemilik membuka laporan kecepatan dapur, then
  terlihat waktu rata-rata dan median dari *accepted* ke *ready* (waktu tunggu + pembuatan) dan
  dari *preparing* ke *ready* (waktu pembuatan) per menu, per outlet, dan per rentang waktu.
- **AC-07:** Given izin `dine-orders-prepare` dan `dine-orders-handover` (atau penggantinya untuk
  semua pesanan), when pemilik mengatur peran, then setiap toko bebas menentukan peran mana yang
  boleh memasak dan menyerahkan.

## Out of scope

- Printer dapur (kitchen ticket printer).
- Mengubah resep atau stok: stok tetap dipotong saat penjualan.

## Open questions

- **Q1:** Apakah semua penjualan POS masuk dapur, atau hanya produk yang ditandai "perlu dibuat"
  (misalnya kopi dan makanan masuk, air mineral dan snack kemasan langsung selesai)?
  Usulan: hanya produk yang ditandai.
- **Q2 (terjawab 2026-11-25):** ya, semua jalur bayar dulu baru diproses. **Q2b:** penjualan
  *Bayar Belakangan* (piutang) tidak pernah lunas di awal: apakah langsung diproses (penjualan
  kredit) atau tidak boleh untuk menu yang perlu dibuat? Usulan: langsung diproses.
- **Q3:** Kecepatan per menu dihitung per pesanan (satu pesanan berisi beberapa menu dihitung
  bersama) atau per item (dapur menandai setiap menu siap sendiri-sendiri)? Per item lebih akurat
  untuk analisis menu, tetapi menambah klik di dapur. Usulan: per item.

## Delivered by

<!-- Issues and PRs, filled in as work ships. -->
