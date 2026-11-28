# 88-force-close-summary

| Steps | Roles |
|---|---|
| `01-monthly-force-close-count` (REQ-003 AC-13, #564): the manager reads one outlet-month's 3 closed, 2 force-closed and 1 force-closed over a hold, opens the over-hold shift from the card, and reads the card in English | manager |

Header-only card: the month bounds follow the app timezone and need a fixed clock, so they live in `tests/Feature/CashierShifts/ForceCloseSummaryTest.php` (`test_month_boundary_follows_the_app_timezone`).
