# Fitur Dine-In (QR Menu)

## Ringkasan

Modul dine-in memungkinkan pelanggan memindai QR code di meja untuk melihat menu dan memesan langsung dariHP mereka. Pesanan masuk ke dashboard staff untuk dikonfirmasi, lalu diproses di kasir.

## Alur Kerja

```
Pelanggan scan QR
  → Lihat menu & pilih item
  → Pilih metode pembayaran (kasir)
  → Pesanan terkirim → status: submitted

Staff lihat pesanan di dashboard
  → Terima (accept) → stok dipotong, transaksi dibuat (belum lunas), pesanan jadi tagihan terbuka
  → Atau Tolak (reject) dengan alasan

Setelah diterima, dua alur berjalan terpisah:
  Penyajian: staff tekan "Sajikan" di antrean pesanan → served_at terisi
  Pembayaran: kasir tekan "Bayar" di POS (Tagihan Terbuka) atau Riwayat Transaksi → transaksi lunas

Pesanan menjadi completed hanya jika sudah disajikan DAN lunas (urutan bebas)
Pesanan completed keluar dari antrean; halaman status tamu menampilkan "Selesai"
```

Tagihan terbuka (*open bill*) bukan piutang: tidak ada `Receivable`, tidak ada tenggat. Kasir bebas memilih
metode pembayaran saat membayar; `payment_method` transaksi bernilai netral `open_bill` sampai saat itu.

## Database

### Tabel: `dine_areas`
Area/grouping meja (contoh: Indoor, Outdoor, VIP).

| Field | Tipe | Deskripsi |
|-------|------|-----------|
| `name` | string | Nama area |
| `sort_order` | integer | Urutan tampil |
| `is_active` | boolean | Area aktif/nonaktif |

### Tabel: `dine_tables`
Meja individual dengan QR token.

| Field | Tipe | Deskripsi |
|-------|------|-----------|
| `dine_area_id` | foreignId | Relasi ke area (nullable) |
| `name` | string | Nama meja (contoh: M1, Outdoor-1) |
| `token` | uuid | UUID auto-generate (unik per meja) |
| `capacity` | integer | Kapasitas orang |
| `pos_x` | integer | Posisi grid X (0-24) |
| `pos_y` | integer | Posisi grid Y (0-14) |
| `shape` | enum | `circle` atau `square` |
| `is_active` | boolean | Meja aktif/nonaktif |

### Tabel: `dine_orders`
Header pesanan pelanggan.

| Field | Tipe | Deskripsi |
|-------|------|-----------|
| `dine_table_id` | foreignId | Meja tujuan |
| `customer_id` | foreignId | (nullable) |
| `access_token` | uuid | Token unik untuk halaman status |
| `status` | enum | submitted/accepted/completed/rejected/cancelled. `completed` = disajikan dan lunas |
| `notes` | text | Catatan pesanan |
| `payment_option` | enum | pay_at_counter / pay_online |
| `payment_method` | string | midtrans/xendit (nullable) |
| `payment_status` | string | pending/paid/failed (nullable) |
| `payment_reference` | string | Reference dari gateway (nullable) |
| `payment_url` | string | URL pembayaran (nullable) |
| `cashier_id` | foreignId | Kasir yang konfirmasi (nullable) |
| `transaction_id` | foreignId | Transaksi terkait (nullable) |
| `subtotal` | integer | Total pesanan |
| `item_count` | integer | Jumlah item |
| `served_at` | timestamp | Kapan staff menandai pesanan disajikan (nullable). Penyajian terpisah dari pembayaran (status transaksi) |

### Tabel: `dine_order_items`
Item-item dalam pesanan.

| Field | Tipe | Deskripsi |
|-------|------|-----------|
| `dine_order_id` | foreignId | Header pesanan |
| `product_id` | foreignId | Produk dipesan |
| `unit_id` | foreignId | Unit (nullable) |
| `qty` | integer | Jumlah |
| `price` | integer | Harga saat pemesanan |
| `note` | string | Catatan item (nullable) |

## Pengaturan

Dikontrol via `Setting` table:

| Key | Default | Deskripsi |
|-----|---------|-----------|
| `dine_in_enabled` | true | Fitur dine-in aktif |
| `dine_in_self_order_enabled` | true | Pelanggan bisa pesan sendiri |
| `dine_in_pay_online_enabled` | true | Pengaturan legacy; pembayaran online belum tersedia dan endpoint hanya menerima `pay_at_counter` |

## Routes

### Admin (dashboard)

| Method | Route | Middleware |
|--------|-------|-----------|
| GET | `/dashboard/dine-areas` | permission:dine-tables-access |
| POST | `/dashboard/dine-areas` | permission:dine-tables-create |
| PATCH | `/dashboard/dine-areas/{area}` | permission:dine-tables-access |
| DELETE | `/dashboard/dine-areas/{area}` | permission:dine-tables-access |
| GET | `/dashboard/dine-tables` | permission:dine-tables-access |
| POST | `/dashboard/dine-tables` | permission:dine-tables-create |
| PATCH | `/dashboard/dine-tables/{table}` | permission:dine-tables-update |
| DELETE | `/dashboard/dine-tables/{table}` | permission:dine-tables-delete |
| GET | `/dashboard/dine-tables/{table}/qr` | permission:dine-tables-access |
| GET | `/dashboard/dine-orders` | permission:dine-orders-access |
| POST | `/dashboard/dine-orders/{order}/accept` | permission:dine-orders-process |
| POST | `/dashboard/dine-orders/{order}/serve` | permission:dine-orders-process |
| POST | `/dashboard/dine-orders/{order}/pay` | permission:dine-orders-process, active_shift |
| POST | `/dashboard/dine-orders/{order}/reject` | permission:dine-orders-process |

### Publik

| Method | Route | Deskripsi |
|--------|-------|-----------|
| GET | `/dine/{token}` | Halaman menu publik |
| POST | `/dine/{token}/order` | Submit pesanan |
| GET | `/dine-order/{accessToken}` | Halaman status pesanan |
| GET | `/dine-order/{accessToken}/check` | Endpoint polling status (JSON) |

## Permissions

| Permission | Deskripsi |
|------------|-----------|
| `dine-tables-access` | Lihat area & meja |
| `dine-tables-create` | Tambah area/meja |
| `dine-tables-update` | Edit posisi meja |
| `dine-tables-delete` | Hapus meja |
| `dine-orders-access` | Lihat daftar pesanan |
| `dine-orders-process` | Terima/tolak, sajikan, dan bayar tagihan pesanan |

**Role cashier** mendapat: `dine-orders-access` + `dine-orders-process`

## Fitur Utama

### Floor Plan Editor (SVG Grid)
- Tampilan grid SVG 25x15 cell (40px/cell)
- Drag-and-drop meja untuk reposisi bagi pengguna yang memiliki izin update
- Posisi meja dapat diatur dari form; posisi awal otomatis memilih cell kosong dan validasi membatasi grid ke X 0-24 dan Y 0-14
- Klik atau fokus meja untuk melihat detail dan aksi yang tersedia sesuai izin
- Mode daftar sebagai alternatif, dengan aksi QR tersedia di kedua tampilan
- Filter berdasarkan area dan status aktif/nonaktif; kombinasi filter dipertahankan saat filter lain diubah

### QR Code Generation
- QR berisi URL: `{APP_URL}/dine/{token}`
- Di-generate via `simplesoftwareio/simple-qrcode`
- Dashboard membuka QR sebagai SVG (format PNG butuh ekstensi `imagick`, yang tidak ada di image Docker/CI); endpoint API `/api/v1/dine-tables/{table}/qr` masih PNG

### Self-Order Pelanggan
- Pilih kategori & produk
- Keranjang real-time
- Catatan opsional per item
- Opsi saat ini: Bayar di Kasir (`pay_at_counter`)

### Polling Status
- Halaman status auto-refresh setiap 5 detik saat status = submitted atau accepted (berhenti saat completed/rejected)
- Notifikasi visual per status (menunggu/diterima/disajikan/selesai/ditolak). "Disajikan" = accepted dan `served_at` terisi, tinggal menunggu bayar

### Konversi Staff
- Accept: stok dipotong langsung, pesanan siap diproses
- Reject: dengan alasan opsional
- Sajikan: staff menandai pesanan disajikan dari antrean (idempoten). Pesanan yang sudah disajikan keluar dari antrean dan menunggu di Tagihan Terbuka bila belum lunas; yang sudah lunas tapi belum disajikan tetap di antrean
- Bayar: lihat "Pembayaran tagihan terbuka" di bawah

## Pembayaran

Saat ini endpoint `/dine/{token}/order` hanya menerima `payment_option: pay_at_counter`.
`pay_online` belum terhubung ke payment gateway dan akan ditolak oleh validasi backend.

### Pembayaran tagihan terbuka

Pesanan `accepted` dibayar dari dua tempat, keduanya memakai satu endpoint (`POST /dashboard/dine-orders/{order}/pay`)
dan **memperbarui transaksi yang dibuat saat accept** (tidak ada transaksi baru):

- **POS** → bagian "Tagihan Terbuka" (daftar pesanan accepted yang belum lunas, di outlet kasir).
- **Riwayat Transaksi** → baris `unpaid` yang punya pesanan dine-in tampil "Belum Lunas · Meja X" (badge amber) dengan tombol "Bayar".

Payload memakai `tenders[]` yang sama dengan checkout POS (`TransactionTenderService::normalize`): tunai (dengan kembalian)
atau transfer bank. Layar bayar saat ini satu metode per tagihan; server menerima sampai dua tender (split) karena
validasinya dipakai bersama. Gateway (Midtrans/Xendit/QRIS) belum didukung untuk tagihan terbuka.

Saat dibayar, dalam satu `DB::transaction` dengan `lockForUpdate()` pada pesanan dan transaksi:

- `payment_method`, `cash`, `change`, `bank_account_id` dan tender dicatat; `payment_status` jadi `paid`.
- `cashier_id` dan `cashier_shift_id` pindah ke kasir yang menerima uang dan **shift aktifnya**. Kas yang diharapkan shift
  (`expected_cash`) hanya bertambah saat tunai benar-benar dibayar; tagihan terbuka tidak dihitung sama sekali.
- Stok tidak dipotong lagi (sudah dipotong saat accept).
- Membayar ulang tagihan yang sudah lunas ditolak (`order` error), jadi tidak ada pembayaran ganda.
- Kasir harus punya shift aktif di outlet yang sama dengan transaksi.
- Pesanan menjadi `completed` bila sudah disajikan (`Transaction` memanggil `DineOrder::completeIfSettled()` saat
  `payment_status` berubah ke `paid`, jadi jalur lain seperti konfirmasi transfer ikut menyelesaikannya).

## Catatan Teknis

- QR generator: `simplesoftwareio/simple-qrcode`
- Status polling: fetch JSON setiap 5 detik (tanpa broadcast/realtime dependency)
- Stok dipotong saat `accept` — bukan saat submit
- Core POS (`CheckoutService`) tidak dimodifikasi; `TransactionController` hanya menambah data Tagihan Terbuka (POS) dan relasi pesanan di Riwayat
- Cart tetap berbasis `cashier_id` — tidak terpengaruh oleh dine-in
