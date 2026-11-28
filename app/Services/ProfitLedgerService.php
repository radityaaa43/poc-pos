<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;

/**
 * The profit ledger: one `profits` row per sale line, holding what the line actually earned.
 *
 * A line earns its price after every bill-level discount (manual, redeemed points, voucher; promotions are
 * already net in its price) less the PPN included in that price and less its frozen cost. #570 BR-2, BR-5.
 */
class ProfitLedgerService
{
    public function __construct(private readonly TaxService $taxService) {}

    /** The bill-level discounts a sale carries: manual, redeemed points and voucher. */
    public function billDiscount(Transaction $transaction): int
    {
        return (int) $transaction->discount
            + (int) $transaction->loyalty_discount_total
            + (int) $transaction->customer_voucher_discount;
    }

    /** The PPN a line's price already includes; 0 for an exclusive or untaxed line (#570 BR-5). */
    public function includedPpn(?Product $product, int $price, ?Outlet $outlet): int
    {
        if (! $product || $product->tax_type !== 'inclusive' || $price <= 0) {
            return 0;
        }

        $rate = (float) ($product->tax_rate ?? $this->taxService->getDefaultRate($outlet));

        return (int) $this->taxService->calculateLineItem($price, 'inclusive', $rate)['tax_amount'];
    }

    /**
     * Writes one row per line: the line's price less its share of the bill-level discounts and its included PPN,
     * less its cost. The shares use cumulative rounding, so the rows add up to Σ price − billDiscount − Σ PPN −
     * Σ cost to the rupiah, and no line gets more discount than its price.
     *
     * @param  array<int, array{price: int, cost_total: int, included_ppn?: int}>  $lines
     */
    public function writeRows(Transaction $transaction, array $lines): void
    {
        $billDiscount = $this->billDiscount($transaction);
        $subtotal = array_sum(array_column($lines, 'price'));
        $allocated = 0;
        $cumulative = 0;

        foreach ($lines as $line) {
            $price = (int) $line['price'];
            $cumulative += $price;
            $upTo = $subtotal > 0 ? min($billDiscount, (int) round($billDiscount * $cumulative / $subtotal)) : 0;
            $lineDiscount = $upTo - $allocated;
            $allocated = $upTo;

            $netSellPrice = max(0, $price - $lineDiscount - (int) ($line['included_ppn'] ?? 0));

            $transaction->profits()->create([
                'transaction_id' => $transaction->id,
                'total' => $netSellPrice - (int) $line['cost_total'],
            ]);
        }
    }

    /**
     * Rebuilds a sale's rows from what it stores, for a denied manual discount whose bill is restored (#570 BR-6).
     * shortcut: an inclusive line's PPN comes from its product's current tax settings, which only a tax change in
     * the minutes between the sale and its denial can move; storing the line's share would need a migration.
     */
    public function rebuildFor(Transaction $transaction): void
    {
        $transaction->profits()->delete();

        $outlet = $transaction->warehouse?->outlet;
        $lines = $transaction->details()->with('product')->orderBy('id')->get()
            ->map(fn ($detail) => [
                'price' => (int) $detail->price,
                'cost_total' => (int) $detail->cost_total,
                'included_ppn' => $this->includedPpn($detail->product, (int) $detail->price, $outlet),
            ])->all();

        $this->writeRows($transaction, $lines);
    }
}
