# 86-opname-variance

| Steps | Roles |
|---|---|
| `01-variance-by-month` (REQ-011 BR-1): the last 12 months of finalized opnames show the count, the average absolute variance value and the average items off per month, with `-` for an empty month; and (BR-2) the month waste recording started carries the marker, earlier rows say Sebelum and later rows Sesudah | opname viewer |

The step arranges its own outlet, warehouse and user (`opname.variance`), so it never shares opname or waste rows with another spec. Finalized opnames are inserted directly, so every valued month carries the Estimasi fallback label; the spec asserts it.

Not covered here (PHPUnit + Vitest): the stored-cost valuation vs the estimated fallback (TC-12), the 12-month window edge and the Jakarta month edge (TC-2, TC-9), outlet scoping and the 403 without `stock-opnames-access` (TC-5, TC-6), and the empty / no-waste / start-before-window notes (Vitest). `E2E: BR-3 none — same route and middleware as the opname list, covered by 01-role-matrix/04-stock-opname; Indonesian/English parity by locales.test.js and the Vitest case.`

Manual checks: open the opname list as a viewer whose outlet has finalized opnames and a waste record, and confirm the marker month and the estimate label read correctly in Indonesian and English.
