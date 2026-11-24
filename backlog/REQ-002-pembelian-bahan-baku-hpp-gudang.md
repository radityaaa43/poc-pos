# REQ-002: Pembelian bahan baku dengan satuan beli dan HPP per gudang

- **Status:** ready
- **Owner:** @radityaaa43
- **Module:** module:purchasing
- **Updated:** 2026-11-24

## User story

Sebagai pemilik usaha dengan beberapa outlet, saya ingin bahan baku dibeli dari supplier dalam
satuan beli (kg, liter, pak) dan stoknya otomatis tercatat dalam satuan resep (gram, ml, pcs),
sehingga HPP setiap menu dan laba setiap outlet mengikuti harga beli yang sebenarnya.

Sebagai staf gudang pusat atau manajer outlet, saya ingin membuat PO, menerima barang, dan meretur
ke supplier dalam satuan beli, sehingga saya tidak perlu menghitung sendiri berapa gram dalam 5 kg.

## Context

REQ-001 sudah memungkinkan menu resep dijual dari bahan baku. Yang belum ada:

- **Satuan beli.** PO, penerimaan barang, dan retur supplier hanya mengenal satuan stok. Membeli
  5 kg kopi harus diketik 5000.
- **HPP dari pembelian.** Harga beli diisi manual di form produk dan tidak pernah berubah saat
  barang diterima. Satu angka berlaku untuk semua gudang, padahal pembelian dilakukan campuran:
  gudang pusat membeli bahan utama (kopi, susu), outlet membeli bahan kecil (es, lemon), dan
  harganya bisa berbeda.
- **HPP yang dibekukan.** Laporan Insights menghitung ulang laba dari harga beli saat ini, sehingga
  laba bulan lalu ikut berubah ketika harga beli berubah.

Keputusan yang sudah diambil pemilik (diskusi 2026-11-20 sampai 2026-11-24):

- Harga diketik di setiap dokumen pembelian. HPP dihitung otomatis dengan metode rata-rata
  tertimbang **per gudang**.
- Harga transfer antar gudang sama dengan harga pokok, tanpa markup.
- Qty dan takaran tetap bilangan bulat dalam satuan terkecil.
- Setiap bahan baku punya penanda **dibeli oleh pusat atau outlet**, dipakai draft PO otomatis.
- Bug lama di rantai pembelian yang merusak data utang dan retur ikut diperbaiki.

Desain teknis: `docs/superpowers/specs/2026-11-24-raw-material-purchasing-cost-design.md`.
Contoh data dalam kriteria di bawah memakai sebuah kedai kopi sebagai ilustrasi.

## Acceptance criteria

- **AC-01:** Given master satuan, when daftar satuan dibuka, then satuan Gram (g) dan Mililiter
  (ml) tersedia.
- **AC-02:** Given menu Bahan Baku, when staf membuat bahan "Biji kopi" dengan satuan stok gram,
  satuan beli kg (isi 1000, Rp 250.000 per kg, default), stok minimum 2500 g, dan dibeli oleh
  pusat, tanpa mengisi barcode dan SKU, then bahan tersimpan sebagai bahan baku dengan barcode/SKU
  otomatis, harga referensi Rp 250 per gram, dan tidak tampil di kasir.
- **AC-03:** Given bahan baku yang dipakai di resep atau sudah pernah dibeli, dijual, ditransfer,
  atau di-opname, when bahan itu dihapus, then penghapusan ditolak dengan pesan yang menyebut
  alasannya. Bahan yang hanya punya stok awal boleh dihapus.
- **AC-04:** Given PO untuk bahan dengan satuan beli kg, when staf menambah item, then satuan
  default kg, harga default Rp 250.000, qty dan harga diisi per kg, dan setara satuan stok tampil
  (5 kg = 5.000 g). Konversi diambil dari data produk, bukan dari isian formulir.
- **AC-05:** Given PO 5 kg yang sudah dipesan ke gudang tertentu, when staf menerima 2 kg, then
  stok gudang itu dan stok global bertambah 2.000 g, mutasi dan batch tercatat 2.000 g, dan sisa PO
  tampil 3 kg.
- **AC-06:** Given gudang dengan stok 4.950 g kopi berharga rata-rata Rp 250 per gram, when
  diterima 5 kg seharga Rp 280.000 per kg, then HPP rata-rata kopi di gudang itu menjadi sekitar
  Rp 265,08 per gram, dan gudang lain tidak berubah.
- **AC-07:** Given penerimaan dengan harga baru, when penerimaan disimpan, then harga satuan beli
  itu dan harga referensi produk diperbarui ke harga terakhir.
- **AC-08:** Given PO yang diterima dua kali dan sudah dibayar sebagian, when penerimaan kedua
  disimpan, then total utang = semua penerimaan PO itu dikurangi retur supplier yang sudah
  selesai, pembayaran yang ada tidak hilang, dan statusnya dihitung ulang.
- **AC-09:** Given halaman Retur Supplier, when supplier dipilih, then daftar penerimaan barang
  supplier itu tampil tanpa mengetik alamat halaman secara manual.
- **AC-10:** Given retur supplier dari item penerimaan 2 kg, when retur 1 kg diselesaikan, then
  satuannya mengikuti item penerimaan, stok gudang berkurang 1.000 g, gudang dan tautan item
  penerimaan tersimpan, utang berkurang 1 × harga per kg, dan HPP rata-rata tidak berubah.
- **AC-11:** Given item penerimaan yang sebagian sudah diretur, when retur baru melebihi sisa yang
  belum diretur, then retur ditolak.
- **AC-12:** Given transfer stok dari gudang pusat (rata-rata Rp 265 per gram) ke gudang outlet,
  when transfer diterima, then HPP rata-rata outlet digabung memakai harga pokok pusat saat
  pengiriman. Bila transfer yang sedang dalam perjalanan dibatalkan, stok kembali ke gudang asal
  dengan harga yang sama.
- **AC-13:** Given penjualan menu resep atau produk biasa di sebuah outlet, when transaksi selesai
  lewat kasir web, API POS, sinkronisasi offline, atau pesanan dine-in yang diterima, then HPP baris
  dihitung dari HPP rata-rata gudang transaksi itu dan disimpan di baris penjualan.
- **AC-14:** Given transaksi lama, when ada penerimaan barang baru dengan harga berbeda, then laba
  transaksi lama di Laporan Laba dan Insights tidak berubah.
- **AC-15:** Given retur penjualan, when retur diselesaikan, then laba dibalik memakai HPP yang
  tersimpan di baris penjualan, di web maupun API.
- **AC-16:** Given draft PO otomatis, when dibuat untuk gudang pusat, then hanya berisi bahan yang
  dibeli oleh pusat; when dibuat untuk gudang outlet, then hanya berisi bahan yang dibeli oleh
  outlet. Qty dibulatkan ke atas dalam satuan beli default (kurang 2.300 g menjadi 3 kg) dengan
  harga satuan beli itu. Produk yang bukan bahan baku tetap seperti sekarang.
- **AC-17:** Given produk dengan satuan non-dasar yang sudah ada sebelum pembaruan (mis. karton
  isi 24), when PO dibuat dengan satuan itu, then harga default = harga beli dasar × 24.
- **AC-18:** Given form resep, when komponen dipilih, then takaran tampil dengan satuan stok bahan
  ("18 g") dan bahan baku tampil lebih dulu di daftar pilihan.
- **AC-19:** Given klien API yang mengirim item PO atau penerimaan tanpa satuan, when data disimpan,
  then satuan dasar dengan konversi 1 dipakai dan hasilnya sama seperti sebelum pembaruan.
- **AC-20:** Given pengguna dengan akses ke beberapa gudang, when daftar Bahan Baku dibuka, then
  setiap bahan menampilkan satuan stok, dibeli oleh, stok minimum, serta stok dan HPP rata-rata di
  setiap gudang yang boleh diakses pengguna itu.
- **AC-21:** Given bahan baru dengan stok awal 2 kg di sebuah gudang outlet, when bahan disimpan,
  then stok gudang itu 2.000 g, mutasi stok awal tercatat, dan HPP rata-rata gudang itu sama dengan
  harga referensi bahan.

## Out of scope

- Satuan atau harga per supplier; qty dan takaran desimal; ongkir atau diskon pembelian yang masuk
  ke HPP; stok minimum per gudang.
- Batch dan kedaluwarsa untuk bahan dalam resep (item backlog berikutnya).
- Distribusi pusat ke outlet dengan permintaan dan persetujuan, serta halaman pantauan persediaan
  (item backlog berikutnya). Item ini hanya memastikan transfer stok yang sudah ada membawa nilai
  HPP.
- Pembuangan bahan, opname bahan dalam satuan beli, nilai selisih opname, perbaikan restock retur
  resep, dan baris susut di laporan laba (item backlog berikutnya).
- Pembuatan ulang data demo.

## Open questions

- Belum ada.

## Delivered by

- Issue: #24, #25, #26, #27, #28, #29, #30, #31, #32, #33, #34
- PR: #36 (AC-01, AC-17)
