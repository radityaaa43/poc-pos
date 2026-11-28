# 49-dine-modifiers

| Steps | Roles |
|---|---|
| `01-guest-options-reach-the-kitchen`: the guest picks an option and a note on the QR menu, sends a pay-later order and staff accept it; the kitchen display lists the option and the note (@REQ-008-AC-15, @REQ-008-AC-17); `02-refused-option-keeps-the-cart`: an option switched off after the page opened refuses the order with a message naming the menu and option, and the guest keeps the cart (@REQ-008-AC-16); `03-kitchen-ticket-lists-options`: the printed kitchen ticket lists the chosen option and the note with no prices (@REQ-008-AC-18) | guest (no login), manager, cashier |

Not in the browser (PHPUnit `DineModifierOrderTest`): the guest submit stores sorted option ids, note and the server price;
the accept re-resolves at the latest data, writes `note`, `modifier_total`, snapshot rows and a unit price with the
options; an option gone between submit and accept refuses the accept and leaves the order submitted (BR-3); option recipe
stock is taken at accept in the same warehouse and transaction (BR-2); a legacy line without options accepts writing none;
the kitchen item's note comes from its own detail, falling back to a product+unit match only when that note is null (a pre-deploy sale), and the `KitchenOrders` read model carries
the option names in snapshot order (free options included). Vitest covers the QR picker and cart (`DineMenu`), the
kitchen card (`OrderCard`) and the ticket wrapping at 32/48 characters (`escpos`).

Manual checks: the QR panel and the cart on a phone-sized screen; a DIBATALKAN and a CETAK ULANG ticket and a TAMBAHAN
order on a real 58 mm and 80 mm printer; a long option word wrapped on both paper sizes.
