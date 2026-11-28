# PPN Tax Management

Kembali ke indeks dokumentasi: `docs/README.md`

## Tujuan

Dukungan PPN (Pajak Pertambahan Nilai) pada transaksi, dengan mode exclusive/inclusive per produk, NPWP/NIB toko, dan tarif default yang bisa dikonfigurasi.

## Definisi

| Istilah | Arti |
|---------|------|
| Tax Exclusive | Harga produk **belum** termasuk PPN. PPN ditambahkan ke grand total |
| Tax Inclusive | Harga produk **sudah** termasuk PPN. PPN dipisah untuk reporting |
| NPWP | Nomor Pokok Wajib Pajak — identitas pajak toko |
| PPN | Pajak Pertambahan Nilai (default 11%) |

## Fitur Saat Ini

- PPN per produk (exclusive/inclusive), tarif bisa berbeda per produk
- Default tarif PPN bisa diatur di Settings → Profil Toko (default 11.00%)
- Tax calculation di checkout: otomatis menambah PPN ke grand total
- Baris PPN tampil di: checkout preview, print invoice, PDF invoice, PDF receipt 80mm & 58mm, thermal receipt
- Grand total sudah termasuk PPN
- NPWP dan NIB toko di Settings → Profil Toko
- Laporan — PPN sudah termasuk di grand total

## Bill settings (Tagihan) per outlet

REQ-010. Edited in the outlet form (Settings → Outlets → edit, section **Tagihan**) or with `PUT /api/v1/settings/outlets/{outlet}`; both need `outlets-update` and a recent password confirmation. Stored as `settings` rows of that outlet; only `BillSettingsService` reads or writes them.

| Key / request field | Values | Default (no row) |
|---|---|---|
| `service_charge_rate` | 0-100 %, at most 2 decimals; 0 = off | `0` |
| `tax_kind` | `ppn` (per product, as above) or `pb1` (one rate for the whole bill) | `ppn` |
| `pb1_rate` | 0-100 %, at most 2 decimals | `10` |
| `cash_rounding_unit` | `0` (Tanpa), `100`, `500` | `0` |

- An absent or `null` request field is unchanged. Global rows (left behind by a deleted outlet) are ignored.
- The outlet edit form sends a bill field only when it was changed there, so a form opened earlier does not write an old value back.
- Each effective change writes one `outlet.bill_settings_updated` audit row with the changed keys, before and after.
- The outlet list (web and `GET /api/v1/settings/outlets`) returns them as `bill_settings`.
- No total uses them yet (#450). They apply to new bills only; stored sales are never recalculated. `transactions.service_charge`, `tax_kind` (`null` = PPN) and `tax_base` hold what a sale was computed with, and stay `0` / `null` until #450 writes them.

## Database

### Products
- `tax_type` — `exclusive` atau `inclusive`
- `tax_rate` — persentase (decimal 5,2), default 11.00

### Transactions
- `tax_rate` — tarif yang dipakai (nullable)
- `tax_total` — total PPN dalam rupiah
- `customer_npwp` — NPWP customer (opsional)

### Settings
- `store_npwp` — NPWP toko
- `store_nib` — NIB toko
- `tax_default_rate` — tarif default untuk produk baru

## Halaman dan Route

| Route | Fungsi |
|-------|--------|
| `settings.store` | Atur NPWP, NIB, tarif PPN default |

## Alur Perhitungan

### Exclusive (default)
```
Harga produk: Rp 10.000
PPN 11%:      Rp  1.100
Total:        Rp 11.100
```

### Inclusive
```
Harga produk: Rp 11.100 (sudah include PPN)
PPN 11%:      Rp  1.100 (dihitung: 11100 - (11100 / 1.11))
Total:        Rp 10.000 + Rp 1.100
```

## Catatan

- Tax hanya mempengaruhi grand_total, tidak mempengaruhi diskon/voucher/loyalty
- Shipping cost juga dikenakan PPN dengan rate yang sama
- Jika `tax_rate = 0`, PPN tidak dihitung
- Setting NPWP/NIB tidak wajib — bisa dikosongkan
