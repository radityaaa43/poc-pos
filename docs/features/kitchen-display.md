# Kitchen Display

Back to the docs index: `docs/README.md`

## Purpose

One screen for the kitchen: every paid order that still has something to prepare, in one queue, oldest first. The screen itself is read-only (see Kitchen tickets for printing). Dine-in (QR) orders and POS sales land in the same list.

## What it shows

- A card per order: the table name for a dine-in order, the invoice number for a POS sale, the order type (Dine-in, Di tempat, Bawa pulang, Diantar), the minutes since it was accepted, and its lines (quantity, product, status).
- Only lines whose product is flagged `needs_preparation`. In a mixed cart the packaged products are not listed.
- Only paid sales. A sale waiting for a transfer or gateway payment is added when it is paid (see `KitchenIntakeService`). The card has a **Belum lunas** / "Unpaid" chip for a sale that is in the kitchen but not paid yet (reserved for pay-later orders).
- Lines that are served leave the queue.
- Empty queue: "Belum ada pesanan" / "No orders yet". No outlet: "Pilih outlet terlebih dahulu".

## Who sees it

- Page: `/dashboard/kitchen` (route `kitchen.index`), menu entry "Layar Dapur" / "Kitchen display" in the dine-in group.
- Permission: `dine-orders-prepare` **or** `dine-orders-handover`. There is no kitchen permission of its own. Anyone else gets 403 and no menu entry.

## Outlet scope

The screen shows the current outlet only: the outlet of the user's open shift, otherwise the outlet picked in the outlet switcher. Items of other outlets never appear. Order within the queue: the oldest accepted item first.

## Refresh

The page asks the server for the order list every 5 seconds (Inertia `usePoll`, only the `orders` prop), so a newly paid order shows within 10 seconds without reloading. When the browser is offline, or no answer has arrived for 15 seconds, a banner says "Koneksi terputus — mencoba lagi…" / "Connection lost — retrying…" and hides itself on the next successful refresh.

## Recovery job

If the intake step after a payment fails, the payment stays and the error is reported. `kitchen:recover-intake` (every 5 minutes, `routes/console.php`) runs the same idempotent intake for sales paid in the last 30 minutes that have a line needing preparation but no kitchen items. It skips pay-later, cancelled or fully returned sales and sales without a warehouse, never creates a second item for a line, and logs how many sales it recovered and how many failed.

Known limit: the job reads the product flag at the time it runs, so a sale made before a product was flagged "needs preparation" is also sent to the kitchen if it is still inside the 30-minute window. It cannot add anything for an older sale. A sale that keeps failing is reported on every run (every 5 minutes, for the 30 minutes of the window) by design: there is no retry loop and no silent skip.

## Session behaviour

Every refresh is an authenticated request, so an open display keeps the idle session alive. The 12-hour absolute session limit still applies: when the session ends, the next refresh is redirected to the login page, so the screen asks for a sign-in instead of showing an empty queue.

## Kitchen tickets (print station)

A device that has the kitchen display open can print a ticket for every new order on a thermal printer. That device is a **print station**.

- Set it up once per device: **Pengaturan printer dapur** / "Kitchen printer settings" on the display. Give the station a name (unique in the outlet, for example "Dapur 1"), connect the printer, pick 58 mm or 80 mm paper, press **Cetak percobaan** / "Test print" to check it, then switch **Cetak otomatis** / "Auto-print" on. The name is saved on the server; the printer, paper size and the switch are saved in this browser only, so each device is set up on its own.
- A ticket prints once per station: the station asks the server every 5 seconds for orders it has not printed yet, prints them one at a time in arrival order, then tells the server which items it printed. Reloading or reopening the page does not print an order again, and a second tablet with its own station prints the same order once on its own. A station prints only orders that reach the kitchen after it was named, not the queue that is already on the screen.
- A ticket has the table name or invoice number in large type, the order type and the time (`HH:MM`), then `qty x product` per line with its note. There are no prices. A pay-later order says **BELUM LUNAS** / "UNPAID". Ticket labels follow the device's current language.
- Printing never changes the order: it is still "Diterima" / accepted on the screen until the kitchen moves it.
- Needs: Chrome or Edge with WebUSB (a secure `https://` address, or `localhost`), a USB ESC/POS printer, and the page kept open. See `docs/features/thermal-printer.md` for printer setup. Failure warnings, reprint and the Windows driver steps are not part of this first version.
- Routes: `kitchen.stations.store`, `kitchen.stations.tickets`, `kitchen.stations.prints` (same permission as the display). Tables: `kitchen_print_stations`, `kitchen_ticket_prints` (one row per station and item).

## Language

Indonesian and English (`kitchen.*` in `resources/js/i18n/locales/`).

## Central files

- `app/Http/Controllers/Apps/KitchenController.php`
- `app/Models/KitchenItem.php`, `app/Services/KitchenIntakeService.php`, `app/Console/Commands/RecoverKitchenIntakeCommand.php`
- `app/Http/Controllers/Apps/KitchenPrintStationController.php`, `app/Services/KitchenTicketService.php`
- `resources/js/Pages/Dashboard/Kitchen/Index.jsx`, `PrintStationPanel.jsx`, `resources/js/Hooks/useKitchenPrintStation.js`, `resources/js/Utils/printQueue.js`
- E2E: `tests/e2e/features/32-kitchen-display/`, `tests/e2e/features/33-kitchen-printer/`
