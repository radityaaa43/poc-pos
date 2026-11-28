# Thermal Printer

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Cetak receipt ke printer thermal (ESC/POS protocol) langsung dari browser via WebUSB, atau melalui server-side text generation.

## Fitur Saat Ini

### ThermalPrintService (Server-side)
- Generate teks receipt dalam format monospace
- Support 80mm (48 karakter) dan 58mm (32 karakter)
- Format: header toko, invoice info, item list, subtotal, diskon, PPN, total, pembayaran, footer
- Output: plain text (`generateReceiptText`) dan HTML (`generateReceiptHtml`)

### Thermal Print Route
- `GET /dashboard/documents/transactions/{invoice}/print/thermal` — HTML receipt
- Dipakai sebagai fallback browser-print jika printer ESC/POS tidak tersedia atau gagal

### Printer Settings
- Paper size: 80mm / 58mm
- Auto-print toggle (cetak otomatis setelah transaksi)

## ESC/POS WebUSB (Client-side)

Cetak langsung byte ESC/POS ke printer via WebUSB (tanpa dialog print browser):

- Generator byte: `resources/js/Utils/escpos.js` — `buildReceiptBytes(data, paperSize)` (58mm/80mm, auto cut `GS V`), `drawerKickBytes(pin)` (ESC p untuk buka cash drawer)
- Connector: `requestPrinter()` / `getPrinter()` / `printBytes()` — WebUSB, filter device printer class 7
- Tombol di **Settings > Printer**: Hubungkan Printer, Test Print, Buka Laci (Kick), Putuskan
- Tombol **Thermal** pada halaman struk mencoba ESC/POS terlebih dahulu, lalu membuka dialog print browser jika gagal
- **Auto-print** setelah checkout non-QRIS (jika setting auto-print aktif + printer sudah terhubung + status bukan pending) + drawer kick otomatis untuk pembayaran tunai (`Print.jsx`)
- Perangkat dan endpoint USB disimpan setelah koneksi berhasil agar Test Print dan halaman struk memakai printer yang sama

> **Catatan:** WebUSB hanya tersedia di browser Chromium (Chrome/Edge/Opera) dan butuh gesture pengguna (klik tombol "Hubungkan Printer"). Browser lain, printer yang tidak kompatibel, atau printer yang terputus akan memakai fallback `window.print()` dari tombol Thermal. Auto-print tidak membuka dialog browser secara otomatis.

## Kitchen printer (English)

The kitchen display can print an order ticket on its own printer: see "Kitchen tickets" in `docs/features/kitchen-display.md`. It uses the same WebUSB code as the receipt printer (`resources/js/Utils/escpos.js`), with its own settings.

- The kitchen printer, its paper size and auto-print are saved per browser in `localStorage` (`kitchen.printerKey`, `kitchen.paper`, `kitchen.autoPrint`). They are separate from **Settings > Printer**, so a cashier PC keeps its receipt printer unchanged.
- An add-on order prints as its own ticket marked **TAMBAHAN** / "ADD-ON" under the original order's number: see "Add-on tickets" in `docs/features/kitchen-display.md`.
- The same printer also prints **DIBATALKAN** tickets when a completed return cancels an order it printed (never for a draft return, and only within 12 hours): see "Cancellation tickets" in `docs/features/kitchen-display.md`.
- Connect it from **Pengaturan printer dapur** on the kitchen display, not from Settings > Printer. Test it with **Cetak percobaan**.
- A cashier PC with a receipt printer and a kitchen printer: grant both in Chrome. Receipts and the cash drawer use the printer that is not the kitchen printer; tickets use the one saved as the kitchen printer. Printers are told apart by vendor id, product id and serial number, so use two different models, or two printers with serial numbers.
- Keep the kitchen display in a **separate browser window**, not a background tab: Chrome slows the timers of hidden tabs, so tickets could arrive late. The same goes for a tablet: keep the screen on and the page in front.
- Needs Chrome or Edge on HTTPS (or `localhost`). Pairing the printer needs one tap on **Hubungkan printer** and Chrome's device dialog; it cannot be done for the user.

### Windows: switch the printer to the WinUSB driver

On Windows a USB printer normally runs on Windows' own printer driver, and Chrome cannot open it. When the kitchen display (or **Hubungkan printer**) says **Printer tidak bisa dipakai dari browser — ganti driver ke WinUSB** / "Printer cannot be used from the browser — switch the driver to WinUSB", replace the driver of that printer with WinUSB. The same five steps are shown in the app under **Lihat langkah** / "Show steps", because shop staff cannot open this repository.

1. Download and open [Zadig](https://zadig.akeo.ie/). Choose **Options > List All Devices**.
2. Pick the kitchen printer from the list (check the name and the USB id against the printer; do not pick another device).
3. Choose **WinUSB** as the target driver.
4. Click **Replace Driver** and wait until it says it succeeded.
5. Reload the kitchen display tab and press **Hubungkan printer** again.

- **The cost:** after the swap Windows can no longer print to that printer through its own driver (Notepad, receipts through the print dialog, other programs). Do this for the kitchen printer only, not for a printer that other Windows programs must keep using.
- **To go back:** open Device Manager, find the printer (under "Universal Serial Bus devices", WinUSB), choose **Uninstall device** with "Delete the driver software for this device", then unplug and plug the printer in again: Windows installs its own driver.
- **If the driver is already WinUSB** and the message still shows, another program or browser tab is holding the printer. Close other programs and tabs that use it (a second kitchen display tab, a print spooler, a manufacturer's utility), unplug and plug the printer in again, then reload. The app tells the two cases apart only by guessing from the error (Windows plus a USB "network error"), so it can show the driver message for a printer that is merely busy.
- The message appears only on Windows. On Android, Linux or ChromeOS the same USB error is shown as a normal print failure.

### Limits: paper out, a cable pulled mid-ticket

- **Paper out is not detected.** WebUSB only tells the app whether the printer accepted the bytes, not whether paper came out. With the roll empty or the cover open the send succeeds, no warning shows and the order is marked printed. Staff who see no ticket use **Cetak ulang** on the order's card: it prints the ticket again, marked CETAK ULANG, and records nothing.
- A cable pulled in the middle of a ticket fails the send: the app shows **Printer dapur tidak terhubung** and prints the whole ticket from the start once the printer is plugged back in. The torn stub already on paper is for staff to discard.

## Route

| Route | Method | Fungsi |
|-------|--------|--------|
| `pdf.transactions.thermal` | GET | HTML receipt thermal |
| `settings.printer` | GET | Halaman settings printer |
| `settings.printer.update` | POST | Simpan settings printer |

## Format Receipt (80mm)

```
            TOKO ANDA
        Jl. Contoh No. 123
        Telp: 021-123456
--------------------------------
No: TRX-XXXXXXXXXX
Tgl: 22/06/2026 14:30
Kasir: Arya
Pelanggan: Umum
--------------------------------
Produk A
2x @ 10.000          20.000
Produk B
1x @ 15.000          15.000
--------------------------------
Subtotal             35.000
PPN                   3.850
--------------------------------
TOTAL                38.850
Tunai                50.000
Kembali              11.150
--------------------------------
        Terima kasih
```

## Catatan

- Untuk print via jaringan: gunakan `NetworkPrintConnector` atau `WindowsPrintConnector` (belum ada di codebase)
- Print shift X/Z report juga memakai ThermalPrintService (`generateShiftReportText`)
