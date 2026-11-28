# 33-kitchen-unprinted-report

| Steps | Roles |
|---|---|
| `01-never-printed`: a ticket due on a past day with no print record is listed with the order number, station, print device and time, with its day count; a period with every ticket printed says so (@REQ-004-AC-08). `02-coba-lagi-clears`: printing the ticket later through the prints endpoint (Coba lagi) removes it from the list (@REQ-004-AC-08) | manager |

Not in the browser (PHPUnit `KitchenUnprintedReportTest`): BR-2 Cetak ulang records nothing and an unprinted ticket is not hidden by it; the 30-second grace; a ticket due before the first print device, or an outlet with no print device, lists nothing; a cancelled item is left out and a served unprinted ticket stays; accepted_at wins over created_at (the fallback meets only legacy rows); one row per order and station, Dapur for a null station, an add-on under its original number; the app-timezone day boundaries; another outlet's tickets and devices never appear and a forged outlet is refused; the 500-row cap with exact counts; the reports-access 403.
