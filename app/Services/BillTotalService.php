<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;

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
     * The columns of a sale whose manual discount is denied (REQ-010 BR-4), from the kind and rates it was sold
     * with, never today's settings: PPN on the lines and the shipping stands; the service charge and its tax move.
     */
    public function restore(Transaction $sale, int $discount): array
    {
        // A sale from before the bill rules is never recomputed: it only gets the discount back.
        if ($sale->tax_kind === null) {
            return ['grand_total' => (int) $sale->grand_total + $discount];
        }

        $shipping = (int) $sale->shipping_cost;
        // Only the PPN charged on top stands in the grand total; the included part is already inside the prices (#576).
        $addedTax = (int) $sale->tax_total - (int) $sale->tax_included;
        $afterDiscount = (int) $sale->grand_total - $shipping - $sale->service_charge - $addedTax + $discount;
        $service = (int) round($afterDiscount * (float) $sale->service_charge_rate / 100);
        $rate = (float) $sale->tax_rate;

        if ($sale->tax_kind === 'pb1') {
            $taxBase = $afterDiscount + $service;
            $taxTotal = (int) round($taxBase * $rate / 100);
        } else {
            $taxBase = $sale->tax_base + ($rate > 0 ? $service - $sale->service_charge : 0);
            $taxTotal = $sale->tax_total - (int) round($sale->service_charge * $rate / 100) + (int) round($service * $rate / 100);
        }

        return [
            'service_charge' => $service,
            'tax_total' => $taxTotal,
            'tax_included' => (int) $sale->tax_included,
            'tax_base' => $taxBase,
            'grand_total' => $afterDiscount + $shipping + $service + $taxTotal - (int) $sale->tax_included,
        ];
    }

    /**
     * Writes nothing and reads only the outlet's bill settings.
     *
     * A tax-inclusive line already carries its PPN, so it is never added to the grand total; it is still counted in
     * `tax_total` and reported apart as `tax_included` (#576). An exclusive line, the shipping and the service charge
     * get their PPN on top, as before.
     *
     * @param  array<int, array<string, mixed>>  $lines  each with product_id, line_total, tax_rate and tax_type
     * @param  int  $afterDiscount  subtotal after promo, voucher, points and manual discount, without shipping
     */
    public function compute(?Outlet $outlet, array $lines, int $afterDiscount, int $shipping, bool $dineIn): array
    {
        $settings = $this->billSettings->get($outlet);
        $service = $dineIn ? (int) round($afterDiscount * $settings['service_charge_rate'] / 100) : 0;
        $taxAdded = 0;
        $lineTaxAdded = 0;
        $taxIncluded = 0;
        $shippingTax = 0;
        $effectiveRate = 0.0;

        if ($settings['tax_kind'] === 'pb1') {
            // PB1 replaces the product PPN; delivery shipping is outside its base.
            $rate = $settings['pb1_rate'];
            $taxBase = $afterDiscount + $service;
            $taxTotal = (int) round($taxBase * $rate / 100);
            $taxAdded = $taxTotal;
        } else {
            // PPN is charged per line, before voucher, points and manual discount.
            $taxBase = 0;
            foreach ($lines as $line) {
                $lineTotal = (int) ($line['line_total'] ?? 0);
                if (empty($line['product_id']) || $lineTotal <= 0) {
                    continue;
                }
                $lineRate = (float) $line['tax_rate'];
                $lineTax = (int) $this->taxService->calculateLineItem($lineTotal, $line['tax_type'], $lineRate)['tax_amount'];
                $inclusive = $line['tax_type'] === 'inclusive';
                if ($inclusive) {
                    // Already inside the price: reported, never charged again.
                    $taxIncluded += $lineTax;
                } else {
                    $lineTaxAdded += $lineTax;
                }
                if ($lineRate > 0) {
                    $effectiveRate = $lineRate;
                    $taxBase += $inclusive ? $lineTotal - $lineTax : $lineTotal;
                }
            }

            // Shipping and the service charge are taxed at the rate of the last taxed line.
            $shippingTax = (int) round($shipping * $effectiveRate / 100);
            $taxAdded = $lineTaxAdded + $shippingTax + (int) round($service * $effectiveRate / 100);
            $taxTotal = $taxAdded + $taxIncluded;
            $taxBase += $effectiveRate > 0 ? $shipping + $service : 0;
            $rate = $effectiveRate;
        }

        return [
            'service_charge' => $service,
            'service_charge_rate' => $dineIn ? $settings['service_charge_rate'] : 0.0,
            'tax_kind' => $settings['tax_kind'],
            'tax_total' => $taxTotal,
            'tax_included' => $taxIncluded,
            'tax_rate' => $rate ?: null,
            'tax_base' => $taxBase,
            'grand_total' => $afterDiscount + $shipping + $service + $taxAdded,
            // The inputs of this total, for a client that recomputes it (the offline POS, #418): only the added part
            // is added to the subtotal.
            'bill_basis' => [
                'after_discount' => $afterDiscount,
                'shipping' => $shipping,
                'line_tax_total' => $lineTaxAdded + $taxIncluded,
                'line_tax_added' => $lineTaxAdded,
                'line_tax_included' => $taxIncluded,
                'shipping_tax' => $shippingTax,
                'effective_rate' => $effectiveRate,
            ],
        ];
    }
}
