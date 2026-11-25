# 33-kitchen-speed-report

| Steps | Roles |
|---|---|
| `01-per-menu-numbers`: counts of orders and portions, medians, averages, target and share within target per menu item, empty period (@REQ-003-AC-15); `02-handover-average`: average time from payment to the last item served over fully served orders (@REQ-003-AC-16); `03-period-and-outlet`: period by accepted day, cancelled and not collected items left out (@REQ-003-AC-26) | manager |

Not in the browser (PHPUnit `KitchenSpeedReportTest`): BR-1 report permission and outlet scope (a forged outlet is refused), BR-6 an item with a missing step time counts only where both ends exist, the app-timezone day boundaries.
