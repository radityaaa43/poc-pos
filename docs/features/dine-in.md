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

## Add-on orders

While an accepted order is not completed, or the server would still link to it (an add-on of it is open), its status page offers **Tambah pesanan** (#251). The link opens the table menu with that order's access token (`?add_on_to=`), and the submit stores the order it was made from (`dine_orders.add_on_to_dine_order_id`). The server refuses (422) a token that is unknown, of another table or of an order staff have not accepted, and a cancelled one: "Pesanan asal sudah dibatalkan — buat pesanan baru" (order cancelled or rejected, or every line returned; a `failed` or `expired` payment is not a cancellation, the bill can still be paid). A new order without that link is never merged, even at the same table. At accept the original is checked again under a lock: cancelled means the accept is refused and nothing is written (staff reject the add-on); finished (the original and every non-cancelled add-on paid, nothing left in the kitchen) means a plain new order; otherwise the new sale gets `transactions.parent_transaction_id` = the root sale; the kitchen display shows it inside the original's card under **Tambahan** (the tickets stay one per sale). Payment, stock and shift are those of any accepted order.

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
| `add_on_to_dine_order_id` | foreignId | Order this one was added to from its status page (nullable, `nullOnDelete`) |
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

### Table: `dine_reservations`
One table booking (see "Reservasi").

| Field | Type | Description |
|-------|------|-------------|
| `outlet_id` | foreignId | The outlet that took the booking (a table without an area is shared by every outlet) |
| `dine_table_id` | foreignId, nullable | The booked table; null once the table is deleted (marked reservations stay as history) |
| `guest_name` | string(100) | Guest's name |
| `phone` | string(30) | Personal data: shown only on the reservations page, never printed or written to the audit log |
| `party_size` | smallint | Number of guests, 1 to 100 |
| `reserved_at` | datetime | Date and time of the booking, wall time in `APP_TIMEZONE` |
| `note` | string(500) | Optional note |
| `status` | string | `booked` (Dicatat), `arrived` (Datang), `cancelled` (Batal), `no_show` (Tidak datang) |

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
| GET | `/dashboard/dine-reservations?date=Y-m-d` | permission:dine-reservations-manage |
| POST | `/dashboard/dine-reservations` | permission:dine-reservations-manage |
| PATCH | `/dashboard/dine-reservations/{reservation}` | permission:dine-reservations-manage |
| POST | `/dashboard/dine-reservations/{reservation}/mark` | permission:dine-reservations-manage |

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
| `dine-reservations-manage` | Reservations: see the list with phone numbers, record, change and mark |

**Role cashier dan manager** mendapat semua permission pesanan (toko kecil: satu orang menjalankan semua langkah). `dine-reservations-manage` is seeded to `manager`, `super-admin` and `dine-tables-access`, not to `cashier`; grant it in Settings → Roles to whoever takes bookings. Toko lain memberi `dine-orders-prepare` ke role dapur dan `dine-orders-handover` ke role yang menyerahkan pesanan lewat Settings → Roles; tidak ada role tetap. Production: jalankan ulang `PermissionSeeder` lalu `RoleSeeder` (idempoten).

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
- Dashboard membuka QR sebagai SVG (format PNG butuh ekstensi `imagick`, yang tidak dipasang di setup host maupun CI); endpoint API `/api/v1/dine-tables/{table}/qr` masih PNG

### Self-Order Pelanggan
- Pilih kategori & produk
- Keranjang real-time
- Catatan opsional per item
- Opsi saat ini: Bayar di Kasir (`pay_at_counter`) dan, bila outlet mengaktifkan Bayar Belakangan, Bayar Belakangan (`pay_later`)
- Tombol kirim `pay_at_counter`: di outlet meja yang gateway-nya siap (prop `onlinePayment`, aturan yang sama dengan pembayaran online tamu) berbunyi **Pesan** dengan petunjuk **Bisa bayar online setelah pesanan diterima**; di outlet lain tetap **Pesan — Bayar di Kasir**. Opsi yang dikirim sama
- Petunjuk di bawah tombol **Pesan — Bayar Belakangan** (`dineMenu.payLater.helper` / `helperOnline`): di outlet yang gateway-nya siap "Pesanan langsung dibuat. Bayar di kasir atau online dari halaman pesanan sebelum Anda pergi."; di outlet lain "Pesanan langsung dibuat. Bayar di kasir sebelum Anda pergi."

### Polling Status
- Halaman status auto-refresh setiap 5 detik selama pesanan belum completed/rejected/cancelled
- Progres tamu: Diterima → Menunggu Pembayaran → Sedang dibuat → Siap → Disajikan → Selesai. Banner mengikuti `status` dan apakah tagihan sudah lunas: `accepted` belum lunas = "Menunggu Pembayaran", `accepted` lunas = "Diterima", lalu `preparing`, `ready`, `served` (sesaat, langsung `completed`). Pesanan Bayar Belakangan yang `accepted` dan belum lunas berbunyi **Diterima**: "Pesanan diterima. Belum lunas: bayar sekarang atau di kasir sebelum Anda pergi.", atau "... bayar di kasir sebelum Anda pergi." bila panel bayar berstatus `counter`

### Konversi Staff
- Accept: stok dipotong langsung, pesanan siap diproses
- Reject: dengan alasan opsional
- Mulai Buat / Siap (dapur) dan Serahkan: langkah berurutan, masing-masing dengan permission sendiri (lihat Alur Kerja). Mulai Buat hanya muncul dan hanya diterima server untuk tagihan yang sudah lunas
- Kitchen steps live on the kitchen display (`docs/features/kitchen-display.md`): an order with kitchen items has no Mulai Buat, Siap or Serahkan on the Orders page; its `preparing` / `ready` / `served` status and trail follow its items, and the old `dine-orders.prepare` / `ready` / `serve` routes delegate to the item steps. An order without kitchen items (accepted before the display existed, or a sale the intake could not record) keeps those buttons on the Orders page, by permission and with the same pay-first gate, so it can still finish (#238, #382)
- Bayar: lihat "Pembayaran tagihan belum lunas" di bawah

## Reservasi

Page **Reservasi** (`/dashboard/dine-reservations`, permission `dine-reservations-manage`; REQ-009 J5). It needs the server: nothing is queued offline.

- A booking has a name, phone number, number of guests, date, time, table (an active table the active outlet may use) and an optional note.
- Two bookings of one table less than 2 hours apart are refused, naming the booking in the way (see the guest-name rule below); exactly 2 hours apart is allowed. `booked` and `arrived` hold the table, `cancelled` and `no_show` free it. The table row is locked during the check, so of two staff taking the same slot one is refused. A table shared by outlets is checked across them.
- Only a `booked` reservation can be changed or marked **Datang**, **Tidak datang** or **Batal**; a mark cannot be undone, and of two staff the first wins. There is no delete.
- A table with a reservation not yet marked (any date, past days too) cannot be deleted or deactivated, on the web or the API (`DineReservationService::deleteTable` / `updateTable`, under the same table-row lock as a booking): "Meja ini punya reservasi <nama> <tanggal jam> — tandai atau batalkan dulu". A table shared by outlets is held by a booking of any outlet, and only that outlet can mark it.
- **Guest name:** this refusal and the 2-hour clash name the guest only to someone with `dine-reservations-manage` whose active outlet owns that booking. Anyone else reads the outlet and the time ("Meja ini punya reservasi outlet <outlet> pada <tanggal jam> — tandai atau batalkan dulu").
- A booking stays editable on its own table after that table moves to another outlet; only a newly chosen table must be one the outlet may book. Deleting a table never deletes reservations: marked ones stay on the list as **Meja sudah dihapus**.
- An outlet with any reservation, booked or marked, cannot be deleted, on the web or the API, even without a warehouse or a sale (`OutletController::hasOperationalHistory`): deactivate it instead.
- The list is per outlet and date. Today's list also shows unmarked bookings of earlier days, so they can be released.
- The phone number is only on this page: `DineReservation` hides it from serialisation, no print path or guest page reads a reservation, and the audit log (`dine_reservation.created`, `.updated`, `.marked`, module `dine_reservations`) records neither the number nor the guest's name, only the flags `phone_changed` and `guest_name_changed`. Old reservations keep their number: there is no retention rule yet.
- Set `APP_TIMEZONE` to the outlet's zone (`Asia/Jakarta` for WIB) before the first booking: dates and times are stored and compared as wall time in it.
- Not built yet (waits for the table status of #426): a table showing **Dipesan** and the warning when it is used. The seam is `DineReservation::bookedSoon()` / `bookedSoonByTable()` (booked, from 30 minutes before until marked). Refusing to remove a table with an open order is also still open (#426); the reservation half above is done.

## Pembayaran

Saat ini endpoint `/dine/{token}/order` menerima `payment_option: pay_at_counter` dan `pay_later`; `pay_later` ditolak server bila setting outlet *Bayar Belakangan* mati (pesan: "Bayar Belakangan tidak tersedia di outlet ini. Pilih Bayar di kasir."). Menu menampilkan tombol "Pesan — Bayar Belakangan" hanya bila setting aktif. Saat staf menerima pesanan `pay_later`, tagihannya diberi `is_pay_later` (tanpa piutang), item dapur yang perlu dibuat langsung tercatat (Belum lunas), dan pesanan tanpa item dapur langsung `served` lalu `completed` setelah dibayar; pelunasan tetap lewat `dine-orders.pay`.
`pay_online` saat mengirim pesanan ditolak oleh validasi backend: pembayaran online dilakukan setelah pesanan diterima (lihat "Guest online payment").

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
  (`PaymentWebhookController`) menandainya `paid`.
  A failed charge (refused or timed out) shows the gateway's message, or "Gagal menghubungi gateway pembayaran. Coba lagi.":
  nothing is taken, the bill stays payable and the cashier can pick any method.
  Percobaan gateway yang `failed`/`expired` boleh diganti metode lain (tender lamanya diganti).
- Tender gateway tidak boleh digabung (split) dengan tender lain pada tagihan dine-in.
- Selama pembayaran gateway/transfer masih `pending`, membayar lagi ditolak (tidak ada charge kedua, tidak ada tender
  kedua); POS dan Riwayat menampilkan "Lihat QR" ke struk. Pengecualian: tagihan yang `pending` karena charge online tamu
  (tanpa tender) diambil alih kasir (takeover, lihat "Guest online payment").
  Another exception: a gateway tender with no reference after 120 s belongs to a request that died before storing its charge.
  The POS "Belum Lunas" row and Transactions History then offer **Bayar** again, with the same flow, and paying replaces it.

Saat dibayar, dalam satu `DB::transaction` dengan `lockForUpdate()` pada pesanan dan transaksi:

- `payment_method`, `cash`, `change`, `bank_account_id` dan tender dicatat.
- `cashier_id` dan `cashier_shift_id` pindah ke kasir yang menerima uang dan **shift aktifnya**. Kas yang diharapkan shift
  (`expected_cash`) hanya bertambah saat tunai benar-benar `paid`; tagihan belum lunas tidak dihitung sama sekali.
- Stok tidak dipotong lagi (sudah dipotong saat accept).
- Membayar ulang tagihan yang sudah lunas ditolak (`order` error), jadi tidak ada pembayaran ganda.
- Kasir harus punya shift aktif di outlet yang sama dengan transaksi.
- The counter charges what the bill still owes (`Transaction::remainingTotal()`), not the grand total: a return recorded
  while the bill was unpaid refunds nothing and lowers the amount asked (POS, Riwayat Transaksi and the gateway charge
  alike). A fully returned bill is refused.
- A gateway tender takes two steps (`TransactionSettlementService`, also used to settle a POS pay-later bill), so no lock or
  transaction is held during the gateway call. `claim()` writes the payment and commits; `charge()` then calls the gateway
  and stores the reference, URL and QR only while the bill is still pending on that attempt
  (`Transaction::storeChargeResult()`, shared with the guest's online payment). A result that comes too late is dropped and
  logged with its reference. Every gateway claim moves `payment_attempt`, so each charge has the order id `{invoice}-r{n}`
  and a retry never reuses one. A failed charge restores the cashier, shift, method, cash and status of before (`failed` with
  `gateway_error` if a charge was pending), deletes only the tender it created, keeps the attempt and writes the audit event
  `transaction.gateway_charge_failed`. If that charge is paid after all and the bill is still `open_bill`, the webhook
  records the gateway as its payment method, so the shift's non-cash total includes it.
- When a cashier's gateway charge fails (at once, or by the gateway's failure or expiry notification), the payments flagged
  while that charge was pending are checked again (`recheckFlaggedPayments`): the oldest that is still due is booked (bill unpaid, no pending
  or paid tender, the amount equals what is owed, not restocked; audit `payment.late_after_failed` with `rechecked`), and any
  other keeps its refund flag, audited `payment.late_rechecked` with the reason.
- Pesanan `completed` pada saat "Serahkan" karena tagihan sudah `paid`. Jaring pengaman: `Transaction` memanggil
  `DineOrder::completeIfSettled()` saat `payment_status` berubah ke `paid` (jalur apa pun), yang menyelesaikan pesanan `served`.
- Perintah `transactions:expire` tidak menyentuh tagihan dine-in: usia transaksi (dibuat saat accept) tidak berarti ditinggalkan.
- Closing a shift never cancels a dine-in bill: an unpaid one holds the close (see `cashier-shifts.md`).

### Guest online payment (REQ-005)

After staff accept an order, the guest can pay its unpaid bill (Belum Lunas) online from the status page, at an outlet whose Midtrans or
Xendit is ready (Settings → Payment). Paying never happens at submit (`pay_online` stays rejected).

**The pay panel** (`resources/js/Components/Dine/GuestPayPanel.jsx`, texts `dine.pay.*` in id and en) shows the `payment` payload:

| `state` | The guest sees |
|---|---|
| none (`payment` null: not accepted, rejected) | no panel |
| `can_pay` | total, **Bayar sekarang** (QRIS), **Bayar dengan metode lain** (`method=gateway`) |
| `pending` | **Menunggu pembayaran**, total, the QRIS image, **Buka di aplikasi e-wallet** (gateway page, new tab), **Bayar dengan metode lain**; only the link when the gateway gave no QR |
| `failed`, `expired`, `gateway_error` | **Pembayaran gagal**, **Kedaluwarsa** or **Pembayaran online sedang bermasalah — bayar di kasir**, total, **Coba lagi** (the last method); **Atau bayar di kasir** for the first two |
| `counter` | **Silakan bayar di kasir**, no button |
| `paid` | **Lunas**, no button |

- **Pay later** (`payment.pay_later`, REQ-005 AC-05): a table-QR Bayar Belakangan bill is paid through the same panel, charge and webhook, in every status from accepted to served. The panel adds **Belum lunas** until the bill is paid. Paying it records no kitchen item again (its items were recorded at accept; the paid hook skips pay-later bills and the intake is once per sale), takes the bill off the POS Belum Lunas list, clears the kitchen display's Belum lunas mark and completes an order that is already served. A POS pay-later sale has no order token, so a guest cannot reach it.
- It learns the result from the page's 5 s status poll, without a reload. While an order is accepted and unpaid, the page's
  "walk to the counter" text shows only when `payment` is null or `counter`.
- A refused press reads **Belum bisa dibayar**, **Sudah lunas**, **Silakan bayar di kasir**, the gateway text above,
  **Terlalu banyak percobaan. Coba lagi sebentar.** (429) or **Tidak ada koneksi. Pembayaran belum dibuat.**. A second press
  is ignored while a request is in flight.
- Answers can arrive out of order and the poll has no QR, so the page keeps the QR and link of the newest charge it has seen
  (`mergePayment`): nothing blank replaces them for the same attempt, an older attempt is ignored and **Lunas** is never
  undone. A newer attempt drops them at once, because the older charge may still be payable; its own QR and link appear
  once the charge is stored. A charge started elsewhere (another tab) gets its QR by one partial reload of the `payment` prop.
- A pending attempt that still has no page and no QR after 60 s offers **Coba lagi**.

- `POST /dine-order/{accessToken}/pay` is public (the order token is the only credential, no permission, no login).
  It reads only `method` (`qris`, the default, or `gateway` for the gateway's own page with every enabled method); an
  `amount` in the body is never read. The limiter `dine-order-pay` allows 5 requests a minute per order token **and**
  IP (not one bucket per IP, so one cafe Wi-Fi does not block other tables).
- The amount is always `Transaction::remainingTotal()` of the accepted bill, worked out by the server. A bill with
  nothing left (fully returned) is refused without calling the gateway.
- Each press is a new **payment attempt** (`transactions.payment_attempt`, order id `{invoice}-r{n}`): the charge is claimed and
  committed under the bill's row lock, then the gateway is called with **no DB transaction open**, then the result is
  stored only if the bill is still on that attempt. A second press (or a retry after failed/expired) supersedes the
  first. The existing webhook settles the bill and the existing paid hook sends the flagged items to the kitchen, once.
- **The webhook decides under locks** (`PaymentWebhookController::applyNotification`): one short DB transaction, lock order
  owning shift (shared), dine order, receivable (a pay-later sale's gateway payment settles it; the receivable controllers lock it before the bill), bill (`FOR UPDATE`, re-read), no network call inside. The bill's shift is re-checked under
  its lock: if a takeover or retry moved the bill meanwhile, the transaction ends and runs once more with the new shift; a second
  move fails closed (500, nothing booked, the gateway redelivers). `TransactionPaymentRetryService::retry` locks in the same order (shift, then sale).
  For a **paid** notification:
  the current attempt books the bill; a repeat of the booked payment (same gateway reference) is ignored; an older
  attempt, or the failed attempt itself, is a **late payment** and is booked as the payment when the bill is not paid, no **pending or paid** tender holds it
  (`Transaction::hasBlockingTenders()`; a failed or expired tender never held money) and the amount equals `remainingTotal()` (first payment wins; audit `payment.late_after_failed`, `booked: true`),
  otherwise it is flagged (`late_superseded_payment_at`, "Bayar ganda — refund manual", audit `payment.late_superseded` or
  `payment.late_after_failed` with `booked: false`) and never booked. A second payment with another reference on a paid bill is flagged. `late_superseded_payment_at` is only the "needs refund" flag (set once);
  every distinct gateway reference is audited (`meta.reference`, `meta.order_id`), a redelivery of the same one is not.
  A failed bill of attempt 0 takes the late path above when it has a gateway charge of its own (a gateway tender row, a single-method gateway sale, or a
  credit sale with a receivable, #380; any gateway tender in any status counts, a pending or paid one then blocks the booking); one without any stays failed. A bill settled by cash or transfer at attempt 0 flags a later gateway payment as a second payment. A late payment of a sale that was restocked is flagged, not booked.
  When a flagged payment is later booked (the same reference redelivered after the blocking tender failed), `late_superseded_payment_at` is cleared, unless another reference was flagged; the earlier flag stays in the audit trail next to the `booked: true` entry.
  A booked payment of the current attempt that is more than the bill owes after a return completed while it waited is
  flagged too, with the excess in `overpaid_amount` and the audit `payment.overpaid_after_return` (#444, see
  [`pos-transactions.md`](pos-transactions.md)); a transfer confirmation applies the same rule.
- **Cashier takeover**: the cashier can always take the money. `DineOrderService::pay` accepts a bill whose guest charge is
  pending (`Transaction::isAwaitingGuestPayment()`: pending, attempt above 0, no tender rows) and still refuses the cashier's own pending
  charge. Every settlement of a bill with attempts moves the counter (`TransactionSettlementService::claim`), so the guest's charge is
  superseded in the same transaction; invalid input changes nothing. The cashier's own gateway call comes after that commit: if it
  fails, the bill is `failed` / `gateway_error` with no tender, the guest's charge stays superseded, and a guest payment that still
  arrives is booked by the late-payment rule above. The guest's charge is expired at the gateway (next bullet); the refund of a
  second payment that still arrives stays manual.
  Audit `dine_order.online_payment_taken_over`. The cashier's gateway charge uses the order id `{invoice}-r{attempt}`, so its
  settlement is booked.
- **Superseded guest charges are expired.** When a guest charge that is still pending is superseded (a newer press, or a
  cashier takeover, whether or not her own charge then succeeds), `ExpireSupersededGatewayCharge` is queued after the commit,
  so neither request waits for the gateway. A charge still being created is queued when its answer finds it superseded. The job
  asks the gateway to expire it (Midtrans Core API `/v2/{order_id}/expire`, Xendit `/invoices/{id}/expire!`, never Midtrans
  `cancel`), with no lock held. It writes audit rows only (`payment.superseded_charge_expired`,
  `payment.superseded_charge_not_expired`, `payment.superseded_charge_cancel_error`) and never changes the bill: a settlement
  that wins the race follows the late-payment rule above and is audited as not expired (`reason: settled_during_call`).
  A temporary failure is tried 5 times over about 11 minutes; the last try is audited, an unexpected error included. If it
  fails, or no queue worker runs (README, "Queue worker"), the late-payment flag is the safety net. A cashier's own charge is
  never expired. A Midtrans page with no method picked is unknown to the Core API (404): the job then cancels the Snap session
  by the token in the page's URL, and retries any answer it does not know. Not yet verified on a sandbox.
- **Paid after the shift closed**: a payment of any method that settles a bill whose owning shift is closed (or force-closed) sets
  `paid_after_close_at` and audit `payment.paid_after_close`; the closed recap, a stored snapshot, is never changed. It applies when the
  closed recap did not hold the money (`CashierShiftService::countedInClosedRecap`: an unpaid bill (Belum Lunas), a gateway sale unpaid at close,
  an unpaid pay-later sale without a receivable or the unreceived part of a split sale; see `cashier-shifts.md`).
- **What the cashier sees** (REQ-005 AC-06, AC-08, AC-10). The POS unpaid bill (Belum Lunas) section marks a dine-in bill whose
  guest charge is pending **Menunggu pembayaran online** (`awaiting_online`, from `Transaction::isAwaitingGuestPayment()`), in place of
  "Menunggu Pembayaran"; **Bayar** stays, and **Lihat QR** stays only for the cashier's own pending charge. Opening **Bayar** on such a
  bill first shows a notice: ask the guest whether they already paid; taking payment now makes the guest's online payment not count and
  the refund is manual. A bill that received a payment flagged "Bayar ganda — refund manual" (`late_payment`) shows that marker on its row.
  The section refreshes itself every 10 seconds (paused while the tab is hidden, the last list kept when the request fails) from
  `GET /dashboard/transactions/open-bills` (`transactions.open-bills`, permission `transactions-access`, the rows and outlet scope of the
  POS page). Transactions History marks a sale paid after its shift closed with the badge **Dibayar setelah shift ditutup** and has a
  chip with the same name (`paid_after_close=1`); the sales report shows the money received after those shifts closed for the
  period as a line under the summary cards (`summary.paid_after_close_count`, `summary.paid_after_close_total`, and
  `summary.paid_after_close_estimated_count` for older rows counted at their sale total; the web page and
  `/api/v1/reports/sales`), inside the period's totals, by the sale's own date.
- A guest charge never writes `cashier_id` or `cashier_shift_id`: the payment stays in the shift that accepted the order.
- It creates no tender rows. Therefore a **pending bill that has tender rows is the cashier's own** gateway charge or
  transfer, and a bill with money already taken in a split: the guest cannot start a second charge (state `counter`).
- Refusals are 422 `errors.payment[0]`: `not_payable` (not accepted, rejected, nothing left, cashier's charge),
  `already_paid`, `no_gateway`, `gateway_error` (the call failed: the bill is set to `failed` with reason `gateway_error`, so
  the cashier can pay at the counter). 404 for an unknown token, 429 over the limit.
- `transactions.payment_failure_reason` is `expired` when the gateway reported an expiry (Midtrans `expire`, Xendit `EXPIRED`), `gateway_error` when
  the charge could not be created, null otherwise; it lets the page tell Kedaluwarsa from Pembayaran gagal.
- The guest's QRIS and hosted page expire after 15 minutes, and the gateway's finish redirect goes back to the status page
  (not the staff-only receipt).
- `payment` on the status page props and on the `/check` response: `{state, amount, attempt, payment_url, qr, pay_later}`, or `null` while there is nothing to show.
  `state` is `can_pay`, `pending`, `failed`, `expired`, `gateway_error`, `counter` (pay at the counter) or `paid`. `qr` (an SVG data URI) is
  only on the page, never on the poll. It holds no invoice, bill id, customer, outlet or gateway reference.

## Catatan Teknis

- QR generator: `simplesoftwareio/simple-qrcode`
- Status polling: fetch JSON setiap 5 detik (tanpa broadcast/realtime dependency)
- Stok dipotong saat `accept` — bukan saat submit
- Core POS (`CheckoutService`) tidak dimodifikasi; `TransactionController` hanya menambah data Belum Lunas (POS) dan relasi pesanan di Riwayat
- Cart tetap berbasis `cashier_id` — tidak terpengaruh oleh dine-in
