# Arsitektur — Point of Sales

Dokumen ini menjelaskan bagaimana sistem disusun: lapisan-lapisan aplikasi, alur request, alur bisnis utama, model data, keamanan, dan integrasi. Semua klaim di sini diturunkan dari kode (bukan hanya dari `docs/`), dengan rujukan file supaya mudah ditelusuri.

> Versi kode: disinkronkan dengan upstream `aryadwiputra/point-of-sales` branch `main` commit `81a91b8` (2026-11-10, `APP_VERSION=v3.0.3`).

> Ringkasan satu kalimat: **monolit Laravel 13** yang merender UI **React 19 lewat Inertia** (tanpa API terpisah untuk UI web), dengan **service layer** untuk logika bisnis, **multi-outlet** (cabang → gudang), **MySQL** sebagai satu-satunya penyimpanan, **REST API Sanctum** (±216 endpoint) untuk mobile/integrasi/offline, dan satu **microservice Node.js** opsional untuk WhatsApp.

---

## 1. Tech Stack

| Lapisan | Teknologi | Catatan |
|---|---|---|
| Backend | Laravel 13, PHP ^8.3 (CI pakai 8.4) | `composer.json` |
| UI bridge | Inertia.js 3 (`inertiajs/inertia-laravel` + `@inertiajs/react`) | Server-driven SPA: controller me-return `Inertia::render()` |
| Frontend | React 19, Vite 5, Tailwind CSS 3 | Entry: `resources/js/app.jsx` |
| Routing JS | Ziggy (`@routes` di `resources/views/app.blade.php`) | Fungsi `route()` tersedia global di React |
| Auth | Laravel Breeze (session) + Sanctum (token API) | `routes/auth.php`, `routes/api.php` |
| RBAC | Spatie Laravel Permission v8 | ±110 permission string, role `super-admin`, `manager`, `cashier` |
| Database | MySQL (default), SQLite in-memory untuk test | 100 migration |
| Error tracking | sentry/sentry-laravel | Aktif hanya jika `SENTRY_LARAVEL_DSN` diisi |
| Lisensi | AGPL-3.0-only (sebelumnya MIT) | `composer.json`, `LICENSE` |
| Queue / Cache / Session | Driver `database` | Tidak butuh Redis |
| PDF | barryvdh/laravel-dompdf | Invoice, struk 58/80mm, label kirim, piutang/hutang |
| Excel | maatwebsite/excel | Import/export produk, customer, transaksi |
| Barcode/QR | picqer/php-barcode-generator, simplesoftwareio/simple-qrcode, jsbarcode, html5-qrcode | Label barcode, QR meja dine-in, scan kamera |
| Wilayah | laravolt/indonesia | Tabel provinsi → kota → kecamatan → desa |
| API docs | dedoc/scramble | `/docs/api` (UI), `/docs/api.json` (OpenAPI) |
| Payment | Midtrans, Xendit (HTTP langsung, bukan SDK) | `app/Services/Payments/` |
| WhatsApp | Node.js + Express + whatsapp-web.js (Puppeteer) | `whatsapp-service/`, port 3001 |
| i18n | react-i18next + `lang/` Laravel | `id` dan `en` |
| Offline/PWA | Service Worker (`public/sw.js`) + IndexedDB (`idb`) | Antrian transaksi offline |
| UI libs | @tabler/icons-react, chart.js, sweetalert2, react-hot-toast, driver.js (product tour), @headlessui/react | |

---

## 2. Gambaran Tingkat Tinggi

```
                         ┌─────────────────────────────────────────────┐
                         │                Browser (React 19)           │
                         │  Inertia pages · Ziggy route() · i18next    │
                         │  Service Worker (sw.js) · IndexedDB queue   │
                         └───────┬───────────────────────┬─────────────┘
              Inertia XHR (session cookie)               │ axios JSON (/dashboard/transactions/sync)
                                 │                       │
 Mobile app / pihak ketiga ──────┼──── Bearer token ─────┤
                                 ▼                       ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                           Laravel 13 (monolit)                               │
│                                                                              │
│  routes/web.php ── middleware web ── Controllers/Apps|Reports|root ──┐       │
│  routes/auth.php ─ Breeze                                            │       │
│  routes/api.php ── auth:sanctum + abilities ── Controllers/Api ──────┤       │
│                    webhooks (tanpa auth, verifikasi signature)       │       │
│                                                                      ▼       │
│                              app/Services (business logic)                   │
│                                      │                                       │
│                              app/Models (Eloquent, 59 model)                 │
│                                                                              │
│  Queue worker (driver database) · Scheduler (routes/console.php)             │
└───────┬──────────────────────────────┬────────────────────────┬──────────────┘
        │                              │                        │ HTTP
        ▼                              ▼                        ▼
   ┌─────────┐              ┌────────────────────┐     ┌──────────────────────┐
   │  MySQL  │              │ Midtrans / Xendit  │     │ whatsapp-service     │
   │ (semua  │              │ charge/invoice/QRIS│     │ Node :3001           │
   │  data)  │              │ → webhook balik    │     │ whatsapp-web.js      │
   └─────────┘              └────────────────────┘     └──────────────────────┘
```

Poin penting:

- **UI web tidak memakai REST API.** Halaman dashboard & POS dirender oleh controller web via Inertia; form submit memakai `router.post()`/`useForm` Inertia. REST API `/api/v1` ada untuk mobile, integrasi, dan sinkronisasi offline.
- **Satu database** untuk semuanya, termasuk session, cache, dan job queue (`SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`).
- **WhatsApp** dipisah ke proses Node karena butuh Chrome headless; Laravel memanggilnya lewat HTTP (`app/Services/WhatsAppService.php`).
- **Multi-outlet** menjadi dimensi utama data: hampir semua query operasional difilter oleh outlet yang boleh diakses user (`OutletAccessService`), lihat §7.0.

---

## 3. Struktur Direktori

```
app/
├── Console/Commands/      9 command: inventory:reconcile, reorder:generate, crm:sync-segments,
│                          crm:generate-reminders, transactions:expire, seed:demo, audit outlet/legacy,
│                          audit multi-unit inventory
├── Exports/, Imports/     Excel (produk, customer, transaksi)
├── Http/
│   ├── Controllers/
│   │   ├── Apps/          36 controller modul dashboard (POS, inventory, purchasing, CRM, settings, dine-in, outlet)
│   │   ├── Api/           Auth, master data, PosApi, PaymentWebhook
│   │   │   └── V1/        ±34 controller REST untuk hampir semua modul dashboard (memakai ulang controller web)
│   │   ├── Reports/       Sales, Profit, AdvancedSalesInsights
│   │   ├── Auth/          Breeze
│   │   └── *.php          Dashboard, Setup, Document (PDF), PublicPortal, DineMenu/DineOrder (publik),
│   │                      OutletContext, User, Role, Permission, Profile, Language, Tour, Notification, Region
│   ├── Middleware/        10 middleware custom (lihat §6)
│   ├── Requests/          FormRequest untuk shift, opname, retur, user, role, outlet
│   └── Resources/         JsonResource untuk API (Product, Cart, Transaction, TransactionTender, ...)
├── Models/                59 model Eloquent (termasuk Outlet, TransactionTender)
├── Services/              24 service + Payments/ (Manager, Midtrans, Xendit)
├── Support/               BotGuard, ProductionSecurityBaseline, Checkout/ (CheckoutContext, CheckoutResult, CheckoutFingerprint)
└── Providers/             AppServiceProvider (rate limiter API, gate Scramble, cek baseline security)

bootstrap/app.php          Registrasi routing, middleware, alias, dan exception rendering
config/security.php        Konfigurasi keamanan custom (registrasi, bot guard, session, step-up)
database/
├── migrations/            100 migration
└── seeders/               Permission → Role → PaymentSetting → DineInSettings (+ DemoSeeder via `seed:demo`)
resources/
├── js/
│   ├── app.jsx            Bootstrap Inertia, register service worker, i18n, theme
│   ├── Layouts/           POSLayout, DashboardLayout, AuthenticatedLayout, GuestLayout, PublicLayout
│   ├── Pages/             Auth/, Dashboard/** (±100 halaman), Public/, Setup/, Profile/
│   ├── Components/        Dashboard/ (UI kit), POS/ (cart, payment, scanner, numpad), Receipt/, Barcode/
│   ├── Context/           OnlineStatusContext, ThemeSwitcherContext
│   ├── Hooks/             useBarcodeScanner, useTour
│   ├── Utils/             Menu (sidebar), Permission, authorization, escpos (WebUSB), offlineDb, tours
│   └── i18n/locales/      id.json, en.json
└── views/app.blade.php    Satu-satunya Blade root (Inertia)
routes/                    web.php + web/*.php (±246 route dashboard), api.php (±216 route v1), auth.php, console.php (scheduler)
public/sw.js, manifest.json  PWA
whatsapp-service/          Microservice Node.js WhatsApp
tests/                     87 file test (580 test): POS, checkout, outlet scoping, inventory, API V1, security, loyalty, dll.
```

---

## 4. Lapisan Backend

### 4.1 Routing

Ada tiga file route utama yang didaftarkan di [bootstrap/app.php](bootstrap/app.php):

| File | Isi | Proteksi |
|---|---|---|
| [routes/web.php](routes/web.php), [routes/web/](routes/web/) | Landing & halaman publik (`/`, `/fitur`, `/dokumentasi`, `/roadmap`), setup wizard `/setup`, invoice publik `/share/transactions/{invoice}`, portal customer `/portal/...`, menu dine-in `/dine/{token}`, dan **semua route dashboard di bawah prefix `/dashboard`** | `auth`, lalu `permission:<nama>` per route (verifikasi email sudah dinonaktifkan) |
| [routes/auth.php](routes/auth.php) | Login, register, forgot/reset password, confirm password | `guest` / `auth`, `bot.guard`, `registration.enabled`, throttle |
| [routes/api.php](routes/api.php) | `/api/webhooks/{midtrans,xendit}` dan `/api/v1/*` | Webhook: verifikasi signature/token. v1: `auth:sanctum` + `throttle:api` + `abilities:<permission>` per endpoint; POS butuh `abilities:pos-access`; aksi sensitif `api_step_up` |
| [routes/console.php](routes/console.php) | Jadwal: `transactions:expire` tiap jam, `crm:sync-segments` 01:00, `crm:generate-reminders` 01:15, `reorder:generate` 02:00 | — |

Pola otorisasi di `routes/web/*.php` konsisten: `Route::resource(...)->middlewareFor('index', 'permission:x-access')->middlewareFor('store', 'permission:x-create')`. Aksi sensitif ditambah `step_up` (konfirmasi password ulang). Seluruh aksi POS (cart, hold/resume, checkout) ditambah `active_shift`.

### 4.2 Controller

Controller tipis-ke-sedang: validasi request, panggil service/model, lalu `Inertia::render()` atau redirect dengan flash message. Pengecualian yang gemuk:

- [TransactionController.php](app/Http/Controllers/Apps/TransactionController.php) (±930 baris) — halaman POS, cart, hold, riwayat, konfirmasi pembayaran; checkout didelegasikan ke `CheckoutService`.
- [PosApiController.php](app/Http/Controllers/Api/PosApiController.php) (±980 baris) — versi JSON dari alur POS untuk mobile + endpoint sinkronisasi offline; juga memakai `CheckoutService`.
- `Api/V1/*` memanggil ulang method controller web (visibilitasnya dilebarkan untuk itu), lalu membungkus hasilnya sebagai JSON, sehingga aturan bisnis web dan API tidak terduplikasi.

### 4.3 Service Layer (`app/Services`)

Logika domain yang dipakai lebih dari satu tempat ada di sini dan di-inject lewat constructor/method injection.

| Domain | Service | Tanggung jawab |
|---|---|---|
| Checkout | `CheckoutService` | Satu-satunya jalur checkout (web, API, offline sync): lock shift, pricing + loyalty, buat transaksi + tender, potong stok (pivot, komposit, batch FEFO), catat mutasi stok, piutang. Input/output via `Support/Checkout/CheckoutContext` & `CheckoutResult` |
| | `TransactionTenderService` | Normalisasi pembayaran split (cash, bank_transfer, midtrans, xendit, qris), hitung kembalian & status agregat |
| Outlet | `OutletAccessService` | Outlet/gudang yang boleh diakses user, outlet aktif (dari shift atau session `active_outlet_id`), gudang yang boleh berjualan, invariant lifecycle gudang |
| Kasir | `CashierShiftService` | Buka/tutup shift, `requireActiveShiftForUser(lockForUpdate)`, kas masuk/keluar, ringkasan setoran |
| Harga | `PricingService` | Engine promo: `standard_discount`, `qty_break`, `bundle_price`, `buy_x_get_y`; scope `all/walk_in/registered/member`; `previewCart()` |
| | `PriceListService` | Harga khusus per kelompok pelanggan |
| | `TaxService` | PPN per item, `exclusive`/`inclusive`, default dari setting `tax_default_rate` (11%) |
| | `BillTotalService` | Satu-satunya penyusun total tagihan: service charge dan PB1/PPN dari `BillSettingsService`; dipakai preview, checkout, dan offline sync lewat `previewCheckout()` |
| | `UnitConversionService` | Konversi satuan (pcs ↔ box ↔ karton) ke base unit |
| Loyalty | `LoyaltyService` | Tier (regular/silver/gold/platinum), earn/redeem poin, voucher, `previewCheckout()` + `finalizeTransaction()` |
| Inventory | `StockMutationService` | Ledger stok terpusat (`recordMutation`): penjualan, dine-in, stok awal, opname, retur, inbound PO, retur supplier, expire transaksi gateway |
| | `StockTransferService` | Draft → send → receive antar gudang |
| | `BatchService` | Batch/expiry, alokasi FEFO, peringatan kedaluwarsa |
| | `ReorderService` | Produk di bawah reorder point → draft PO |
| Purchasing | `PurchaseOrderService`, `GoodsReceivingService`, `SupplierReturnService` | Rantai PO → penerimaan barang → hutang → retur |
| Finance | `ReceivableService`, `PayableAgingService` | Aging piutang/hutang, statement |
| | `CustomerCreditService` | Batas piutang pelanggan vs pemakaian di semua outlet; `lockedStanding()` mengunci baris pelanggan untuk keputusan kredit |
| CRM | `CustomerSegmentationService`, `CrmAutomationService` | Segmen otomatis/manual, campaign, reminder, share invoice |
| Dine-in | `DineOrderService` | Accept (potong stok, lock row) / reject pesanan meja |
| Integrasi | `Payments/PaymentGatewayManager` (+ `MidtransGateway`, `XenditGateway`), `WhatsAppService`, `ThermalPrintService` | Dispatch gateway & QRIS, HTTP ke WA service, teks ESC/POS |
| Audit | `AuditLogService` | Snapshot before/after untuk perubahan penting |

### 4.4 Model & Pengaturan

- 59 model Eloquent di `app/Models`. Relasi kunci dijelaskan di §8.
- **Pengaturan aplikasi** disimpan sebagai key-value di tabel `settings` lewat [Setting.php](app/Models/Setting.php). Ada dua level: global (`Setting::get/getInt/getBool/set/setMany`) dan per outlet (`getForOutlet/getBoolForOutlet/setForOutlet`, kolom `settings.outlet_id`) untuk profil toko, printer, pajak, dll. Contoh key: `store_name`, `printer_auto_print`, `loyalty_*`, `tax_default_rate`, `app_setup_completed`.
- **Kredensial gateway** ada di tabel `payment_settings` (model `PaymentSetting`), dienkripsi, dan bisa di-scope per outlet (begitu juga rekening bank).

### 4.5 Shared Props Inertia

[HandleInertiaRequests.php](app/Http/Middleware/HandleInertiaRequests.php) menyuntikkan data global ke setiap halaman:

`auth.user`, `auth.permissions`, `auth.super`, `completedTours`, `locale`, notifikasi (stok menipis, batch kedaluwarsa, piutang & hutang jatuh tempo + ringkasan aging), `activeCashierShift`, `outlets` + `outletLocked` (pemilih outlet aktif; terkunci saat ada shift terbuka), `storeProfile`, `printerSettings`, `pendingApprovalCount`, `pendingDineOrdersCount`, `appVersion`, dan `security` (warning baseline, status registrasi, masa berlaku step-up).

Frontend memakai `auth.permissions` untuk menyembunyikan menu/tombol ([Utils/Menu.jsx](resources/js/Utils/Menu.jsx), [Utils/Permission.jsx](resources/js/Utils/Permission.jsx)). Ini hanya UX; otorisasi sebenarnya tetap di middleware `permission`.

### 4.6 Exception Rendering

Diatur terpusat di `bootstrap/app.php`:
- `MissingAbilityException` (Sanctum) → 403 JSON.
- `AuthenticationException` → 401 JSON untuk `api/*`, redirect ke login untuk web.
- `UnauthorizedException` (Spatie) → 403.
- Saat `APP_DEBUG=false`, status 401/403/404/419/429/500/503 dirender sebagai halaman Inertia `Error.jsx`.

---

## 5. Lapisan Frontend

### 5.1 Bootstrap

[resources/js/app.jsx](resources/js/app.jsx) membuat aplikasi Inertia, me-resolve halaman dari `Pages/**`, memasang i18n, theme switcher, dan mendaftarkan service worker `/sw.js`.

### 5.2 Layout

| Layout | Dipakai oleh |
|---|---|
| `POSLayout.jsx` | Layar kasir (`Dashboard/Transactions/Index.jsx`) — layar penuh, navbar ringkas |
| `DashboardLayout.jsx` | Semua halaman admin — sidebar (dari `Utils/Menu.jsx`), navbar, notifikasi, language switcher |
| `AuthenticatedLayout.jsx` | Halaman profil |
| `GuestLayout.jsx` | Login/register/reset password |
| `PublicLayout.jsx` | Menu dine-in publik, status pesanan, invoice publik |

### 5.3 Halaman POS

Halaman kasir disusun dari komponen di `Components/POS/`: `SearchBar` + `BarcodeScanner` (kamera, html5-qrcode) + `useBarcodeScanner` (scanner USB/keyboard wedge), `ProductGrid`, `CartPanel`, `CustomerSelect`/`AddCustomerModal`/`CustomerHistoryPanel`, `PaymentPanel`, `NumpadModal`, `HeldTransactions`. Struk dicetak lewat `Components/Receipt/ThermalReceipt.jsx` atau ESC/POS via WebUSB (`Utils/escpos.js`).

### 5.4 Sidebar & Modul (urutan menu)

Overview → Master Data (kategori, produk, customer, supplier) → Penjualan (transaksi, riwayat, retur, piutang, aging) → Approval diskon → Inventory (opname, mutasi, transfer) → Pengadaan (PO, penerimaan, retur supplier, hutang) → CRM & Harga (member, promo, voucher, segmen, campaign, reminder) → Dine-in (area, meja, pesanan) → Laporan (penjualan, laba, insights) → Operasional (shift kasir, audit log) → User Management → Pengaturan (payment gateway, profil toko, rekening bank, loyalty, target, price list, gudang, satuan, WhatsApp).

### 5.5 i18n, Theme, Tour

- Teks UI via `t()` dari react-i18next; locale disimpan per user (kolom `users.locale`) dan dibaca middleware `SetLocale`. Ganti bahasa: `POST /language/switch`.
- Dark/light mode via `ThemeSwitcherContext`.
- Product tour onboarding via driver.js (`Utils/tours.js`, `Hooks/useTour.js`); status selesai disimpan di `users.completed_tours`.

### 5.6 Offline Mode & PWA

```
Online:   POS ── Inertia POST /dashboard/transactions/store ──► Laravel
Offline:  POS ── payload + client_uuid ──► IndexedDB "pos-offline".pending_transactions
Online lagi: flushPendingTransactions() ── axios POST /dashboard/transactions/sync ──► PosApiController::syncTransactions
             (idempoten berdasarkan transactions.client_uuid → status synced / duplicate / pending_approval / failed)
```

- IndexedDB store: `products`, `customers`, `pricing`, `pending_transactions` ([Utils/offlineDb.js](resources/js/Utils/offlineDb.js)).
- [public/sw.js](public/sw.js) meng-cache aset statis dan mengembalikan `{offline:true}` untuk request API saat jaringan putus.
- Status online dipantau `OnlineStatusContext` (event `online`/`offline`).

---

## 6. Middleware & Pipeline Request

Urutan di grup `web` (ditambahkan setelah middleware bawaan Laravel):

```
SetLocale → SecureHeaders → EnforceAbsoluteSessionLifetime → HandleInertiaRequests → AddLinkHeadersForPreloadedAssets
```

Alias per route:

| Alias | Class | Fungsi |
|---|---|---|
| `permission`, `role`, `role_or_permission` | Spatie | Otorisasi berbasis permission/role |
| `active_shift` | `EnsureActiveCashierShift` | Tolak aksi POS jika kasir belum buka shift |
| `step_up` | `EnsureRecentPasswordConfirmation` | Wajib konfirmasi password dalam 15 menit terakhir (`AUTH_PASSWORD_TIMEOUT=900`) untuk CRUD role/user, payment settings, rekening bank, konfirmasi pembayaran |
| `bot.guard` | `EnsureBotGuard` + `Support/BotGuard` | Honeypot field + token waktu minimal submit (2 detik) di form auth |
| `registration.enabled` | `EnsurePublicRegistrationEnabled` | Registrasi publik mati secara default |
| `setup.notinstalled` | `EnsureNotInstalled` | `/setup` hanya bisa diakses sebelum `app_setup_completed` |
| `abilities` | Sanctum `CheckAbilities` | Ability token API per endpoint (nama ability = nama permission Spatie) |
| `api_step_up` | `EnsureApiStepUp` | Versi API dari `step_up`: token harus baru saja memanggil `POST /api/v1/auth/confirm-password` (disimpan di cache, timeout 900 detik); percobaan tanpa konfirmasi dicatat di audit log. Dipakai untuk konfirmasi pembayaran, tutup shift, CRUD role/user, payment settings, rekening bank, price list, outlet |
| `keep_flash` | `KeepFlashForPolls` | Untuk request latar belakang POS (poll tagihan belum lunas, preview promo, sinkron offline, ringkasan piutang, riwayat pelanggan, status pembayaran di struk): flash (error/sukses) tetap utuh untuk halaman hasil redirect |

Middleware global lain: `SecureHeaders` (header keamanan response), `EnforceAbsoluteSessionLifetime` (logout paksa setelah 12 jam sejak login, `SECURITY_SESSION_ABSOLUTE_LIFETIME_SECONDS=43200`).

### Alur request Inertia (contoh: buka daftar produk)

1. `GET /dashboard/products` → middleware `web` → `auth` → `permission:products-access`.
2. `ProductController@index` query produk (paginate, filter, stok per gudang outlet aktif) → `Inertia::render('Dashboard/Products/Index', [...])`.
3. `HandleInertiaRequests::share()` menambahkan props global.
4. Request pertama: Blade `app.blade.php` + JSON page di `data-page`. Navigasi berikutnya: XHR dengan header `X-Inertia`, server hanya mengirim JSON props.
5. React me-render `Pages/Dashboard/Products/Index.jsx` di dalam `DashboardLayout`.

---

## 7. Alur Bisnis Utama

### 7.0 Model Multi-Outlet

```
Outlet (cabang: code, name, is_active, is_sales_enabled, alamat, logo)
  └── Warehouse (outlet_id) ── product_warehouse.stock
User ──user_outlets──► Outlet   (super-admin: semua outlet)
```

- Outlet `PUSAT` dibuat seeder dan **khusus pusat** (`is_sales_enabled=false`): tidak bisa dipakai berjualan; cabang dibuat lewat wizard atau Settings → Outlet.
- **Outlet aktif** ditentukan `OutletAccessService::activeOutlet()`: outlet dari shift yang sedang terbuka; jika tidak ada, pilihan user di session (`POST /dashboard/outlet`, key `active_outlet_id`); jika tidak ada, outlet pertama yang boleh diakses. Pemilih outlet terkunci selama ada shift terbuka.
- Data yang di-scope per outlet: gudang & operasi stok, laporan, profil toko/printer/pajak (`Setting::getForOutlet`), payment settings & rekening bank, promo/pricing rule/price list, voucher & campaign CRM, target WhatsApp, dine-in, audit log, visibilitas shift. Akses lintas outlet ditolak di controller/service, bukan hanya disembunyikan di UI.
- Shift hanya bisa dibuka di gudang milik outlet yang `is_sales_enabled`.

### 7.1 First-install (Setup Wizard)

`DatabaseSeeder` hanya mengisi data sistem (permission, role, payment setting, dine-in setting) dan membuat outlet + gudang `PUSAT`. **Tidak ada user default.** Wizard `/setup` ([SetupController.php](app/Http/Controllers/SetupController.php)) dalam satu DB transaction membuat: profil toko, jenis usaha + kategori awal (food/retail/grocery/fashion/pharmacy/services), akun admin dengan role `super-admin`, dan **1–10 cabang** (masing-masing outlet + gudang), lalu menandai `app_setup_completed`. Pengunjung pertama otomatis diarahkan ke `/setup`.

### 7.2 Shift Kasir → POS → Checkout

```
Buka shift (pilih gudang, kas awal) ─► cart (cek stok, satuan, harga) ─► hold/resume (opsional)
   ─► pricing preview (promo + price list + pajak + loyalty + voucher)
   ─► checkout ─► struk / QRIS / payment URL ─► tutup shift (rekap setoran)
```

Semua checkout (web `TransactionController::store`, API `PosApiController::checkout`, dan offline sync yang memanggil `checkout()` dengan request sintetis) masuk ke [CheckoutService::execute()](app/Services/CheckoutService.php) dengan sebuah `CheckoutContext`. Semuanya berjalan dalam **satu `DB::transaction`**:

1. Lock shift aktif kasir (`requireActiveShiftForUser(lockForUpdate: true)`); outlet & gudang diambil dari shift.
2. Ambil cart aktif kasir; hitung `PricingService::previewCart()` (dengan konteks outlet) lalu `LoyaltyService::previewCheckout()` (diskon manual, ongkir, redeem poin, voucher, pajak) → `grand_total`.
3. Pembayaran: mode lama (satu metode, validasi tunai ≥ total) atau **split tender** lewat `TransactionTenderService` (beberapa baris cash/bank_transfer/midtrans/xendit/qris; `payment_method='split'` jika lebih dari satu). Tender disimpan di `transaction_tenders`.
4. Buat `transactions` (+ `warehouse_id`, `sync_fingerprint` untuk offline, `payment_status` agregat dari tender).
5. Per item: buat `transaction_details` + `profits` (HPP memperhitungkan konversi satuan; diskon manual dialokasikan proporsional).
6. **Potong stok**: `lockForUpdate()` baris `product_warehouse`, cek ulang stok, decrement pivot **dan** `products.stock`. Produk komposit memotong stok komponennya. Jika produk punya batch, konsumsi batch **FEFO** dan catat `transaction_detail_batch_allocations`. Setiap pengurangan dicatat ke `stock_mutations` via `StockMutationService::recordMutation()`.
7. Hapus cart, `LoyaltyService::finalizeTransaction()` (poin, tier, voucher terpakai).
8. Pay-later → buat `receivables`.
9. Kembalikan `CheckoutResult` (termasuk flag `needsDiscountApproval`).

Setelah commit (di controller):
- Jika diskon manual melebihi batas → transaksi diset `pending_approval` + `discount_approval_logs` (disetujui/ditolak di `/dashboard/discount-approvals`).
- Jika pakai gateway → `PaymentGatewayManager` membuat charge/invoice/QRIS (Midtrans diprioritaskan, Xendit fallback untuk QRIS). Panggilan HTTP eksternal sengaja dilakukan **di luar** DB transaction.
- Transaksi gateway yang macet `pending` lebih dari 24 jam di-expire tiap jam oleh `transactions:expire`, dan stoknya dikembalikan (dengan entri mutasi).

### 7.3 Pembayaran Online & Webhook

```
Checkout (pending) ─► Midtrans/Xendit ─► pelanggan bayar ─► POST /api/webhooks/{midtrans|xendit}
                                                              │
                         Midtrans: sha512(order_id+status_code+gross_amount+server_key) == signature_key
                         Xendit:   hash_equals(callback_token tersimpan, header token)
                                                              ▼
                                         update transactions.payment_status (paid/failed/expired)
```

Transfer bank manual dikonfirmasi lewat `PATCH /dashboard/transactions/{id}/confirm-payment` (butuh `step_up`). Customer juga bisa melunasi piutang online dari portal publik `/portal/receivables/{id}/pay`.

### 7.4 Rantai Pengadaan

```
Reorder point / manual ─► Purchase Order (draft → ordered → partial → completed / cancelled)
   ─► Goods Receiving (input qty + batch/expiry) ─► stok gudang PO bertambah + StockMutation + Payable (hutang)
   ─► Supplier Return (opsional) ─► stok berkurang + koreksi payable
   ─► Pembayaran hutang (PayablePayment) · aging hutang
```

### 7.5 Multi-Warehouse

`product_warehouse.stock` adalah **sumber kebenaran operasional**; `products.stock` adalah agregat global yang harus diubah dalam transaksi DB yang sama. Hampir semua tabel stok/transaksi punya `warehouse_id`. Transfer: draft → **send** (stok asal berkurang) → **receive** (stok tujuan bertambah) atau cancel. Jika agregat menyimpang: `php artisan inventory:reconcile [--fix]`.

### 7.5a Resep (Produk Komposit) & Bahan Baku

Untuk coffee shop/F&B: menu dibuat sebagai produk komposit (`is_composite`) dengan komponen di `composite_product_items` (takaran bilangan bulat, dalam satuan stok bahan, mis. gram/ml). Bahan baku ditandai `products.is_raw_material`.

- **Harga jual**: `Product::compositeSellPrice()` memakai `sell_price` menu bila diisi. Jika 0, harga dihitung dari total harga komponen (perilaku paket/bundle).
- **Stok tersedia**: `Product::stockIn($warehouseId)` menghitung porsi yang bisa dibuat dari bahan paling sedikit di gudang itu. POS hanya menampilkan menu dengan porsi > 0.
- **Penjualan**: `CheckoutService` memotong stok tiap bahan (per gudang, dengan mutasi stok) dan menghitung HPP dari biaya per satuan stok bahan × takaran (`InventoryCostService::unitCost`: rata-rata gudang penjual, lalu rata-rata gudang pusat, lalu `buy_price` referensi).
- **Biaya resep**: resep tidak punya harga beli sendiri (`buy_price` disimpan 0). Form produk, daftar produk, API, dan ekspor menampilkan HPP per porsi yang dihitung dengan aturan yang sama di gudang outlet aktif (`OutletAccessService::costWarehouseId`); form menamai outlet di labelnya.
- **Bahan baku** tidak tampil di POS, scan barcode, API POS, maupun menu dine-in, dan ditolak saat masuk keranjang/checkout. Bahan tetap bisa dibeli lewat PO, di-opname, dan ditransfer seperti produk biasa.
- Stok masih bilangan bulat, sehingga takaran desimal (mis. 18,5 g) tidak didukung. Gunakan satuan terkecil.

### 7.6 Stock Opname & Retur Penjualan

- Opname: pilih gudang → input hitungan fisik per item → **finalize** → selisih dicatat sebagai mutasi penyesuaian.
- Retur penjualan: dari riwayat transaksi → draft → complete → restock (opsional) + kredit customer (`customer_credits`).

### 7.7 Dine-in (QR Menu)

```
Admin: area & meja (floor plan SVG) ─► QR berisi {APP_URL}/dine/{token}
Pelanggan: scan QR ─► pilih menu ─► POST /dine/{token}/order (status submitted) ─► pantau /dine-order/{accessToken}
Staff: /dashboard/dine-orders ─► accept (DineOrderService: lock order, buat Transaction + Profit, potong stok + catat mutasi) / reject (alasan)
```

Aksi pesanan dine-in di-scope ke outlet yang boleh diakses staf.

Pembayaran online untuk dine-in **dinonaktifkan** — hanya `pay_at_counter` yang diterima (`pay_online` → 422).

### 7.8 CRM, Loyalty & WhatsApp

- Segmen otomatis disinkronkan harian (`crm:sync-segments`), segmen manual diatur per customer.
- Campaign (broadcast promo, reminder piutang, share invoice) menghasilkan `customer_campaign_logs`; reminder harian dibuat `crm:generate-reminders`.
- Pengiriman otomatis butuh `wa_enabled=true` + device terhubung (scan QR di Settings → WhatsApp). Laravel memanggil `whatsapp-service` (`/start`, `/status`, `/send`, `/disconnect`) dengan header `X-Service-Token` = `WA_SERVICE_TOKEN`. Service menolak semua request (503) jika token belum diset, dan default hanya listen di `127.0.0.1`. Session WhatsApp disimpan di `whatsapp-service/session/`.

### 7.9 Laporan & Dokumen

Sales report, profit report (dari tabel `profits`), advanced insights (penjualan per jam, performa kasir, repeat customer). Dokumen PDF via dompdf di `DocumentController`: invoice, struk 58/80mm, label pengiriman, piutang, hutang. Invoice publik dibagikan lewat `/share/transactions/{invoice}?token={access_token}` (throttle 10/menit).

---

## 8. Model Data

Dikelompokkan per domain (nama tabel):

| Domain | Tabel utama |
|---|---|
| Identitas & RBAC | `users`, `roles`, `permissions`, `model_has_*`, `role_has_permissions`, `personal_access_tokens`, `sessions` |
| Outlet | `outlets`, `user_outlets`; kolom `outlet_id` di `warehouses`, `settings`, tabel payment, pricing, dine-in, promo/CRM |
| Master | `categories`, `products`, `units`, `product_units`, `product_warehouse`, `warehouses`, `customers`, `suppliers`, wilayah (`provinces`, `cities`, `districts`, `villages`) |
| Penjualan | `carts` (juga menyimpan hold), `transactions`, `transaction_tenders` (split payment), `transaction_details`, `transaction_detail_batch_allocations`, `profits`, `discount_approval_logs` |
| Kasir | `cashier_shifts`, `shift_cash_movements` |
| Inventory | `stock_mutations`, `stock_opnames`(+`_items`), `stock_transfers`(+`_items`), `product_batches`, tabel komposit produk |
| Pengadaan & Hutang | `purchase_orders`(+`_items`), `goods_receivings`(+`_items`), `supplier_returns`(+`_items`), `payables`, `payable_payments` |
| Piutang & Retur | `receivables`, `receivable_payments`, `sales_returns`(+`_items`), `customer_credits` |
| Harga & Promo | `pricing_rules`, `pricing_rule_qty_breaks`, `pricing_rule_bundle_items`, `pricing_rule_buy_get_items`, `price_lists`, `price_list_items` |
| Loyalty & CRM | `loyalty_point_histories`, `customer_vouchers`, `customer_segments`, `customer_segment_memberships`, `customer_campaigns`, `customer_campaign_logs` |
| Dine-in | `dine_areas`, `dining_tables`, `dine_orders`, `dine_order_items` |
| Sistem | `settings`, `payment_settings`, `bank_accounts`, `audit_logs`, `product_notification_reads`, `jobs`, `cache` |

Hub relasi:

```
Outlet ──► Warehouse          ┌──────────── Category
  ▲                            │
User (user_outlets)  Warehouse ◄──product_warehouse(stock)──► Product ──► ProductUnit/Unit, ProductBatch, components (kit), PricingRule
   ▲                                        ▲
   │                                        │
CashierShift ──► Transaction ──► TransactionDetail ──► BatchAllocation
   ▲              │  ├─► TransactionTender
                  │  ├─► Profit
   User(kasir)    │  ├─► Receivable ──► ReceivablePayment
                  │  ├─► SalesReturn ──► SalesReturnItem
                  │  ├─► DiscountApprovalLog
                  │  └─► CustomerCampaignLog
                  └─► Customer ──► LoyaltyPointHistory, CustomerVoucher, CustomerSegment, CustomerCredit
```

Aturan integritas:
- Setiap mutasi stok wajib dibungkus `DB::transaction` dengan `lockForUpdate()` pada baris yang diubah.
- Ubah `product_warehouse.stock` dan `products.stock` bersama-sama, dan catat lewat `StockMutationService::recordMutation()`.
- Transaksi offline dideduplikasi lewat `transactions.client_uuid`, plus `sync_fingerprint` (hash isi payload) untuk mendeteksi UUID yang sama dengan isi berbeda.
- Gudang tidak boleh dihapus/dinonaktifkan jika masih punya riwayat atau shift terbuka (`OutletAccessService::hasWarehouseHistory/hasOpenShift`).

---

## 9. Keamanan & Otorisasi

| Area | Mekanisme |
|---|---|
| Autentikasi web | Session Breeze (verifikasi email dinonaktifkan); registrasi publik off (`AUTH_PUBLIC_REGISTRATION=false`) |
| Anti-bot | Honeypot + timer (`bot.guard`), throttle register 3/10 menit & forgot password 5/10 menit |
| Session | Lifetime absolut 12 jam, `SESSION_SECURE_COOKIE` untuk production |
| Step-up auth | Konfirmasi password ulang untuk aksi sensitif (15 menit): `step_up` di web, `api_step_up` + `POST /api/v1/auth/confirm-password` di API |
| RBAC | ±110 permission; `super-admin` mendapat semua, `manager` dan `cashier` subset; dicek di setiap route dashboard |
| Outlet scoping | User hanya melihat/mengubah data outlet yang di-assign (`user_outlets`); dicek di server lewat `OutletAccessService` |
| API | Sanctum token (kedaluwarsa `SANCTUM_TOKEN_EXPIRATION=1440` menit); ability token = nama permission Spatie yang di-stamp saat login (+ `user:read`); rate limit 120/menit (`API_RATE_LIMIT_PER_MINUTE`) |
| Webhook | Signature SHA-512 (Midtrans) & `hash_equals` callback token (Xendit) |
| Rahasia | Kredensial gateway dienkripsi di DB |
| Header | `SecureHeaders` middleware |
| Baseline production | `ProductionSecurityBaseline` memperingatkan jika `APP_DEBUG` aktif, `APP_URL` bukan HTTPS, atau secure cookie mati (log + banner di UI) |
| Audit | `AuditLogService` mencatat before/after untuk perubahan penting |

---

## 10. REST API (`/api/v1`)

±216 endpoint. Hampir setiap modul dashboard kini punya padanan API.

| Grup | Endpoint (ringkas) | Auth |
|---|---|---|
| Auth | `auth/login`, `auth/register`, `auth/me`, `auth/logout`, `auth/confirm-password` | login/register publik (throttle), sisanya token |
| Master data | `products`, `customers`, `categories`, `warehouses`, `suppliers`, `units` | token + `abilities:{modul}-{aksi}` |
| POS | `pos/shift` (+open/close), `pos/products` (+scan), `pos/cart`, `pos/hold(s)`, `pos/checkout`, `pos/transactions` (+sync, detail) | token + `abilities:pos-access` |
| Penjualan | transactions (riwayat, confirm-payment), sales-returns, receivables, discount-approvals, cashier-shifts | token + ability; aksi sensitif `api_step_up` |
| Inventory & pengadaan | stock-opnames, stock-mutations, stock-transfers, purchase-orders, goods-receivings, supplier-returns, payables | token + ability |
| CRM & harga | members, customer-vouchers, customer-segments, crm-campaigns, crm-reminders, pricing-rules, price-lists | token + ability |
| Dine-in | dine-areas, dine-tables, dine-orders | token + ability |
| Laporan | reports sales / profits / insights, audit-logs | token + ability |
| Admin & setting | users, roles, permissions, outlets, settings (payment, bank accounts, store, printer, loyalty, target, WhatsApp) | token + ability + `api_step_up` untuk perubahan |

Dokumentasi interaktif otomatis: `/docs/api`. Untuk test: `Sanctum::actingAs($user, ['*'])`.

---

## 11. Integrasi Eksternal

| Sistem | Arah | File |
|---|---|---|
| Midtrans | Laravel → charge/QRIS; Midtrans → webhook | `Services/Payments/MidtransGateway.php`, `Api/PaymentWebhookController.php` |
| Xendit | Laravel → invoice/QRIS; Xendit → webhook | `Services/Payments/XenditGateway.php` |
| WhatsApp | Laravel → HTTP `WA_SERVICE_URL` (default `http://localhost:3001`) | `Services/WhatsAppService.php`, `whatsapp-service/server.js` |
| Printer thermal | Browser → WebUSB ESC/POS | `Utils/escpos.js`, `Services/ThermalPrintService.php` |
| Kamera | Browser → html5-qrcode | `Components/POS/BarcodeScanner.jsx` |

Webhook gateway hanya bisa bekerja jika `APP_URL` dapat diakses publik.

---

## 12. Testing & CI/CD

- 88 file test, 591 test (POS & checkout integrity, split payment, outlet scoping, cart authorization, FEFO, komposit, transfer, opname, loyalty concurrency, API V1, webhook, security hardening, setup wizard). Hasil lokal 2026-11-20: 590 lulus, 1 di-skip.
- `phpunit.xml` memaksa SQLite in-memory, queue `sync`, cache/session `array`. Jangan pakai fitur khusus MySQL di query.
- Konvensi: `RefreshDatabase`, seed `PermissionSeeder → RoleSeeder → UserSeeder`, `tax_rate=0` di produk test.
- CI ([.github/workflows/tests.yml](.github/workflows/tests.yml)): push/PR ke `main` → tiga job wajib: Backend (`composer validate`, Pint, PHPUnit di MySQL 8.4), Frontend (Vitest + `npm run build`), E2E (Playwright per fitur di `tests/e2e/features/`). CI tidak men-deploy; deploy ke VPS dilakukan manual (ADR 0001).

---

## 13. Menjalankan Secara Lokal

Everything runs on the host (PHP 8.4, Composer, MySQL 8.4, Node), without containers. Setup and run commands: [`README.md`](README.md) → Local setup.

Tambahkan juga variabel baru dari `.env.example` (mis. `SANCTUM_STATEFUL_DOMAINS`, `SANCTUM_TOKEN_EXPIRATION`, `WA_SERVICE_TOKEN`). SSR Inertia (`INERTIA_SSR_ENABLED=true`) otomatis dilewati selama bundle `bootstrap/ssr` belum di-build.

Lalu buka `http://localhost:8000` (otomatis diarahkan ke `/setup`) untuk membuat toko, cabang, & akun admin. WhatsApp service opsional: `cd whatsapp-service && npm install && npm start`.

Data demo (opsional, sebagai pengganti wizard): `php artisan seed:demo --force`. Perintah ini mereset dan mengisi dataset lengkap (outlet `MAL`/`TKB`/`PUT`, produk, transaksi, purchasing, CRM, dine-in), menandai setup selesai, dan ditolak di environment production. Akun: `arya@gmail.com` (super-admin, semua outlet), `manager@gmail.com` (manager, MAL + TKB), `cashier@gmail.com` (kasir, MAL), semuanya dengan password `password`. Lihat `docs/demo-data.md`.

---

## 14. Catatan Arsitektur (temuan saat review)

Status per upstream `81a91b8`. Temuan dari review awal (snapshot 2026-09-10) yang sudah diperbaiki upstream dicantumkan supaya jelas apa yang berubah.

### Masih terbuka

1. **Offline sync dari web (sudah diperbaiki).** Browser mengirim queue lewat route session `POST /dashboard/transactions/sync` (`transactions.sync`, permission `transactions-access`) yang memakai `PosApiController::syncTransactions`; route `POST /api/v1/pos/transactions/sync` tetap untuk klien Bearer token (mobile). `bootstrap/app.php` sengaja tidak memakai `statefulApi()`.
2. **Stok dipotong sebelum approval diskon.** `needsDiscountApproval` dihitung di `CheckoutService` dan status `pending_approval` diset setelah commit, jadi transaksi yang menunggu approval sudah memotong stok. Jika supervisor menolak, diskon dibatalkan (`grand_total` dinaikkan, status `unpaid`) dan transaksi tetap berlaku. Tidak ada route pembatalan transaksi; stok hanya kembali lewat retur penjualan.
3. **Beban per request.** `HandleInertiaRequests` menjalankan beberapa query notifikasi (stok menipis, batch, piutang, hutang, approval, dine-in), daftar outlet, dan 9× `Setting::get*()` tanpa cache di **setiap** request Inertia.
4. **`package-lock.json` tidak sinkron dengan `package.json`.** Lockfile upstream tidak memuat `driver.js` dan `concurrently`, sehingga `npm ci` gagal (`Missing: concurrently@10.0.7 from lock file`). Job CI build sudah beralih ke `npm install`, tetapi langkah deploy di VPS masih memakai `npm ci`, jadi kemungkinan besar gagal. Lockfile hasil `npm install` di working tree ini sudah benar.

### Sudah diperbaiki upstream

- Logika checkout terduplikasi antara web dan API: kini satu `CheckoutService` dipakai web, API, dan offline sync.
- Jalur API/offline tidak memakai batch FEFO: kini ikut `CheckoutService`.
- Penjualan tidak tercatat di `stock_mutations`: kini checkout, dine-in accept, dan expire transaksi gateway mencatat mutasi.
- WhatsApp service tanpa autentikasi: kini wajib `X-Service-Token` dan default listen di `127.0.0.1`.
- CI tidak menjalankan test: kini menjalankan `php artisan test`.

### Diperbaiki di repo ini (2026-11-20)

- Produk komposit dijual Rp 0 jika komponennya bahan baku, karena harga selalu dihitung dari total harga komponen. Kini `sell_price` menu dipakai bila diisi (§7.5a).
- Menu komposit tidak tampil di grid POS dan tidak bisa di-scan, karena tidak punya stok pivot sendiri. Kini tampil dengan jumlah porsi dari stok bahan.
- Mengubah qty menu komposit di keranjang selalu gagal ("stok tidak mencukupi"), karena stok dibaca dari produk komposit itu sendiri.
- Grid POS menampilkan stok global semua gudang. Kini memakai stok gudang shift aktif.
- Halaman menu QR dine-in (`/dine/{token}`) selalu error 500: query memakai kolom `is_active`/`sort_order` yang tidak ada di `categories`/`products`, dan eager-load `units.unit` ke relasi yang tidak ada.
- Penanda bahan baku (`is_raw_material`) ditambahkan beserta form produk dan estimasi HPP per porsi. Test: `tests/Feature/Products/RecipeProductTest.php`.
