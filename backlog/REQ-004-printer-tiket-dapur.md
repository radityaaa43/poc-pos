# REQ-004: Printer tiket dapur

- **Status:** ready
- **Owner:** @radityaaa43
- **Module:** module:sales
- **Updated:** 2026-11-26

## User story

Sebagai staf dapur di kedai yang memakai kertas, saya ingin setiap pesanan yang masuk dapur
langsung tercetak di printer dapur, agar saya bisa membuat menu tanpa harus melihat layar.

Sebagai kasir, saya ingin pesanan yang sudah saya terima tidak perlu saya sampaikan lagi ke dapur,
agar antrean kasir tidak tertahan.

Sebagai pemilik, saya ingin tahu kalau printer dapur bermasalah, agar tidak ada pesanan yang
terlewat.

## Context

REQ-003 (#232) memberi semua pesanan alur dapur dan **Layar Dapur** (#258). Banyak kedai masih
memakai kertas di dapur. Mereka belum punya layar, atau dapurnya terlalu panas dan basah untuk
tablet. Aplikasi sudah bisa mencetak struk ke printer thermal lewat **WebUSB ESC/POS** dari browser
(`resources/js/Utils/escpos.js`, Settings → Printer, `docs/features/thermal-printer.md`). REQ-003
menaruh printer dapur di luar cakupan. REQ ini menambahkannya.

**Keputusan** (Business/PM, didelegasikan pemilik, 2026-11-26; pemilik menyetujui urutan A → B → C
→ D):

1. **Perangkat Layar Dapur adalah stasiun cetak.** Pesanan dicetak dari perangkat yang membuka Layar
   Dapur, dengan printer yang terhubung lewat USB ke perangkat itu.
   - Ditolak: mencetak dari perangkat kasir, karena pesanan QR meja tidak lewat kasir.
   - Ditolak: printer jaringan (LAN), karena server di cloud tidak bisa menjangkau jaringan toko.
2. **Satu tiket per pesanan setiap kali item masuk dapur.** Waktunya sama dengan aturan REQ-003:
   *Bayar sekarang* saat lunas, *Bayar Belakangan* langsung.
3. **Tiket tanpa harga.** Dapur hanya butuh menu, jumlah dan catatan.
4. **Pesanan tambahan** (REQ-003 AC-21) dicetak sebagai tiket **TAMBAHAN**. **Item yang dibatalkan**
   (REQ-003 AC-11) dicetak sebagai tiket **DIBATALKAN**, supaya dapur berhenti membuat.
5. **Satu kali per stasiun, juga setelah dimuat ulang.**
   - Setiap item dicetak sekali per stasiun cetak. Catatan "sudah dicetak" disimpan di server per
     stasiun, sehingga memuat ulang halaman atau memasang tablet kedua tidak mencetak ulang semuanya.
   - Cetak ulang selalu manual dan bertanda **CETAK ULANG**.
6. **Layar tetap sumber kebenaran.** Gagal cetak tidak pernah menghapus atau menahan pesanan. Pesanan
   tetap di Layar Dapur dan kegagalannya terlihat.
7. **Pengaturan per perangkat.** Cetak otomatis dan ukuran kertas (58/80 mm) disimpan di perangkat,
   seperti pengaturan printer kasir sekarang. Perangkat diberi nama stasiun (misalnya "Dapur 1") agar
   catatan cetaknya terpisah.
8. **Perangkat yang didukung:**
   - **Android dengan Chrome dan USB-OTG:** didukung.
   - **PC Windows dengan Chrome:** didukung untuk printer yang bisa dipakai lewat WebUSB. Sebagian
     printer perlu driver-nya diganti ke WinUSB sekali per PC. Langkahnya didokumentasikan, dan layar
     pengaturan menampilkan pesan jelas kalau printer tidak bisa dipakai, bukan gagal diam-diam.
   - **iPad dan perangkat tanpa WebUSB:** tidak didukung.
   - Ditolak: menjanjikan semua printer di Windows, karena Windows mengunci banyak printer struk ke
     driver bawaannya.

## Process

**Sekarang (as-is).**
- Pesanan masuk Layar Dapur (REQ-003).
- Kedai tanpa layar tetap menyampaikan pesanan secara lisan atau dengan kertas tulisan tangan.
- Pesanan bisa terlewat dan tulisan bisa salah baca.

**Tujuan (to-be).**
1. Staf membuka Layar Dapur di PC atau tablet dapur, menghubungkan printer USB, dan menyalakan *Cetak
   otomatis*.
2. Begitu item masuk dapur, tiket dicetak paling lambat 10 detik kemudian. Tiket berisi nomor pesanan
   atau meja, jenis pesanan, jam, menu, jumlah dan catatan, plus tanda *BELUM LUNAS* untuk Bayar
   Belakangan.
3. Pesanan tambahan dicetak sebagai tiket TAMBAHAN, dan pembatalan sebagai tiket DIBATALKAN.
4. Kalau printer gagal, Layar Dapur menampilkan peringatan dan pesanan tetap tampil. Staf bisa
   menekan *Cetak ulang* setelah printer kembali.

## Stakeholders

| Pihak | Peran dalam alur | Kepentingan | Yang memutuskan |
|---|---|---|---|
| Pemilik usaha | Memilih memakai printer dapur | Tidak ada pesanan terlewat | Pemakaian per outlet |
| Staf dapur | Membaca tiket, menyalakan printer | Tiket jelas, tidak dobel | — |
| Kasir dan pelayan | Menerima pesanan | Tidak perlu menyampaikan lagi ke dapur | — |
| Tamu | Memesan | Pesanan cepat dan benar | — |

## Acceptance criteria

### Mencetak

- **AC-01:** Given Layar Dapur terbuka dengan printer terhubung dan *Cetak otomatis* aktif, when item
  pesanan masuk dapur, then satu tiket dapur tercetak paling lambat 10 detik kemudian, berisi nomor
  pesanan (invoice) atau nama meja, jenis pesanan, jam masuk, menu, jumlah dan catatan per item,
  tanpa harga.
- **AC-02:** Given pesanan Bayar Belakangan, when tiketnya dicetak, then tiket memuat tanda **BELUM
  LUNAS**.
- **AC-03:** Given pesanan tambahan (REQ-003 AC-21), when itemnya masuk dapur, then tiket terpisah
  bertanda **TAMBAHAN** dicetak dengan nomor pesanan asal.
- **AC-04:** Given item yang dibatalkan lewat retur (REQ-003 AC-11), when pembatalan selesai, then
  tiket **DIBATALKAN** dicetak berisi menu dan jumlah yang dibatalkan.
- **AC-05:** Given pesanan yang sudah dicetak di perangkat ini, when Layar Dapur memperbarui diri atau
  dibuka ulang, then pesanan itu tidak dicetak lagi.
- **AC-06:** Given pesanan di Layar Dapur, when staf menekan **Cetak ulang**, then tiketnya dicetak
  lagi dengan tanda **CETAK ULANG**.

### Pengaturan dan kegagalan

- **AC-07:** Given Layar Dapur, when staf membuka pengaturan printer dapur, then staf bisa
  menghubungkan atau memutus printer USB, memilih kertas 58 atau 80 mm, menyalakan atau mematikan
  *Cetak otomatis*, dan mencetak tiket percobaan. Semua tersimpan di perangkat itu.
- **AC-08:** Given *Cetak otomatis* aktif, when printer tidak terhubung atau gagal mencetak, then Layar
  Dapur menampilkan peringatan **Printer dapur tidak terhubung** atau **Gagal mencetak**, pesanan
  tetap tampil, dan pesanan yang gagal ditandai **Belum tercetak** sampai dicetak ulang.
- **AC-09:** Given perangkat yang tidak mendukung WebUSB (misalnya iPad), when staf membuka pengaturan
  printer dapur, then muncul pesan bahwa perangkat ini tidak mendukung printer USB, dan Layar Dapur
  tetap berjalan tanpa cetak.
- **AC-10:** Given *Cetak otomatis* aktif, when tab Layar Dapur tidur atau cetakan terakhir yang
  berhasil sudah terlalu lama padahal ada pesanan baru, then layar menampilkan **Printer dapur offline
  atau tab tidur**. Layar menjaga perangkat tetap menyala selama *Cetak otomatis* aktif (Wake Lock).
- **AC-11:** Given PC Windows yang printernya dikunci driver Windows, when staf menghubungkan printer,
  then muncul pesan **Printer tidak bisa dipakai dari browser — ganti driver ke WinUSB** dengan tautan
  ke langkah di dokumentasi.

## Business rules

- **Uang:** tiket tidak memuat harga atau pembayaran, hanya tanda *BELUM LUNAS* untuk Bayar
  Belakangan.
- **Stok:** tidak berubah. Mencetak tidak memotong atau mengembalikan stok.
- **Izin:** pengaturan printer dapur memakai izin yang sama dengan membuka Layar Dapur
  (`dine-orders-prepare` atau `dine-orders-handover`). Tidak ada izin baru.
- **Pengaturan:** per perangkat. Tidak ada pengaturan outlet baru.
- **Sekali cetak:** satu tiket per kejadian (masuk, tambahan, batal) per stasiun, tercatat di server.
  Memuat ulang halaman tidak mencetak ulang. Cetak ulang selalu manual dan bertanda.
- **Antrean cetak:** tiket dicetak berurutan. Tiket yang gagal tetap di antrean dengan pesan **Gagal
  mencetak — coba lagi**, lalu dicoba lagi pada pembaruan berikutnya atau lewat tombol. Tiket tidak
  pernah hilang dan tidak pernah tercetak dua kali.

## Non-functional requirements

- **Kecepatan:** tiket tercetak paling lambat 10 detik setelah item masuk dapur, sama dengan AC-24
  REQ-003.
- **Keandalan:**
  - Beberapa pesanan yang masuk bersamaan dicetak berurutan tanpa ada yang hilang.
  - Gagal cetak tidak menghentikan pembaruan layar.
  - Setelah koneksi internet pulih, pesanan yang masuk saat putus dicetak.
- **Keamanan:** hanya pengguna yang boleh membuka Layar Dapur yang bisa mencetak.
- **Audit:** tidak perlu audit log. Cetak adalah aksi perangkat, bukan perubahan data.
- **Bahasa dan aksesibilitas:** label pengaturan dalam bahasa Indonesia dan Inggris. Isi tiket
  memakai nama menu apa adanya.

## Data and integrations

- **Data:** item dapur (REQ-003, `kitchen_items`) dan pesanan tambahan serta pembatalan dari REQ-003.
  Catatan cetak per stasiun disimpan di server (baru). Pengaturan printer disimpan di perangkat.
- **Integrasi:** printer thermal USB lewat WebUSB ESC/POS yang sudah ada (`escpos.js`). Tidak ada
  integrasi baru.

## Success metrics

- **Tiket tepat waktu:** ≥ 95% tiket tercetak ≤ 10 detik setelah item masuk dapur. Diukur saat uji
  manual per rilis.
- **Pesanan terlewat:** 0 pesanan yang tidak tercetak dan tidak terlihat. Diukur dari laporan staf dan
  dari tanda *Belum tercetak* yang tidak pernah dicetak ulang.

## Out of scope

- Printer jaringan (LAN/IP) dan cetak lewat cloud.
- Pemisahan tiket per stasiun (bar dan dapur panas), yang menjadi REQ tersendiri (opsi C).
- Printer label atau stiker.
- iPad dan perangkat tanpa WebUSB.

## Risks and assumptions

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Layar Dapur tertutup atau perangkat mati | Tidak ada tiket | Layar menampilkan status printer. Pemilik diberi tahu di dokumentasi bahwa perangkat harus tetap membuka Layar Dapur |
| Dua perangkat sama-sama mencetak | Tiket dobel | Sekali cetak berlaku per perangkat. Pemilik menyalakan *Cetak otomatis* di satu perangkat saja |
| Printer kehabisan kertas | Tiket tidak keluar | Peringatan *Gagal mencetak* dan tanda *Belum tercetak* (AC-08) |

- Kelayakan dicek Tech Lead (2026-11-26):
  - Android dengan Chrome berjalan.
  - Windows perlu driver WinUSB untuk banyak printer.
  - Antrean cetak dan catatan cetak di server harus dibangun.
  - Halaman harus tetap terbuka dan terjaga.
  - E2E memakai penampung cetak khusus tes.
- [asumsi] Aplikasi diakses lewat HTTPS, karena WebUSB butuh koneksi aman.

## Open questions

- Semua terjawab 2026-11-26 (keputusan 1–8, kelayakan dari Tech Lead).

## Delivered by

<!-- Issues and PRs, filled in as work ships. -->
