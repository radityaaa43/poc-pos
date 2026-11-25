# 34-kitchen-stations

| Steps | Roles |
|---|---|
| `01-manage-stations`: a station is added, the same name in another case is refused with the field message, then renamed and deleted; Dapur has a rename button and no delete button (@REQ-006-AC-01); `02-product-station`: flagging a product shows the station select, the chosen station is kept on reopen, and a flagged product with no choice shows Dapur (@REQ-006-AC-02); `03-delete-moves-products`: deleting a station in the UI moves its flagged product to Dapur while the kitchen item of a real sale still reports the old station name (@REQ-006-AC-03); `04-release-default`: Dapur is listed as "Bawaan" with no delete button, and a flagged product nobody mapped sells to the kitchen as Dapur (@REQ-006-AC-09, clauses 1 and 2) | manager, cashier (03 and 04 sell as cashier) |

AC-09 clause 3 (every device starts on "Semua stasiun") is owned by #344 (S2): there is no device setting or station filter in this ticket, so its e2e lives there.

Not in the browser (PHPUnit `KitchenStationTest`, `ProductKitchenStationTest`, `KitchenStationReleaseTest`, `KitchenIntakeTest`): BR-1 403 for roles without `products-edit`, duplicates by spaces, renaming Dapur, reusing a deleted station's name, a station deleted by a race, the delete rolling back with its audit row, the audit events (BR-5), and the release migration backfill (the e2e database is built fresh, so legacy rows never exist). The page and the sidebar entry per role are in `role-matrix.expected.json`.

Manual checks: the Settings page and the product form select on a phone-sized screen; the English labels.
