# 37-dine-guest-menu

| Steps | Roles |
|---|---|
| `01-guest-places-order`: guest orders without login, status page, stock untouched until accepted; `02-guest-order-validation`: emptied cart, note over 500 characters, self-ordering off, unknown token, stock gone, each with the message the guest reads; `03-self-order-off-is-enforced-by-the-server`: direct POST while self-ordering is off; `04-pay-later-offered-when-on`: Bayar Belakangan button and its helper text only while the outlet setting is on, a tap sends a pay-later order, a direct POST while it is off is refused; `05-pay-later-turned-off-midway`: the setting is switched off while the menu is open, the tap is refused with the reason and Bayar di Kasir still works | guest (no login) |
