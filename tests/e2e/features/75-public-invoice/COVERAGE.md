# 75-public-invoice

| Steps | Roles |
|---|---|
| `01-open-without-login`: guest opens the invoice PDF by token (cache header is `test.fixme`, #113); `02-wrong-token-refused`: wrong, missing, foreign token and unknown invoice give 404; `03-rate-limit`: 11th request in a minute gives 429; `04-staff-share-links`: history page offers the WhatsApp link (link with token is `test.fixme`, #112) | guest, cashier |
