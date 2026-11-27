# 33-kitchen-speed-report

| Steps | Roles |
|---|---|
| `01-per-menu-numbers`: counts of orders and portions, medians, averages, target and share within target per menu item, empty period (@REQ-003-AC-15); `02-handover-average`: average time from payment to the last item served over fully served orders (@REQ-003-AC-16); `03-period-and-outlet`: period by accepted day, cancelled and not collected items left out (@REQ-003-AC-26); `04-station-filter`: the Stasiun filter keeps only that station's menus (a Stasiun column per menu), the handover card stays whole-order with its caption, a deleted station keeps its name and stays filterable as "(dihapus)" (@REQ-006-AC-08) | manager |

Not in the browser (PHPUnit `KitchenSpeedReportTest`): BR-1 report permission and outlet scope (a forged outlet is refused), BR-6 an item with a missing step time counts only where both ends exist, the app-timezone day boundaries, REQ-006 an item without a station counted as Dapur, a menu with items at two stations getting a row per station, a forged station id refused and the station filter staying inside the outlet.
