# REQ-003: Alur dapur untuk semua pesanan, layar dapur, dan kecepatan pembuatan menu

- **Status:** ready
- **Owner:** @radityaaa43
- **Module:** module:sales
- **Updated:** 2026-11-25

## User story

Sebagai staf dapur, saya ingin melihat setiap menu yang harus dibuat dan menandainya sedang dibuat
lalu siap, agar pesanan tidak terlewat dan kasir tahu kapan pesanan bisa diserahkan.

Sebagai kasir atau pelayan, saya ingin melihat menu yang sudah siap dan menandainya diserahkan,
agar pesanan selesai tercatat dan shift bisa ditutup dengan rapi.

Sebagai pemilik usaha, saya ingin melihat berapa lama dapur membuat setiap menu dibandingkan
target saya, agar saya bisa menemukan menu, outlet, atau jam yang lambat.

## Context

#128 (PR #221) memberi pesanan dine-in dua siklus terpisah:

- **Status penyajian:** submitted → accepted → preparing → ready → served → completed.
- **Status bayar:** unpaid → paid.

Penjualan POS (di tempat, bawa pulang, diantar) belum punya alur ini: transaksi langsung selesai,
dapur tidak melihat apa pun, dan tidak ada data berapa lama pembuatannya.

Keputusan pemilik (2026-11-25, sesi analisis REQ-003):

1. Semua jalur pesanan (dine-in QR, POS di tempat, bawa pulang, diantar) memakai alur dapur yang
   sama.
2. Hanya produk yang ditandai **Perlu dibuat di dapur** yang masuk dapur. Produk lain dalam
   transaksi yang sama langsung dianggap diserahkan.
3. Saat memesan, pembeli memilih **Bayar sekarang** atau **Bayar Belakangan**. Aturannya sama di
   semua channel; dine-in hanya berbeda channel (pesan lewat QR meja).
   - **Bayar sekarang:** pesanan diproses setelah lunas. Lunas berarti tunai, transfer yang sudah
     dikonfirmasi, QRIS, Midtrans, atau Xendit yang sudah berhasil. Split payment lunas jika semua
     bagiannya lunas. Pembayaran yang masih menunggu tetap ditahan, meski outlet mengaktifkan
     Bayar Belakangan.
   - **Bayar Belakangan:** hanya tersedia jika outlet mengaktifkannya (default nonaktif, diubah oleh
     pemilik). Pesanan langsung diproses.
4. Bayar Belakangan punya dua bentuk. Istilah "open bill" / "Tagihan Terbuka" tidak dipakai lagi di
   aplikasi.
   - **Pelanggan terdaftar yang diberi izin piutang:** boleh dibayar di hari lain; masuk tagihan
     (piutang) dengan jatuh tempo; tidak menahan tutup shift.
   - **Selain itu** (tamu, atau pelanggan terdaftar tanpa izin piutang): wajib lunas sebelum shift
     ditutup; tanpa piutang.
5. Pelanggan terdaftar mendapat penanda baru **Boleh piutang**. Saat ini belum ada; semua pelanggan
   terdaftar bisa memakai Bayar Belakangan.
6. Siap dan diserahkan dicatat **per item**, dengan tombol *Semua siap* dan *Serahkan semua*.
   Setiap perpindahan status mencatat waktu dan pelakunya.
7. Siapa yang boleh memasak dan menyerahkan diatur per toko lewat izin (`dine-orders-prepare`,
   `dine-orders-handover` dari #128 atau penggantinya untuk semua pesanan), tidak dikunci ke peran.
8. Shift tidak bisa ditutup selama masih ada item yang belum diserahkan, atau Bayar Belakangan
   tanpa izin piutang yang belum lunas. Pengguna dengan izin `cashier-shifts-force-close` boleh menutup paksa
   dengan catatan.
9. Pemilik bisa memasang target waktu buat per menu. Laporan menampilkan median waktu pembuatan,
   persentase dalam target, dan rata-rata waktu bayar sampai diserahkan per pesanan.

## Acceptance criteria

### Produk dan masuk dapur

- **AC-01:** Given form produk, when pemilik membuka produk, then ada pilihan *Perlu dibuat di
  dapur* dan *Target waktu buat (menit)* (boleh kosong). Saat fitur ini rilis, pilihan *Perlu
  dibuat di dapur* aktif untuk produk beresep (komposit) dan nonaktif untuk produk lain.
- **AC-02:** Given pesanan *Bayar sekarang* dari channel mana pun (POS atau QR meja), when
  transaksinya lunas, then item yang perlu dibuat muncul di layar dapur outlet tersebut dengan
  status *accepted*. Sebelum lunas (termasuk transfer yang belum dikonfirmasi, gateway yang
  menunggu webhook, atau split yang belum lunas semua), item tidak muncul di dapur.
- **AC-03:** Given outlet yang mengaktifkan Bayar Belakangan, when pembeli memilih Bayar Belakangan
  dari channel mana pun, then item yang perlu dibuat langsung muncul di layar dapur dan layar
  menandai pesanan itu *belum lunas*.
- **AC-04:** Given outlet yang tidak mengaktifkan Bayar Belakangan, when kasir atau tamu (QR meja)
  memesan, then pilihan Bayar Belakangan tidak tersedia.
- **AC-05:** Given transaksi berisi item yang perlu dibuat dan item yang tidak, when transaksi
  lunas, then hanya item yang perlu dibuat yang masuk dapur; item lain langsung tercatat
  diserahkan.

### Layar dapur dan serah terima

- **AC-06:** Given pesanan dine-in dan pesanan POS di outlet yang sama, when staf dapur membuka
  layar dapur, then semuanya tampil dalam satu antrean, urut dari yang paling lama menunggu, dengan
  nomor meja atau nomor pesanan, jenis pesanan, dan daftar item.
- **AC-07:** Given layar dapur terbuka, when pesanan baru masuk atau status berubah, then layar
  diperbarui tanpa dimuat ulang manual.
- **AC-08:** Given item di layar dapur, when pengguna dengan izin memasak menekan *Mulai buat*
  lalu *Siap* pada item, atau *Semua siap* pada pesanan, then status item berubah menjadi
  *preparing* lalu *ready*, dengan waktu dan pelaku tercatat per item.
- **AC-09:** Given item *ready*, when pengguna dengan izin serah terima menekan *Serahkan* pada item
  atau *Serahkan semua* pada pesanan, then item menjadi *served* dengan waktu dan pelaku tercatat;
  pesanan menjadi *completed* setelah semua itemnya diserahkan dan transaksinya lunas.
- **AC-10:** Given peran apa pun, when pemilik mengatur izin memasak dan serah terima, then hanya
  peran dengan izin itu yang melihat tombolnya, dan server menolak permintaan dari peran tanpa izin.
  Default izin: kasir dan manajer.

### Pembatalan, shift, offline

- **AC-11:** Given item di dapur, when transaksinya diretur lewat retur penjualan, then item tampil
  *Dibatalkan* di layar dapur lalu keluar dari antrean. Pilihan kembalikan ke stok default aktif jika
  item masih *accepted*, dan default nonaktif jika sudah *preparing* atau *ready*; pembuat retur
  tetap bisa mengubahnya. Item yang dibatalkan tidak dihitung di laporan kecepatan.
- **AC-12:** Given shift kasir terbuka, when kasir menutup shift sementara masih ada item yang
  belum diserahkan atau Bayar Belakangan tanpa izin piutang yang belum lunas, then shift tidak bisa
  ditutup dan kasir melihat daftarnya. Bayar Belakangan pelanggan dengan izin piutang tidak menahan
  tutup shift.
- **AC-13:** Given shift tertahan seperti AC-12, when pengguna dengan izin
  `cashier-shifts-force-close` menutup paksa dengan catatan wajib, then shift tertutup, item yang
  belum diserahkan ditandai *tidak diambil*, Bayar Belakangan tanpa izin piutang yang belum lunas masuk selisih
  kas di rekap shift, dan kejadian ini tercatat di audit log.
- **AC-14:** Given POS sedang offline, when kasir menjual item yang perlu dibuat, then kasir melihat
  pesan bahwa dapur belum menerima pesanan dan perlu disampaikan langsung; setelah sinkron, item
  masuk layar dapur, dan durasinya dihitung sejak server menerima pesanan.

### Laporan kecepatan

- **AC-15:** Given item yang sudah *ready*, when pemilik membuka laporan kecepatan dapur dan memilih
  outlet dan rentang waktu, then per menu terlihat jumlah item, median dan rata-rata *preparing →
  ready* (waktu buat), median dan rata-rata *accepted → ready* (waktu tunggu + buat), target, dan
  persentase item yang selesai dalam target (kosong jika menu tanpa target).
- **AC-16:** Given pesanan yang sudah *completed*, when pemilik membuka laporan yang sama, then
  terlihat rata-rata durasi dari lunas sampai semua item diserahkan per pesanan, per outlet dan per
  rentang waktu (data saja, tanpa target).

### Izin piutang pelanggan

- **AC-17:** Given pelanggan terdaftar, when pengguna yang berwenang membuka data pelanggan, then
  ada penanda *Boleh piutang*. Bayar Belakangan atas nama pelanggan dengan penanda ini menjadi
  piutang dengan jatuh tempo; tanpa penanda, tagihan wajib lunas sebelum shift ditutup. Hanya
  pengguna dengan izin baru `customers-credit-approve` (default: pemilik dan manajer, bukan kasir)
  yang bisa mengubah penanda; server menolak perubahan dari pengguna lain.
- **AC-18:** Given data sebelum fitur ini rilis, when fitur rilis, then pengaturan Bayar Belakangan
  aktif untuk outlet yang pernah punya transaksi Bayar Belakangan dan nonaktif untuk outlet lain,
  dan *Boleh piutang* aktif untuk pelanggan yang pernah punya piutang dan nonaktif untuk pelanggan
  lain.

## Metrik sukses

- **Utama:** median waktu buat (*preparing → ready*) per menu per outlet. Bulan pertama sesudah
  rilis adalah baseline; pemilik memasang target per menu sesudahnya. Sumber: waktu per item dari
  AC-08.
- **Kualitas data:** minimal 95% item dapur punya waktu lengkap sampai *ready*. Sumber: item dapur
  per outlet per minggu.
- **Kasir:** jumlah shift yang ditutup paksa karena pesanan atau tagihan tertahan (AC-13). Sumber:
  audit log.

## Out of scope

- Printer dapur (kitchen ticket printer).
- Mengubah cara stok dipotong: stok tetap dipotong saat penjualan (atau saat accept untuk dine-in,
  sesuai #128).
- Hutang supplier (Payable), batas nominal piutang (credit limit), dan perubahan cara piutang
  dicatat atau ditagih.
- Target waktu berbeda per outlet.
- Stasiun dapur terpisah (misalnya bar dan dapur panas) dalam satu outlet.

## Open questions

- Semua pertanyaan analisis terjawab 2026-11-25. Pertanyaan baru dicatat di sini.

## Delivered by

<!-- Issues and PRs, filled in as work ships. -->
