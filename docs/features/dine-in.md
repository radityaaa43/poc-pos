# Fitur Dine-In (QR Menu)

## Ringkasan

Modul dine-in memungkinkan pelanggan memindai QR code di meja untuk melihat menu dan memesan langsung dariHP mereka. Pesanan masuk ke dashboard staff untuk dikonfirmasi, lalu diproses di kasir.

## Alur Kerja

```
Pelanggan scan QR → lihat menu & pilih item → pesanan terkirim (status: submitted)

Staff: Terima (accept) → stok dipotong, transaksi dibuat (belum lunas) / atau Tolak (reject)

BAYAR DULU, BARU DIBUAT. Dua siklus berjalan berdampingan, dengan satu gerbang di antaranya:

  SIKLUS PESANAN (dine_orders.status)          SIKLUS PEMBAYARAN (transactions.payment_status)
  ───────────────────────────────────          ───────────────────────────────────────────────
  submitted                                     (transaksi belum ada)
     │ Terima        dine-orders-process
  accepted  "Menunggu Pembayaran" ────────────► unpaid  (payment_method = open_bill, tanpa piutang)
     │                                              │ Bayar dari POS "Belum Lunas" atau Riwayat:
     │                                              │   tunai ................... paid
     │                                              │   transfer ................ pending → paid (konfirmasi kasir)
     │                                              │   QRIS / Midtrans / Xendit  pending → paid (webhook)
     │ Mulai Buat    dine-orders-prepare            ▼
     │   ▲ GERBANG: hanya bila paid ◄───────────── paid
  preparing
     │ Siap          dine-orders-prepare
  ready
     │ Serahkan      dine-orders-handover
  served ── langsung completed (tagihan sudah paid)
  completed  (keluar dari antrean; tamu melihat "Selesai")
```

Aturan:

- **Pembayaran dulu.** "Mulai Buat" (`accepted → preparing`) ditolak kecuali `payment_status` transaksi `paid`. `pending`
  (transfer menunggu konfirmasi, gateway menunggu webhook) belum dianggap lunas. Gerbang dicek di server, di dalam
  transaksi DB yang sama dengan perubahan status dan dengan baris transaksi terkunci (`lockForUpdate`), bukan hanya di UI.
- [BR-1] An order whose items are all unflagged (no `needs_preparation`) skips the kitchen: it is served when paid and completes at once (served_by stays empty); the Pay-later half lands in ticket #279.
- Setiap langkah pesanan hanya sah dari status sebelumnya; status yang salah ditolak (error `order`), langkah yang sudah
  diambil diulang = tidak berbuat apa-apa.
- Karena sudah lunas sebelum dibuat, `served` langsung menjadi `completed` pada saat "Serahkan". Keadaan "disajikan tapi
  belum lunas" tidak bisa terjadi lagi; `DineOrder::completeIfSettled()` (dipanggil juga dari hook `Transaction`) tinggal
  jaring pengaman.
- Siapa yang menjalankan tiap langkah ditentukan lewat permission di Settings → Roles, bukan role tetap.
- Antrean staff punya tampilan: **Baru** (`submitted`), **Menunggu Pembayaran** (`accepted` dan belum lunas, juga tampil di
  POS "Belum Lunas" dan Riwayat sebagai "Belum Lunas"), **Dapur** (`accepted` yang sudah lunas + `preparing`) dan
  **Penyerahan** (`ready`). Pesanan yang belum lunas tidak muncul di Dapur. `completed` hilang dari antrean.

Tagihan belum lunas (*Belum Lunas*) bukan piutang: tidak ada `Receivable`, tidak ada tenggat. Kasir bebas memilih
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
| `status` | string | submitted/accepted/preparing/ready/served/completed, plus rejected/cancelled. Sumber kebenaran siklus pesanan; `completed` = served dan transaksi lunas |
| `notes` | text | Catatan pesanan |
| `payment_option` | enum | pay_at_counter / pay_later (pay_online ditolak) |
| `payment_method` | string | midtrans/xendit (nullable) |
| `payment_status` | string | pending/paid/failed (nullable) |
| `payment_reference` | string | Reference dari gateway (nullable) |
| `payment_url` | string | URL pembayaran (nullable) |
| `cashier_id` | foreignId | Kasir yang konfirmasi (nullable) |
| `transaction_id` | foreignId | Transaksi terkait (nullable) |
| `subtotal` | integer | Total pesanan |
| `item_count` | integer | Jumlah item |
| `accepted_at`/`accepted_by`, `preparing_at`/`preparing_by`, `ready_at`/`ready_by`, `served_at`/`served_by`, `completed_at` | timestamp / foreignId | Kapan dan siapa (user, nullable, `nullOnDelete`) untuk tiap langkah, diisi dalam transaksi DB yang sama dengan perubahan status dan tidak ditimpa oleh pengulangan. Bahan analisis kecepatan dapur per menu (laporannya issue terpisah). Yang menentukan tahap adalah `status` |

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
| POST | `/dashboard/dine-orders/{order}/prepare` | permission:dine-orders-prepare |
| POST | `/dashboard/dine-orders/{order}/ready` | permission:dine-orders-prepare |
| POST | `/dashboard/dine-orders/{order}/serve` | permission:dine-orders-handover |
| POST | `/dashboard/dine-orders/{order}/pay` | permission:dine-orders-process, active_shift |
| POST | `/dashboard/dine-orders/{order}/reject` | permission:dine-orders-process |

### Publik

| Method | Route | Deskripsi |
|--------|-------|-----------|
| GET | `/dine/{token}` | Halaman menu publik |
| POST | `/dine/{token}/order` | Submit pesanan |
| GET | `/dine-order/{accessToken}` | Halaman status pesanan |
| GET | `/dine-order/{accessToken}/check` | Endpoint polling status (JSON); also carries `payment` (see "Guest online payment") |
| POST | `/dine-order/{accessToken}/pay` | Guest starts the online payment of an accepted order (`dine-order.pay`, limiter `dine-order-pay`) |

## Permissions

| Permission | Deskripsi |
|------------|-----------|
| `dine-tables-access` | Lihat area & meja |
| `dine-tables-create` | Tambah area/meja |
| `dine-tables-update` | Edit posisi meja |
| `dine-tables-delete` | Hapus meja |
| `dine-orders-access` | Lihat daftar pesanan |
| `dine-orders-process` | Terima/tolak pesanan dan terima pembayaran tagihan (Bayar) |
| `dine-orders-prepare` | Dapur: "Mulai Buat" dan "Siap" |
| `dine-orders-handover` | "Serahkan": verifikasi pesanan sudah diserahkan ke pelanggan |

**Role cashier dan manager** mendapat semuanya (toko kecil: satu orang menjalankan semua langkah). Toko lain memberi `dine-orders-prepare` ke role dapur dan `dine-orders-handover` ke role yang menyerahkan pesanan lewat Settings → Roles; tidak ada role tetap. Production: jalankan ulang `PermissionSeeder` lalu `RoleSeeder` (idempoten).

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
- Opsi saat ini: Bayar di Kasir (`pay_at_counter`) dan, bila outlet mengaktifkan Bayar Belakangan, Bayar Belakangan (`pay_later`)

### Polling Status
- Halaman status auto-refresh setiap 5 detik selama pesanan belum completed/rejected/cancelled
- Progres tamu: Diterima → Menunggu Pembayaran → Sedang dibuat → Siap → Disajikan → Selesai. Banner mengikuti `status` dan apakah tagihan sudah lunas: `accepted` belum lunas = "Menunggu Pembayaran", `accepted` lunas = "Diterima", lalu `preparing`, `ready`, `served` (sesaat, langsung `completed`)

### Konversi Staff
- Accept: stok dipotong langsung, pesanan siap diproses
- Reject: dengan alasan opsional
- Mulai Buat / Siap (dapur) dan Serahkan: langkah berurutan, masing-masing dengan permission sendiri (lihat Alur Kerja). Mulai Buat hanya muncul dan hanya diterima server untuk tagihan yang sudah lunas
- Kitchen steps moved to the kitchen display (`docs/features/kitchen-display.md`): Mulai Buat / Siap are no longer on the Orders page, and the order's `preparing` / `ready` status and trail now follow its kitchen items. Serahkan moved there too (#238): the Orders page no longer has a Serahkan button, and the old `dine-orders.serve` route hands over the order's ready kitchen items
- Bayar: lihat "Pembayaran tagihan belum lunas" di bawah

## Pembayaran

Saat ini endpoint `/dine/{token}/order` menerima `payment_option: pay_at_counter` dan `pay_later`; `pay_later` ditolak server bila setting outlet *Bayar Belakangan* mati (pesan: "Bayar Belakangan tidak tersedia di outlet ini. Pilih Bayar di kasir."). Menu menampilkan tombol "Pesan — Bayar Belakangan" hanya bila setting aktif. Saat staf menerima pesanan `pay_later`, tagihannya diberi `is_pay_later` (tanpa piutang), item dapur yang perlu dibuat langsung tercatat (Belum lunas), dan pesanan tanpa item dapur langsung `served` lalu `completed` setelah dibayar; pelunasan tetap lewat `dine-orders.pay`.
`pay_online` belum terhubung ke payment gateway dan akan ditolak oleh validasi backend.

### Pembayaran tagihan belum lunas

Pesanan yang sudah diterima (accepted sampai ready) dibayar dari dua tempat, keduanya memakai satu endpoint
(`POST /dashboard/dine-orders/{order}/pay`) dan **memperbarui transaksi yang dibuat saat accept** (tidak ada transaksi baru).
Pembayaran harus selesai sebelum pesanan dibuat (gerbang "Mulai Buat" di atas):

- **POS** → bagian "Belum Lunas" (pesanan yang belum lunas di outlet kasir; kini juga penjualan Bayar Belakangan tanpa piutang, lihat pos-transactions.md).
- **Riwayat Transaksi** → baris dengan pesanan dine-in yang belum lunas tampil "Belum Lunas · Meja X" (badge amber) dengan tombol "Bayar".

Payload memakai `tenders[]` yang sama dengan checkout POS (`TransactionTenderService::normalize`). Layar bayar menawarkan
satu metode per tagihan:

- **Tunai** (dengan kembalian) → langsung `paid`.
- **Transfer bank** → `pending` sampai kasir mengonfirmasi di Riwayat Transaksi (aturan #153), lalu `paid`.
- **QRIS / Midtrans / Xendit** → tagihan dicharge lewat `PaymentGatewayManager::createTenderPayment` terhadap transaksi
  yang sama, status `pending`, lalu halaman struk menampilkan QR. Tagihan tetap "Belum Lunas" sampai webhook
  (`PaymentWebhookController`) menandainya `paid`. Charge gagal = tagihan tetap `unpaid` dan kasir bisa memilih metode lain.
  Percobaan gateway yang `failed`/`expired` boleh diganti metode lain (tender lamanya diganti).
- Tender gateway tidak boleh digabung (split) dengan tender lain pada tagihan dine-in.
- Selama pembayaran gateway/transfer masih `pending`, membayar lagi ditolak (tidak ada charge kedua, tidak ada tender
  kedua); POS dan Riwayat menampilkan "Lihat QR" ke struk.

Saat dibayar, dalam satu `DB::transaction` dengan `lockForUpdate()` pada pesanan dan transaksi:

- `payment_method`, `cash`, `change`, `bank_account_id` dan tender dicatat.
- `cashier_id` dan `cashier_shift_id` pindah ke kasir yang menerima uang dan **shift aktifnya**. Kas yang diharapkan shift
  (`expected_cash`) hanya bertambah saat tunai benar-benar `paid`; tagihan belum lunas tidak dihitung sama sekali.
- Stok tidak dipotong lagi (sudah dipotong saat accept).
- Membayar ulang tagihan yang sudah lunas ditolak (`order` error), jadi tidak ada pembayaran ganda.
- Kasir harus punya shift aktif di outlet yang sama dengan transaksi.
- Pesanan `completed` pada saat "Serahkan" karena tagihan sudah `paid`. Jaring pengaman: `Transaction` memanggil
  `DineOrder::completeIfSettled()` saat `payment_status` berubah ke `paid` (jalur apa pun), yang menyelesaikan pesanan `served`.
- Perintah `transactions:expire` tidak menyentuh tagihan dine-in: usia transaksi (dibuat saat accept) tidak berarti ditinggalkan.

### Guest online payment (REQ-005, server half)

After staff accept an order, the guest can pay its open bill online from the status page, at an outlet whose Midtrans or
Xendit is ready (Settings → Payment). Paying never happens at submit (`pay_online` stays rejected). The pay panel on the
page ships with the second half of #325; until then only the endpoint and the `payment` payload exist.

- `POST /dine-order/{accessToken}/pay` is public (the order token is the only credential, no permission, no login).
  It reads only `method` (`qris`, the default, or `gateway` for the gateway's own page with every enabled method); an
  `amount` in the body is never read. The limiter `dine-order-pay` allows 5 requests a minute per order token **and**
  IP (not one bucket per IP, so one cafe Wi-Fi does not block other tables).
- The amount is always `Transaction::remainingTotal()` of the accepted bill, worked out by the server. A bill with
  nothing left (fully returned) is refused without calling the gateway.
- Each press is a new **payment attempt** (`transactions.payment_attempt`, order id `{invoice}-r{n}`): the charge is claimed and
  committed under the bill's row lock, then the gateway is called with **no DB transaction open**, then the result is
  stored only if the bill is still on that attempt. A second press (or a retry after failed/expired) supersedes the
  first; the old charge's webhook is ignored by the existing guard (a late paid callback is only marked for a manual refund).
  The existing webhook settles the bill and the existing paid hook sends the flagged items to the kitchen, once.
- A guest charge never writes `cashier_id` or `cashier_shift_id`: the payment stays in the shift that accepted the order.
- It creates no tender rows. Therefore a **pending bill that has tender rows is the cashier's own** gateway charge or
  transfer, and a bill with money already taken in a split: the guest cannot start a second charge (state `counter`).
  A pay-later bill (`is_pay_later`) is also `counter` for now.
- Refusals are 422 `errors.payment[0]`: `not_payable` (not accepted, rejected, nothing left, cashier's charge, pay-later bill),
  `already_paid`, `no_gateway`, `gateway_error` (the call failed: the bill is set to `failed` with reason `gateway_error`, so
  the cashier can pay at the counter). 404 for an unknown token, 429 over the limit.
- `transactions.payment_failure_reason` is `expired` when the gateway reported an expiry (Midtrans `expire`, Xendit `EXPIRED`), `gateway_error` when
  the charge could not be created, null otherwise; it lets the page tell Kedaluwarsa from Pembayaran gagal.
- The guest's QRIS and hosted page expire after 15 minutes, and the gateway's finish redirect goes back to the status page
  (not the staff-only receipt).
- `payment` on the status page props and on the `/check` response: `{state, amount, attempt, payment_url, qr}`, or `null` while there is nothing to show.
  `state` is `can_pay`, `pending`, `failed`, `expired`, `gateway_error`, `counter` (pay at the counter) or `paid`. `qr` (an SVG data URI) is
  only on the page, never on the poll. It holds no invoice, bill id, customer, outlet or gateway reference.

## Catatan Teknis

- QR generator: `simplesoftwareio/simple-qrcode`
- Status polling: fetch JSON setiap 5 detik (tanpa broadcast/realtime dependency)
- Stok dipotong saat `accept` — bukan saat submit
- Core POS (`CheckoutService`) tidak dimodifikasi; `TransactionController` hanya menambah data Belum Lunas (POS) dan relasi pesanan di Riwayat
- Cart tetap berbasis `cashier_id` — tidak terpengaruh oleh dine-in
