<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Product;

/**
 * The only place that adds service charge and PB1 to a bill (REQ-010); tests/fixtures/bill-totals.json pins
 * its numbers.
 */
class BillTotalService
{
    public function __construct(
        private readonly BillSettingsService $billSettings,
        private readonly TaxService $taxService,
    ) {}

    /**
     * Gives each priced line the tax rate and type of its product, else the outlet's default rate.
     */
    public function taxLines(array $items, ?Outlet $outlet): array
    {
        $defaultRate = $this->taxService->getDefaultRate($outlet);
        $products = Product::whereIn('id', array_filter(array_column($items, 'product_id')))
            ->get(['id', 'tax_rate', 'tax_type'])
            ->keyBy('id');

        return array_map(function (array $item) use ($products, $defaultRate) {
            $product = $products->get($item['product_id'] ?? null);

            return [
                ...$item,
                'tax_rate' => (float) ($product->tax_rate ?? $defaultRate),
                'tax_type' => $product->tax_type ?? 'exclusive',
            ];
        }, $items);
    }

    /**
     * Writes nothing and reads only the outlet's bill settings.
     *
     * @param  array<int, array<string, mixed>>  $lines  each with product_id, line_total, tax_rate and tax_type
     * @param  int  $afterDiscount  subtotal after promo, voucher, points and manual discount, without shipping
     */
    public function compute(?Outlet $outlet, array $lines, int $afterDiscount, int $shipping, bool $dineIn): array
    {
        $settings = $this->billSettings->get($outlet);
        $service = $dineIn ? (int) round($afterDiscount * $settings['service_charge_rate'] / 100) : 0;
        $lineTax = 0;
        $shippingTax = 0;
        $effectiveRate = 0.0;

        if ($settings['tax_kind'] === 'pb1') {
            // PB1 replaces the product PPN; delivery shipping is outside its base.
            $rate = $settings['pb1_rate'];
            $taxBase = $afterDiscount + $service;
            $taxTotal = (int) round($taxBase * $rate / 100);
        } else {
            // PPN is charged per line, before voucher, points and manual discount.
            $taxBase = 0;
            foreach ($lines as $line) {
                $lineTotal = (int) ($line['line_total'] ?? 0);
                if (empty($line['product_id']) || $lineTotal <= 0) {
                    continue;
                }
                $lineRate = (float) $line['tax_rate'];
                $lineTax += $this->taxService->calculateLineItem($lineTotal, $line['tax_type'], $lineRate)['tax_amount'];
                if ($lineRate > 0) {
                    $effectiveRate = $lineRate;
                    $taxBase += $lineTotal;
                }
            }

            // Shipping and the service charge are taxed at the rate of the last taxed line.
            $shippingTax = (int) round($shipping * $effectiveRate / 100);
            $taxTotal = $lineTax + $shippingTax + (int) round($service * $effectiveRate / 100);
            $taxBase += $effectiveRate > 0 ? $shipping + $service : 0;
            $rate = $effectiveRate;
        }

        return [
            'service_charge' => $service,
            'tax_kind' => $settings['tax_kind'],
            'tax_total' => $taxTotal,
            'tax_rate' => $rate ?: null,
            'tax_base' => $taxBase,
            'grand_total' => $afterDiscount + $shipping + $service + $taxTotal,
            // The inputs of this total, for a client that recomputes it (the offline POS, #418).
            'bill_basis' => [
                'after_discount' => $afterDiscount,
                'shipping' => $shipping,
                'line_tax_total' => $lineTax,
                'shipping_tax' => $shippingTax,
                'effective_rate' => $effectiveRate,
            ],
        ];
    }
}
