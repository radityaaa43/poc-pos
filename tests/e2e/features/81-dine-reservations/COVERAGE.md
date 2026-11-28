# 81-dine-reservations

| Steps | Roles |
|---|---|
| `01-record-reservation-and-clash`: a reservation is recorded with every field, a second one 90 minutes later on the same table is refused under Jam, exactly 2 hours apart saves, and a change saves (@REQ-009-AC-16); `02-mark-arrived-cancelled-no-show`: Datang, Tidak datang and Batal after the confirmation, an unmarked reservation from 3 days ago stays on today's list, and of two staff marking one the first wins (@REQ-009-AC-18); `03-list-by-date-and-phone`: each date lists its own reservations with status and a `tel:` phone link (@REQ-009-AC-19); `04-no-permission`: a cashier has no menu entry and the page answers 403 (@REQ-009-AC-21) | manager, cashier (04) |

Not in the browser (PHPUnit `DineReservationTest`): the table-row lock, the 2-hour boundary in both directions, cancelled and no-show freeing the slot, invalid input, another outlet's table and reservation (404), a table shared by outlets, 403 on every write route, the phone absent from the model, the audit log, print paths and the guest menu (@REQ-009-AC-19, BR-1), one audit row per create, change and mark (BR-2), the app timezone, and BR-3 (a table with an unmarked reservation cannot be deleted or deactivated on web or API; marked ones survive the delete; `Tables/Index.test.jsx` covers the message on the table page), and an outlet with a reservation refusing deletion. The page and the sidebar entry per role are in `role-matrix.expected.json`. Dipesan on the table list (AC-17) is not built yet: it needs the table status of #426.

Manual checks: the list and the form on a phone-sized screen; the English labels.
