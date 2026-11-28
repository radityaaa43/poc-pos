# API & Integrasi

API v1 menyediakan akses token-based untuk aplikasi mobile, perangkat kasir, dan integrasi eksternal.

## Authentication

- Login dan register tersedia di `/api/v1/auth/*`.
- Endpoint terlindungi memakai Sanctum Bearer token.
- Token login membawa abilities dari permission user.
- Endpoint master data memeriksa ability yang sesuai.

## Endpoint Utama

- Master data: products, customers, categories, warehouses, suppliers.
- POS: shift, product scan, cart, hold/resume, checkout, transactions.
- Offline sync: `POST /api/v1/pos/transactions/sync`.
- Payment webhooks: `/api/webhooks/midtrans` dan `/api/webhooks/xendit`.

## Penolakan Piutang

`POST /api/v1/pos/checkout` dengan `payment_method: pay_later` menjawab `422` bila pelanggan berbatas piutang ditolak:

```json
{ "success": false, "message": "Melebihi batas piutang — sisa Rp 200.000",
  "errors": { "code": "credit_limit_exceeded", "remaining_limit": 200000 } }
```

`errors.code` bernilai `credit_limit_exceeded` (total melewati sisa batas) atau `credit_overdue` (ada piutang lewat
jatuh tempo; `message`: "Lunasi piutang yang lewat jatuh tempo dulu"). `remaining_limit` adalah sisa batas dalam
rupiah bulat. Pelanggan tanpa batas tidak pernah ditolak. Tidak ada penjualan yang tersimpan: terima pembayaran
sekarang. Persetujuan pengecualian hanya lewat web.

## Dokumentasi API

Pada instalasi aktif, dokumentasi Scramble tersedia di `/docs/api` dan OpenAPI JSON di `/docs/api.json`.
Akses dokumentasi produksi dapat dilindungi dengan `SCRAMBLE_DOCS_TOKEN`.
