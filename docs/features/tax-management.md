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
- They apply to new bills only. POS sales use the service charge and the tax type (below); cash rounding changes no total yet.

## How a bill is computed

`BillTotalService` is the only place that adds the service charge and PB1. The POS and API previews, checkout and offline sync all reach it through `LoyaltyService::previewCheckout`; totals sent by a client are ignored.

- *After discount* = subtotal after promo, voucher, points and manual discount, without shipping.
- Service charge = after discount x `service_charge_rate`, only on a dine-in sale (`order_type = in_store`). A request without an order type is not dine-in.
- PB1 outlet: tax = (after discount + service charge) x `pb1_rate`. Product PPN is ignored and delivery shipping is not in the base.
- PPN outlet: PPN per line as before, on the line totals before voucher, points and manual discount. Shipping and the service charge are taxed at the rate of the last line with a rate above 0.
- Total = after discount + shipping + service charge + tax. Each amount is rounded to whole rupiah, half up.

Example, dine-in with a 5 % service charge: 2 x Rp 10,000 of a product with 11 % PPN.

| Outlet tax | Service charge | Tax | Total |
|---|---|---|---|
| PPN | 1,000 | 2,200 + 110 = 2,310 | 23,310 |
| PB1 10 % | 1,000 | 10 % x 21,000 = 2,100 | 23,100 |

A sale stores `service_charge`, the `service_charge_rate` it was computed with (0 when not dine-in), `tax_kind`, `tax_rate` (the PB1 rate, or the effective PPN rate), `tax_total`, `tax_included`, `tax_base` (what the tax was computed on) and `grand_total`. A **tax-inclusive** product's price already carries its PPN: that part is counted in `tax_total` and reported apart in `tax_included`, and it is never added to `grand_total` (#576); an exclusive price, the shipping and the service charge still get their PPN on top. Older sales keep `0` / `null` (`tax_included` 0), read as PPN, and are never recomputed — a sale already charged twice keeps its figures. Every receipt, the invoice, the public page and the sales export take the service charge out of the subtotal and name the tax from `tax_kind`; the PPN a price already includes gets its own line ("Termasuk PPN 11%"), while PPN charged on top keeps the `+` line, and the subtotal equals the items. Loyalty points keep their base (total minus shipping), so the service charge counts like the tax.

Denying a manual discount recomputes `service_charge`, `tax_total`, `tax_base` and `grand_total` on the restored base from the sale's own `tax_kind`, `tax_rate` and `service_charge_rate` (stored at checkout, 0 when not dine-in), never the current settings; PPN on lines and shipping stays. The receivable of a pay-later sale moves by the same amount, and a sale from before the bill rules only gets the discount back.

## Database

### Products
- `tax_type` — `exclusive` atau `inclusive`
- `tax_rate` — persentase (decimal 5,2), default 11.00

### Transactions
- `tax_rate` — tarif yang dipakai (nullable)
- `tax_total` — total PPN dalam rupiah (yang ditambahkan di atas harga plus yang sudah termasuk di dalamnya)
- `tax_included` — bagian PPN yang sudah termasuk dalam harga produk *inclusive*; tidak pernah ditambahkan lagi ke `grand_total`
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
