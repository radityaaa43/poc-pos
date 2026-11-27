# 33-kitchen-printer

| Steps | Roles |
|---|---|
| `00-device-settings`: name a print device on the kitchen display, a second device cannot take the same name in the outlet, connect the fake WebUSB printer, a test print is 32 characters wide on 58 mm and 48 on 80 mm, paper size and Cetak otomatis survive a reload, with Cetak otomatis off a sale prints nothing, disconnect (@REQ-004-AC-07); the panel switches to English (@REQ-004-BR-10). `01-auto-print`: a paid dine-in order prints a ticket within 10 seconds with table, type, time, `1 x` item and note, no `Rp` or price, and its card still says "Diterima" (@REQ-004-AC-01, BR-9). `02-pay-later`: a pay-later order prints BELUM LUNAS, a paid one does not (@REQ-004-AC-02). `03-print-once`: after a reload nothing is printed again, and a second station prints the same sale once on its own (@REQ-004-AC-05). `04-print-order`: two orders print in arrival order, none lost (@REQ-004-BR-7). `11-cancel-ticket`: a completed full return prints a DIBATALKAN ticket with the order number, the menu and the whole quantity, no price; a partial return prints the returned quantity and a second partial return prints a second ticket without the first again; a draft return prints nothing (two ticket polls waited out) and a completed one prints once; after a reload nothing is printed again; a print device that never printed the original prints nothing (@REQ-004-AC-04) | cashier, own user (language in `00`) |

Bytes are read from the fake WebUSB printer (`support/webusb.js`), so the real send path runs. The browser's timers, the printer hardware and the USB driver do not.

Manual checks (REQ-004, K1):
- A real printer on Android Chrome over USB-OTG: the ticket prints, is legible, and is cut.
- A real printer on Windows Chrome (WinUSB driver): same; the driver message belongs to K2.
- A visible and a hidden second tab on the cashier PC for ten minutes with Cetak otomatis on: tickets still arrive within 10 seconds, or the hidden tab's throttling is noted (the docs say to use a separate window).
- Two granted printers on one PC (the receipt printer and the kitchen printer): receipts stay on the receipt printer, tickets go to the kitchen printer.
- A DIBATALKAN ticket on a real printer: the cancelled quantity and menu are legible at 58 mm and 80 mm, and it is cut (K3b; the e2e reads decoded text from the fake printer, not paper).
- A backlog after a long outage (more than 30 orders) prints in order, one whole order at a time (covered by `KitchenTicketTest`; never seen on hardware).
