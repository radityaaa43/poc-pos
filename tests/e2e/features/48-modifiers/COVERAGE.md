# 48-modifiers

| Steps | Roles |
|---|---|
| `01-groups`: minimum above maximum, maximum below 1 and a required group with minimum 0 are refused with their message, then the group is listed (@REQ-008-AC-01); the page and form read in Indonesian and in English (@REQ-008-AC-20); `02-options`: a negative price, a duplicate name and a required group without default are refused, ticking a second default moves it, an option keeps price, default and recipe on reopen (@REQ-008-AC-02); `03-attach`: two groups attach to a menu, are reordered and keep that order; ticking raw material hides the field (@REQ-008-AC-03); `04-outlet-switch`: an option switched off for a second outlet is left out of that outlet's catalog and kept at the E2E outlet (@REQ-008-AC-04); `05-permission`: a cashier gets 403 and no menu entry (@REQ-008-AC-05) | manager, cashier (05) |

Not in the browser: the server-side rules, 403s, ingredient guard, soft delete, audit, catalog queries and seeder grants (PHPUnit `ModifierGroupTest`); a switched-off option refused at checkout (AC-13, next ticket). Per-role page and sidebar: `role-matrix.expected.json`.

Manual checks: the group form and the product form's Modifier section on a phone-sized screen and with the keyboard only; deleting a group (confirm dialog).
