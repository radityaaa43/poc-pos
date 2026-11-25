# Kitchen Display

Back to the docs index: `docs/README.md`

## Purpose

One screen for the kitchen: every paid order that still has something to prepare, in one queue, oldest first. Dine-in (QR) orders and POS sales land in the same list, and the kitchen moves each line forward from here (see Actions).

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

## Outlet scope

The screen shows the current outlet only: the outlet of the user's open shift, otherwise the outlet picked in the outlet switcher. Items of other outlets never appear. Order within the queue: the oldest accepted item first.

## Refresh

The page asks the server for the order list every 5 seconds (Inertia `usePoll`, only the `orders` prop), so a newly paid order shows within 10 seconds without reloading. When the browser is offline, or no answer has arrived for 15 seconds, a banner says "Koneksi terputus — mencoba lagi…" / "Connection lost — retrying…" and hides itself on the next successful refresh.

## Session behaviour

Every refresh is an authenticated request, so an open display keeps the idle session alive. The 12-hour absolute session limit still applies: when the session ends, the next refresh is redirected to the login page, so the screen asks for a sign-in instead of showing an empty queue.

## Language

Indonesian and English (`kitchen.*` in `resources/js/i18n/locales/`).

## Central files

- `app/Http/Controllers/Apps/KitchenController.php`
- `app/Models/KitchenItem.php`, `app/Services/KitchenIntakeService.php`, `app/Services/KitchenItemService.php`
- `resources/js/Pages/Dashboard/Kitchen/Index.jsx`
- E2E: `tests/e2e/features/32-kitchen-display/`
