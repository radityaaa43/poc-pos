# Kitchen Display

Back to the docs index: `docs/README.md`

## Purpose

One screen for the kitchen: every paid order that still has something to prepare, in one queue, oldest first. It is read-only. Dine-in (QR) orders and POS sales land in the same list.

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

If the intake step after a payment fails, the payment stays and the error is reported. `kitchen:recover-intake` (every 5 minutes, `routes/console.php`) runs the same idempotent intake for sales paid in the last 24 hours that have a line needing preparation but no kitchen items. It skips pay-later, cancelled or fully returned sales and sales without a warehouse, never creates a second item for a line, and logs how many sales it recovered and how many failed.

Known limit: the job reads the product flag at the time it runs, so a sale made before a product was flagged "needs preparation" is also sent to the kitchen if it is still inside the 24-hour window. It cannot add anything for an older sale. A sale that keeps failing is reported on every run (every 5 minutes) by design: there is no retry loop and no silent skip.

## Session behaviour

Every refresh is an authenticated request, so an open display keeps the idle session alive. The 12-hour absolute session limit still applies: when the session ends, the next refresh is redirected to the login page, so the screen asks for a sign-in instead of showing an empty queue.

## Language

Indonesian and English (`kitchen.*` in `resources/js/i18n/locales/`).

## Central files

- `app/Http/Controllers/Apps/KitchenController.php`
- `app/Models/KitchenItem.php`, `app/Services/KitchenIntakeService.php`, `app/Console/Commands/RecoverKitchenIntakeCommand.php`
- `resources/js/Pages/Dashboard/Kitchen/Index.jsx`
- E2E: `tests/e2e/features/32-kitchen-display/`
