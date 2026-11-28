<?php

namespace App\Services;

use App\Models\SalesReturn;
use App\Models\Transaction;
use Illuminate\Support\Collection;

/**
 * What a return gives back of a sale's service charge and tax, in proportion to the item value returned (REQ-010
 * AC-10). Cumulative integer rounding: the shares of all returns of a sale add up to what it charged, and the last
 * return takes the remainder. Cash rounding is never part of it, and no return refunds more than the sale took.
 */
class ReturnBillShareService
{
    /**
     * The sale's figures a return shares out, plus the item value and the full total its completed returns already
     * gave back (excluding $exceptReturnId), and what the sale actually took.
     *
     * @return array{base:int, service_charge:int, refundable_tax:int, returned_value:int, returned_total:int, paid:int}
     */
    public function billShare(Transaction $sale, ?int $exceptReturnId = null, bool $lock = false): array
    {
        // The sum of the line totals: the base PPN is charged on, and what a share is proportional to.
        $base = (int) $sale->details()->sum('price');
        // A pre-release sale (tax_kind null) is treated as PPN with no service charge (BR-2/A1).
        $service = $sale->tax_kind === null ? 0 : max(0, (int) $sale->service_charge);

        $completed = $this->completedReturns($sale, $exceptReturnId, $lock);

        return [
            'base' => $base,
            'service_charge' => $service,
            'refundable_tax' => $this->refundableTax($sale),
            'returned_value' => min($base, max(0, (int) $completed->sum(
                fn (SalesReturn $return) => (int) $return->total_return_amount
                    - (int) $return->service_refunded
                    - (int) $return->tax_refunded
            ))),
            'returned_total' => (int) $completed->sum(fn (SalesReturn $return) => (int) $return->total_return_amount),
            // What the sale actually took: shipping and its tax are never refunded.
            'paid' => max(0, (int) $sale->grand_total - (int) $sale->shipping_cost - $this->shippingTax($sale)),
        ];
    }

    /**
     * The service charge and tax to refund for returning $itemValue more of $sale, over what its completed returns
     * already took. Integer cumulative rounding, so successive returns never over- or under-refund.
     *
     * @return array{service_refunded:int, tax_refunded:int}
     */
    public function forReturn(Transaction $sale, int $itemValue, ?int $exceptReturnId = null, bool $lock = false): array
    {
        $bill = $this->billShare($sale, $exceptReturnId, $lock);
        $base = $bill['base'];

        if ($base < 1) {
            return ['service_refunded' => 0, 'tax_refunded' => 0];
        }

        $from = $bill['returned_value'];
        $to = min($base, $from + max(0, $itemValue));

        $service = $this->share($bill['service_charge'], $to, $base) - $this->share($bill['service_charge'], $from, $base);
        $tax = $this->share($bill['refundable_tax'], $to, $base) - $this->share($bill['refundable_tax'], $from, $base);

        // Items refund at pre-discount line prices, so a discounted sale can already give back more than it took. Cap
        // the share at what is left of the paid amount after the completed returns and this item value.
        $cap = max(0, $bill['paid'] - $bill['returned_total'] - max(0, $itemValue));
        if ($service + $tax > $cap) {
            $service = min($service, $cap);
            $tax = min($tax, $cap - $service);
        }

        return ['service_refunded' => $service, 'tax_refunded' => $tax];
    }

    /** Round half up of $total x $cumulative / $base, integer only: the rule TransactionDetail::returnAmount uses. */
    private function share(int $total, int $cumulative, int $base): int
    {
        return intdiv(2 * $total * $cumulative + $base, 2 * $base);
    }

    /**
     * The sale's tax without the PPN charged on shipping: shipping is not a line, so a return never refunds its tax.
     * PB1 never taxes shipping, so it is 0 there.
     */
    private function refundableTax(Transaction $sale): int
    {
        return max(0, max(0, (int) $sale->tax_total) - $this->shippingTax($sale));
    }

    /** PPN on shipping, or 0 when no tax was charged or PB1 never taxes shipping. */
    private function shippingTax(Transaction $sale): int
    {
        if ((int) $sale->tax_total < 1 || $sale->tax_kind === 'pb1' || (int) $sale->shipping_cost < 1) {
            return 0;
        }

        return (int) round((int) $sale->shipping_cost * (float) $sale->tax_rate / 100);
    }

    /**
     * The sale's completed returns, excluding $exceptReturnId. When locking, the id is filtered in PHP: a
     * `where id != ?` under FOR UPDATE is the pattern that deadlocked on MySQL (#486).
     */
    private function completedReturns(Transaction $sale, ?int $exceptReturnId, bool $lock): Collection
    {
        $query = SalesReturn::query()
            ->where('transaction_id', $sale->id)
            ->where('status', 'completed');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query
            ->get(['id', 'total_return_amount', 'service_refunded', 'tax_refunded'])
            ->reject(fn (SalesReturn $return) => $exceptReturnId !== null && (int) $return->id === $exceptReturnId)
            ->values();
    }
}
