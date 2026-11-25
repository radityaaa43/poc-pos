# Kitchen Display

Back to the docs index: `docs/README.md`

## Purpose

One screen for the kitchen: every paid order that still has something to prepare, in one queue, oldest first. Dine-in (QR) orders and POS sales land in the same list, and the kitchen moves each line forward from here (see Actions). The screen can also print a ticket for each new order (see Kitchen tickets).

## What it shows

- A card per order: the table name for a dine-in order, the invoice number for a POS sale, the order type (Dine-in, Di tempat, Bawa pulang, Diantar), the minutes since it was accepted, and its lines (quantity, product, status).
- Only lines whose product is flagged `needs_preparation`. In a mixed cart the packaged products are not listed.
- Only paid sales. A sale waiting for a transfer or gateway payment is added when it is paid (see `KitchenIntakeService`). The card has a **Belum lunas** / "Unpaid" chip for a sale that is in the kitchen but not paid yet (reserved for pay-later orders).
- Lines that are served leave the queue.
- Empty queue: "Belum ada pesanan" / "No orders yet". No outlet: "Pilih outlet terlebih dahulu".

## Who sees it

- Page: `/dashboard/kitchen` (route `kitchen.index`), menu entry "Layar Dapur" / "Kitchen display" in the dine-in group.
- Permission: `dine-orders-prepare` **or** `dine-orders-handover`. There is no kitchen permission of its own. Anyone else gets 403 and no menu entry.

## Actions

- **Mulai buat** / "Start" on a line moves it `accepted` to `preparing`. **Siap** / "Ready" moves it `preparing` to `ready`. **Semua siap** / "All ready" on a card readies every line that is not ready yet; a line that was never started gets its `preparing` time and user at the same moment.
- Each step records the server time and the user on the line (`preparing_at`/`preparing_by`, `ready_at`/`ready_by`). Steps only go forward; a cancelled line has no buttons.
- Who may: `dine-orders-prepare` (cashier and manager by default, configurable per shop). Without it the buttons are not drawn and a direct POST gets 403. A line of another outlet is refused too.
- First tap wins: when two people tap the same step, the second gets "Sudah diproses oleh {name}" (the server message, Indonesian in both languages) and the card redraws with the real state.
- No offline queue: a tap that cannot reach the server shows "Gagal menyimpan — periksa koneksi, lalu coba lagi" / "Not saved — check the connection and try again" and nothing is replayed later. The next refresh shows the truth.
- A dine-in order's own status follows its lines: the first line `preparing` makes the order `preparing`, all lines `ready` makes it `ready` (with the same time and user), so the guest status page keeps working. The old per-order routes `dine-orders.prepare` / `dine-orders.ready` delegate to the same steps. Serahkan (hand over) is still on the dine-in Orders page.
- [BR-1] A dine-in order whose items are all unflagged never reaches the kitchen: it is served when paid and completes at once (Pay now here; the Pay-later half lands in ticket #279). A mixed order waits only for its flagged items.
- Routes: `kitchen.items.start`, `kitchen.items.ready`, `kitchen.orders.all-ready` (service: `app/Services/KitchenItemService.php`).

## Cancelled items

An item whose sale line was returned (a completed sales return, #241) shows **Dibatalkan**, struck through and without buttons, for 2 minutes after the return (`KitchenItem::onDisplay()`, measured on `updated_at`), then leaves the queue on a later poll. A partial return only lowers the quantity shown. A start or ready tap on a cancelled item answers "Sudah dibatalkan".

## Outlet scope

The screen shows the current outlet only: the outlet of the user's open shift, otherwise the outlet picked in the outlet switcher. Items of other outlets never appear. Order within the queue: the oldest accepted item first.

## Refresh

The page asks the server for the order list every 5 seconds (Inertia `usePoll`, only the `orders` prop), so a newly paid order shows within 10 seconds without reloading. When the browser is offline, or no answer has arrived for 15 seconds, a banner says "Koneksi terputus — mencoba lagi…" / "Connection lost — retrying…" and hides itself on the next successful refresh.

## Recovery job

If the intake step after a payment fails, the payment stays and the error is reported. `kitchen:recover-intake` (every 5 minutes, `routes/console.php`) runs the same idempotent intake for sales paid in the last 30 minutes that have a line needing preparation but no kitchen items. It skips pay-later, cancelled or fully returned sales and sales without a warehouse, never creates a second item for a line, and logs how many sales it recovered and how many failed.

Known limit: the job reads the product flag at the time it runs, so a sale made before a product was flagged "needs preparation" is also sent to the kitchen if it is still inside the 30-minute window. It cannot add anything for an older sale. A sale that keeps failing is reported on every run (every 5 minutes, for the 30 minutes of the window) by design: there is no retry loop and no silent skip.

## Session behaviour

Every refresh is an authenticated request, so an open display keeps the idle session alive. The 12-hour absolute session limit still applies: when the session ends, the next refresh is redirected to the login page, so the screen asks for a sign-in instead of showing an empty queue.

## Kitchen tickets (print device)

A device that has the kitchen display open can print a ticket for every new order on a thermal printer. That device is a **print device**. It is not a kitchen station (see Kitchen stations below): a kitchen station is a place in the kitchen where a product is made, a print device is the tablet or PC that prints.

- Set it up once per device: **Pengaturan printer dapur** / "Kitchen printer settings" on the display. Give the print device a name (**Nama perangkat cetak** / "Print device name", unique in the outlet, for example "Dapur 1"), connect the printer, pick 58 mm or 80 mm paper, press **Cetak percobaan** / "Test print" to check it, then switch **Cetak otomatis** / "Auto-print" on. The name is saved on the server; the printer, paper size and the switch are saved in this browser only, so each device is set up on its own.
- A ticket prints once per print device: the print device asks the server every 5 seconds for orders it has not printed yet, prints them one at a time in arrival order, then tells the server which items it printed. Reloading or reopening the page does not print an order again, and a second tablet with its own print device name prints the same order once on its own. A print device prints only orders that reach the kitchen after it was named, not the queue that is already on the screen.
- Only one tab per device prints: the tab that holds the browser's Web Lock `kitchen-print` runs the loop, and any other tab of the same browser shows "Tiket dicetak dari tab lain di perangkat ini" / "Tickets are printed from another tab on this device" in the panel and waits. When the printing tab closes, a waiting tab takes over on its own, so two open kitchen tabs never print a ticket twice.
- A ticket has the table name or invoice number in large type, the order type and the time (`HH:MM`), then `qty x product` per line with its note. There are no prices. A pay-later order says **BELUM LUNAS** / "UNPAID". Ticket labels follow the device's current language.
- Printing never changes the order: it is still "Diterima" / accepted on the screen until the kitchen moves it.
- Needs: Chrome or Edge with WebUSB (a secure `https://` address, or `localhost`), a USB ESC/POS printer, and the page kept open. See `docs/features/thermal-printer.md` for printer setup. Failure warnings, reprint and the Windows driver steps are not part of this first version.
- Routes: `kitchen.stations.store`, `kitchen.stations.tickets`, `kitchen.stations.prints` (same permission as the display). Tables: `kitchen_print_stations`, `kitchen_ticket_prints` (one row per station and item).

## Language

Indonesian and English (`kitchen.*` in `resources/js/i18n/locales/`).

## Central files

- `app/Http/Controllers/Apps/KitchenController.php`
- `app/Models/KitchenItem.php`, `app/Services/KitchenIntakeService.php`, `app/Services/KitchenItemService.php`, `app/Console/Commands/RecoverKitchenIntakeCommand.php`
- `app/Http/Controllers/Apps/KitchenPrintStationController.php`, `app/Services/KitchenTicketService.php`
- `resources/js/Pages/Dashboard/Kitchen/Index.jsx`, `PrintStationPanel.jsx`, `resources/js/Hooks/useKitchenPrintStation.js`, `resources/js/Utils/printQueue.js`
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
- **Semua siap** (`POST kitchen.orders.all-ready`) takes an optional `station_ids` (array of integers). A filtered display sends its stations, so only their items become ready (`KitchenItemService::allReady($transaction, $user, $stationIds)`); without it every open item of the sale does. An unknown id changes nothing; anything that is not an array of integers is a 422. The dine order still becomes `ready` only when every item of every station is ready. The whole-order buttons on the Dine-in Orders page call it without a scope.
- Payload code: `app/Support/Kitchen/KitchenOrders.php`, `app/Http/Controllers/Apps/KitchenController.php`. Tests: `tests/Feature/Kitchen/KitchenStationDisplayTest.php`; e2e `34-kitchen-stations/05..08`.
