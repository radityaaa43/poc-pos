<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;

/**
 * The profit ledger: one `profits` row per sale line, holding what the line actually earned.
 *
 * A line earns its price after every bill-level discount (manual, redeemed points, voucher; promotions are
 * already net in its price) less the PPN included in that price and less its frozen cost. #570 BR-2, BR-5.
 * A return appends its own negative row to the same sale; those rows are never rebuilt here.
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

    /**
     * The PPN a line's price already includes; 0 for an exclusive or untaxed line, and 0 at a PB1 outlet,
     * whose bill ignores the product's PPN and takes PB1 on top instead (#570 BR-5).
     */
    public function includedPpn(?Product $product, int $price, ?Outlet $outlet, ?string $taxKind = null): int
    {
        if (! $product || $product->tax_type !== 'inclusive' || $price <= 0 || $taxKind === 'pb1') {
            return 0;
        }

        $rate = (float) ($product->tax_rate ?? $this->taxService->getDefaultRate($outlet));

        return (int) $this->taxService->calculateLineItem($price, 'inclusive', $rate)['tax_amount'];
    }

    /**
     * Writes one row per line: the line's price less its share of the bill-level discounts and its included PPN,
     * less its cost.
     *
     * @param  array<int, array{price: int, cost_total: int, included_ppn?: int}>  $lines
     */
    public function writeRows(Transaction $transaction, array $lines): void
    {
        foreach ($this->rowsFor($transaction, $lines) as $total) {
            $transaction->profits()->create([
                'transaction_id' => $transaction->id,
                'total' => $total,
            ]);
        }
    }

    /**
     * Rebuilds a sale's own rows in place from what it stores, for a denied manual discount whose bill is restored
     * (#570 BR-6). Only the first rows — one per line, written at checkout — are touched: a return's reversal rows
     * were appended after them and stay, and updating in place keeps each row's `created_at`.
     *
     * A row the sale never had (a legacy sale) is created. shortcut: an inclusive line's PPN comes from its product's
     * current tax settings; storing the line's share would need a migration.
     */
    public function rebuildFor(Transaction $transaction): void
    {
        $outlet = $transaction->warehouse?->outlet;
        $details = $transaction->details()->with('product')->orderBy('id')->get();
        $lines = $details->map(fn (TransactionDetail $detail) => [
            'price' => (int) $detail->price,
            'cost_total' => $this->frozenCost($detail),
            'included_ppn' => $this->includedPpn($detail->product, (int) $detail->price, $outlet, $transaction->tax_kind),
        ])->all();

        $own = $transaction->profits()->orderBy('id')->take(count($lines))->get();

        foreach ($this->rowsFor($transaction, $lines) as $index => $total) {
            if ($row = $own->get($index)) {
                $row->update(['total' => $total]);
            } else {
                $transaction->profits()->create(['transaction_id' => $transaction->id, 'total' => $total]);
            }
        }
    }

    /**
     * The frozen HPP of a line; a line from before the cost was frozen falls back to the product's current buy
     * price, as its return margin does.
     */
    private function frozenCost(TransactionDetail $detail): int
    {
        if ($detail->cost_total !== null) {
            return (int) $detail->cost_total;
        }

        $qty = max(1, (int) $detail->qty);

        return (int) round((int) $detail->product->buy_price * (float) ($detail->conversion_factor ?? 1)) * $qty;
    }

    /**
     * One total per line: the line's price less its share of the bill-level discounts and its included PPN, less its
     * cost. The shares use cumulative rounding, so the rows add up to Σ price − billDiscount − Σ PPN − Σ cost to the
     * rupiah, and no line gets more discount than its price.
     *
     * @param  array<int, array{price: int, cost_total: int, included_ppn?: int}>  $lines
     * @return array<int, int>
     */
    private function rowsFor(Transaction $transaction, array $lines): array
    {
        $billDiscount = $this->billDiscount($transaction);
        $subtotal = array_sum(array_column($lines, 'price'));
        $allocated = 0;
        $cumulative = 0;
        $rows = [];

        foreach ($lines as $line) {
            $price = (int) $line['price'];
            $cumulative += $price;
            $upTo = $subtotal > 0 ? min($billDiscount, (int) round($billDiscount * $cumulative / $subtotal)) : 0;
            $lineDiscount = $upTo - $allocated;
            $allocated = $upTo;

            $netSellPrice = max(0, $price - $lineDiscount - (int) ($line['included_ppn'] ?? 0));

            $rows[] = $netSellPrice - (int) $line['cost_total'];
        }

        return $rows;
    }
}
