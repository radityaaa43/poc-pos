# 79-payment-webhooks

| Steps | Roles |
|---|---|
| `01-midtrans-settles-portal-sale`: signed settlement marks the portal sale Lunas, expiry then late settlement stays failed, bad signature refused (open balance after payment is `test.fixme`, #125); `02-xendit-settles-portal-sale`: PAID callback with the right token, wrong token refused | guest (gateway calls) |
