# Kitchen Display

Back to the docs index: `docs/README.md`

## Purpose

One screen for the kitchen: every paid order that still has something to prepare, in one queue, oldest first. Dine-in (QR) orders and POS sales land in the same list, and the kitchen moves each line forward from here (see Actions). The screen can also print a ticket for each new order (see Kitchen tickets).

## What it shows

- A card per order: the table name for a dine-in order, the invoice number for a POS sale, the order type (Dine-in, Di tempat, Bawa pulang, Diantar), the minutes since it was accepted, and its lines (quantity, product, status).
- Only lines whose product was flagged `needs_preparation` when the line was sold (a later flag change does not move a sale already made). In a mixed cart the packaged products are not listed.
- Only paid sales. A sale waiting for a transfer or gateway payment is added when it is paid (see `KitchenIntakeService`). The card has a **Belum lunas** / "Unpaid" chip for a sale that is in the kitchen but not paid yet (reserved for pay-later orders).
- Lines that are served leave the queue.
- Empty queue: "Belum ada pesanan" / "No orders yet". No outlet: "Pilih outlet terlebih dahulu".

## Who sees it

- Page: `/dashboard/kitchen` (route `kitchen.index`), menu entry "Layar Dapur" / "Kitchen display" in the dine-in group.
- Permission: `dine-orders-prepare` **or** `dine-orders-handover`. There is no kitchen permission of its own. Anyone else gets 403 and no menu entry.

## Actions

- **Mulai buat** / "Start" on a line moves it `accepted` to `preparing`. **Siap** / "Ready" moves it `preparing` to `ready`. **Semua siap** / "All ready" on a card readies every line that is not ready yet; a line that was never started gets its `preparing` time and user at the same moment.
- Each step records the server time and the user on the line (`preparing_at`/`preparing_by`, `ready_at`/`ready_by`). Steps only go forward; a cancelled line has no buttons.
- **Serahkan** / "Hand over" on a `ready` line moves it to `served` (time and user in `served_at`/`served_by`). **Serahkan semua** / "Hand over all" on a card serves every `ready` line of the order that the display shows (a display filtered to some stations hands over only those stations' lines, as Semua siap does) and leaves the others alone. Served lines leave the screen; a card with no open line disappears. When all lines are served and the bill is paid, the dine-in order completes (the guest page shows "Selesai"); a pay-later order stays `served` until it is paid.
- Who may: `dine-orders-prepare` for Mulai buat / Siap / Semua siap, `dine-orders-handover` for Serahkan / Serahkan semua (cashier and manager hold both by default, configurable per shop; a role with one sees only its own buttons). Without the permission the buttons are not drawn and a direct POST gets 403. A line of another outlet is refused too.
- First tap wins: when two people tap the same step, the second gets "Sudah diproses oleh {name}" (the server message, Indonesian in both languages) and the card redraws with the real state.
- No offline queue: a tap that cannot reach the server shows "Gagal menyimpan — periksa koneksi, lalu coba lagi" / "Not saved — check the connection and try again" and nothing is replayed later. The next refresh shows the truth.
- A dine-in order's own status follows its lines: the first line `preparing` makes the order `preparing`, all lines `ready` makes it `ready` (with the same time and user), so the guest status page keeps working. The old per-order routes `dine-orders.prepare` / `dine-orders.ready` delegate to the same steps. Serahkan (hand over) is on this screen too, see below.
- [BR-1] A dine-in order whose items are all unflagged never reaches the kitchen: it is served when paid and completes at once (Pay now here; the Pay-later half lands in ticket #279). A mixed order waits only for its flagged items.
- Routes: `kitchen.items.start`, `kitchen.items.ready`, `kitchen.orders.all-ready`, `kitchen.items.hand-over`, `kitchen.orders.hand-over-all` (service: `app/Services/KitchenItemService.php`).

## Cancelled items

An item whose sale line was returned (a completed sales return, #241) shows **Dibatalkan**, struck through and without buttons, for 2 minutes after the return (`KitchenItem::onDisplay()`, measured on `updated_at`), then leaves the queue on a later poll. A partial return only lowers the quantity shown. A start or ready tap on a cancelled item answers "Sudah dibatalkan".

## Outlet scope

The screen shows the current outlet only: the outlet of the user's open shift, otherwise the outlet picked in the outlet switcher. Items of other outlets never appear. Order within the queue: the oldest accepted item first.

## Refresh

The page asks the server for the order list every 5 seconds (Inertia `usePoll`, only the `orders` prop), so a newly paid order shows within 10 seconds without reloading. When the browser is offline, or no answer has arrived for 15 seconds, a banner says "Koneksi terputus — mencoba lagi…" / "Connection lost — retrying…" and hides itself on the next successful refresh.

## Recovery job

If the intake step after a payment fails, the payment stays and the error is reported. `kitchen:recover-intake` (every 5 minutes, `routes/console.php`) runs the same idempotent intake for sales that have a line needing preparation but no kitchen items. It has two parts. **Paid sales:** paid in the last 30 minutes (not pay-later; a pay-later sale reaches the kitchen when it is created). **Pay-later sales** (a POS credit sale, or a QR bill accepted as pay later): created in the last 30 minutes, in any payment state (unpaid, a payment attempt in flight, failed, expired or paid), because the food is owed in every state; the window runs on the creation time, since that is when their intake belongs, and the payment status is ignored, so a bill paid long after the window was already taken in while it was still inside it. Both parts skip fully returned sales and sales without a warehouse, never create a second item for a line, and log how many sales were recovered and how many failed.

Which lines count: each sale line remembers whether its product needed preparation when it was sold (`transaction_details.needs_preparation`, written at checkout and when a dine-in order is accepted), and both the intake and this job read that, so flagging or unflagging a product later changes nothing for sales already made. Known limit: a line written before that column existed has no snapshot and reads the product flag at the time the job runs, so such a sale made before a product was flagged "needs preparation" is also sent to the kitchen if it is still inside the 30-minute window. The job cannot add anything for an older sale. A sale that keeps failing is reported on every run (every 5 minutes, for the 30 minutes of the window) by design: there is no retry loop and no silent skip.

## Session behaviour

Every refresh is an authenticated request, so an open display keeps the idle session alive. The 12-hour absolute session limit still applies: when the session ends, the next refresh is redirected to the login page, so the screen asks for a sign-in instead of showing an empty queue.

## Kitchen tickets (print device)

A device that has the kitchen display open can print a ticket for every new order on a thermal printer. That device is a **print device**. It is not a kitchen station (see Kitchen stations below): a kitchen station is a place in the kitchen where a product is made, a print device is the tablet or PC that prints.

- Set it up once per device: **Pengaturan printer dapur** / "Kitchen printer settings" on the display. Give the print device a name (**Nama perangkat cetak** / "Print device name", unique in the outlet, for example "Dapur 1"), connect the printer, pick 58 mm or 80 mm paper, press **Cetak percobaan** / "Test print" to check it, then switch **Cetak otomatis** / "Auto-print" on. The name is saved on the server; the printer, paper size and the switch are saved in this browser only, so each device is set up on its own.
- A ticket prints once per print device: the print device asks the server every 5 seconds for orders it has not printed yet, prints them one at a time in arrival order, then tells the server which items it printed. Reloading or reopening the page does not print an order again, and a second tablet with its own print device name prints the same order once on its own. A print device prints only orders that reach the kitchen after it was named, not the queue that is already on the screen.
- Only one tab per device prints: the tab that holds the browser's Web Lock `kitchen-print` runs the loop, and any other tab of the same browser shows "Tiket dicetak dari tab lain di perangkat ini" / "Tickets are printed from another tab on this device" in the panel and waits. When the printing tab closes, a waiting tab takes over on its own, so two open kitchen tabs never print a ticket twice.
- A ticket has the table name or invoice number in large type, the order type and the time (`HH:MM`), then `qty x product` per line with its note. There are no prices. A pay-later order says **BELUM LUNAS** / "UNPAID". Ticket labels follow the device's current language.
- Printing never changes the order: it is still "Diterima" / accepted on the screen until the kitchen moves it.
- Needs: Chrome or Edge with WebUSB (a secure `https://` address, or `localhost`), a USB ESC/POS printer, and the page kept open. See `docs/features/thermal-printer.md` for printer setup. Printer failures, reprint and the Windows driver steps are under "Printer problems" below.
- Routes: `kitchen.print-devices.store`, `kitchen.print-devices.tickets`, `kitchen.print-devices.prints` (`/kitchen/print-devices`) (same permission as the display). Tables: `kitchen_print_devices`, `kitchen_ticket_prints` (one row per station and item).

### Cancellation tickets (DIBATALKAN)

When a sales return that cancels a kitchen item is **completed**, every print device that printed that item's ticket prints a second ticket marked **DIBATALKAN** / "CANCELLED" (labels follow the device language).

- **Trigger:** only a completed return (see "Cancelled items"). A draft return, editing a draft or discarding it prints nothing, and neither does a return of an item already served or cancelled. A return completed before this release prints nothing: no cancellation was recorded for it.
- **Content:** the order number or table name in large type under the DIBATALKAN heading, the order type and the cancel time (`HH:MM`), then `qty x product` with its note per line. The quantity is what this return cancelled, so a partial return of 1 of 3 prints `1 x`. No prices. One ticket per return and order, listing every printed line it cancelled.
- **Once per print device:** the print device asks for the same list (`cancellations` next to `tickets`), prints the tickets one at a time after any earlier ticket, then tells the server (`kitchen.print-devices.cancellation-prints`, `/kitchen/print-devices/{id}/cancellation-prints`, same permission as the display). Reloading or losing the connection never prints one twice; a second print device prints its own copy once.
- **Only where the original was printed:** a print device that never printed the item's original ticket prints no cancellation for it. The server also skips a cancellation made more than 30 seconds before that original was recorded (the original already had the lowered quantity), and one older than 12 hours (a device switched off over a weekend does not print Friday's cancellations). Inside the 30-second grace one extra DIBATALKAN ticket may print for a cancel the original already carried; that is accepted.
- **Queued originals are reconciled:** each successful poll drops a ticket still waiting (or failed) in the print queue whose order is no longer offered, and replaces one whose items or quantities changed. An item cancelled while its original was still waiting therefore prints neither ticket.
- **Data:** `kitchen_cancellations` (one immutable row per cancelled line, written in the return's own transaction: item, return, `qty_cancelled`) and `kitchen_cancellation_prints` (one row per print device and cancellation, unique together). `kitchen_ticket_prints` is only read. Nothing here changes stock or the item's status.

### Printer problems

When **Cetak otomatis** is on, the display shows at most one printer warning at the top of the page (one `role="alert"` region), the first that applies in this order. Nothing here hides or filters an order card, and nothing is stored on the server: a failed ticket is only queued in the browser.

| Warning | When | What to do |
|---|---|---|
| **Printer tidak bisa dipakai dari browser — ganti driver ke WinUSB** | On Windows, connecting or re-attaching the printer failed with a USB "network error": the printer is on Windows' own driver. Also shown in the printer settings panel, with **Lihat langkah** (the five WinUSB steps, no link). Not retried by itself | Follow the steps; see "Windows: switch the printer to the WinUSB driver" in `docs/features/thermal-printer.md` |
| **Printer dapur tidak terhubung** | No printer is connected, the saved printer is not found, or the browser reported it unplugged | Plug it in or press **Hubungkan printer**; the next poll prints what waits |
| **Gagal mencetak — coba lagi** | The printer did not accept a ticket (USB transfer failed, or it could not be claimed off Windows). Has a **Coba lagi** button | Fix the printer; the next 5-second poll retries, or press **Coba lagi** |
| **Printer dapur offline atau tab tidur** | An order has waited for its ticket more than 30 seconds (the 10-second promise, one poll and a margin), for example after the tablet slept. Stays 15 seconds after the backlog is printed so it does not flash | Wake the device and keep the page in front; the backlog prints oldest first |
| **Layar bisa tidur — jaga perangkat tetap menyala** | The browser has no Wake Lock, or refused it | Keep the screen on in the device's settings |

- **Belum tercetak** / "Not printed": a chip on the order's card, shown when its ticket failed or is still unprinted after 30 seconds. It goes away when the original ticket prints. An order that is printed shows no chip.
- **One button per card:** a card with the chip has **Coba lagi**, which prints the normal ticket and records it as printed (never marked as a reprint). A card whose ticket **this device printed** has **Cetak ulang** / "Reprint", with Cetak otomatis on or off. **Cetak ulang** prints the whole order again (whatever the station filter shows, without cancelled lines) with **CETAK ULANG** / "REPRINT" in large type above the number, as many times as needed, and records nothing: it makes no request to the server and changes no order. It is disabled while the printer is failing or its own copy is printing, and a failed reprint shows **Gagal mencetak ulang** in the card and is dropped, not retried. Which items the device printed is kept in the browser (`kitchen.printed`, the newest 2000), so an order has no button if another device printed it, if it reached the kitchen before this device was named, or after the browser's data was cleared or the browser became another print device.
- **Sleeping tab and Wake Lock:** while Cetak otomatis is on, the page asks the browser to keep the screen awake and asks again when the tab becomes visible. The browser still stops timers of a hidden tab or a sleeping device, so a device that slept prints nothing meanwhile; on waking, the poll returns the waiting orders, which print oldest first behind the offline-or-asleep warning. Orders are never lost: printing is recorded only after the ticket was sent.
- **Devices without WebUSB** (iPad, Firefox, Safari, or a page not on HTTPS): the printer settings say **Perangkat ini tidak mendukung printer USB** / "This device does not support USB printers", and **Hubungkan printer** and **Cetak otomatis** are disabled. The display itself works as usual and shows no printer warning.
- **Paper out is not detected** (WebUSB cannot report it): use **Cetak ulang**. See `docs/features/thermal-printer.md`.
- **For the owner:** orders that never got a ticket are the kitchen items with no `kitchen_ticket_prints` row older than a few minutes; there is no report for it.
- Code: `resources/js/Utils/printerStatus.js` (error codes and the one status), `resources/js/Components/Kitchen/PrinterStatusBanner.jsx`, `resources/js/Hooks/useWakeLock.js`.

## Language

Indonesian and English (`kitchen.*` in `resources/js/i18n/locales/`).

## Central files

- `app/Http/Controllers/Apps/KitchenController.php`
- `app/Models/KitchenItem.php`, `app/Services/KitchenIntakeService.php`, `app/Services/KitchenItemService.php`, `app/Console/Commands/RecoverKitchenIntakeCommand.php`
- `app/Http/Controllers/Apps/KitchenPrintDeviceController.php`, `app/Services/KitchenTicketService.php`
- `resources/js/Pages/Dashboard/Kitchen/Index.jsx`, `PrintDevicePanel.jsx`, `resources/js/Hooks/useKitchenPrintDevice.js`, `resources/js/Utils/printQueue.js`
- E2E: `tests/e2e/features/32-kitchen-display/`, `tests/e2e/features/33-kitchen-printer/`

## Kitchen stations

A kitchen station is a named place in the kitchen (Dapur, Bar, Panggang). Each product that needs preparation goes to one station; the kitchen display can be set to stations per device (see "Station filter on the display" below).

- Page: Settings → "Stasiun dapur" (`/dashboard/settings/kitchen-stations`), permission `products-edit` (403 and no menu entry without it). Stations are shop-wide, not per outlet.
- **Dapur** is the default station, created by the migration. It can be renamed but not deleted ("Bawaan" badge, no delete button; the server answers 422). Names are unique, ignoring case; a duplicate is refused under the field.
- Product form: once "Perlu dibuat di dapur" is ticked, a "Stasiun dapur" select appears with Dapur preselected. Dapur is stored as no station (`products.kitchen_station_id = null`), so a product nobody mapped and a product on Dapur read the same. Unticking the flag clears the station.
- At intake (`KitchenIntakeService`) each kitchen item copies its product's station into `kitchen_items.kitchen_station_id`; a product with no station, or whose station was deleted, gives Dapur. Moving the product later does not change items already in the kitchen.
- Deleting a station is a soft delete in one transaction: its products go back to Dapur, its kitchen items are untouched and still show its name (`KitchenItem::kitchenStation()` uses `withTrashed()`). A deleted station's name can be used again.
- Audit log events (module `kitchen_stations`): `kitchen_station.created`, `.updated`, `.deleted` (with the number of products moved).
- Files: `app/Http/Controllers/Apps/KitchenStationController.php`, `app/Services/KitchenStationService.php`, `app/Models/KitchenStation.php`, `resources/js/Pages/Dashboard/Settings/KitchenStations.jsx`. E2E: `tests/e2e/features/34-kitchen-stations/`.

### Station filter on the display

- The server sends every open item of the outlet; the browser filters. Each item in `orders[].items[]` carries `station_id` and `station` (the name, also for a station deleted since: `withTrashed()`), and the page gets a `stations` prop: active stations, Dapur first, then by name. The 5 s poll reloads `orders` and `stations`.
- **Semua stasiun** is the default on every device: whole orders, the station name on each item, and "x dari y siap" per order (only when the shop has two or more stations). Tapping station chips shows only those stations' items and hides orders with none. The choice lives in the browser (`localStorage` key `kitchenStations`), so it is per device; it is never sent to the server. A saved station that was deleted falls back to Semua stasiun with a notice.
- An item of a deleted station is shown as Dapur's: it appears on a device set to Dapur, not on one set to another station, and Semua stasiun still labels it with the old name.
- **Siap diambil** / "Ready for pickup" (#361): on a filtered display, an order whose shown items are all `ready` leaves the working grid for this section below it; one item still to make (or a just-cancelled one) keeps it above. The heading counts them; the 10 waiting longest since ready (latest `orders[].items[].ready_at`, else the order's `since`) show first, and **Lihat semua (n)** / **Tampilkan lebih sedikit** expands and collapses the list, a choice the 5 s poll keeps until reload. Semua stasiun has no such section.
- **Semua siap** (`POST kitchen.orders.all-ready`) takes an optional `station_ids` (array of integers). A filtered display sends its stations, so only their items become ready (`KitchenItemService::allReady($transaction, $user, $stationIds)`); without it every open item of the sale does. An unknown id changes nothing; anything that is not an array of integers is a 422. The dine order still becomes `ready` only when every item of every station is ready. The whole-order buttons on the Dine-in Orders page call it without a scope.
- **Serahkan semua** (`POST kitchen.orders.hand-over-all`) takes the same optional `station_ids` and applies the same rule through one shared scope (`KitchenItem::scopeInStations`): the stations asked for, and, when the default station Dapur is among them, also items with no station or whose station was removed. Only `ready` items are touched; cancelled items never.
- Payload code: `app/Support/Kitchen/KitchenOrders.php`, `app/Http/Controllers/Apps/KitchenController.php`. Tests: `tests/Feature/Kitchen/KitchenStationDisplayTest.php`; e2e `34-kitchen-stations/05..08`.
