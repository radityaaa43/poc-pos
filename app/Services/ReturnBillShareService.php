<?php

namespace App\Services;

use App\Models\SalesReturn;
use App\Models\Transaction;

/**
 * What a return gives back of a sale's service charge and tax, in proportion to the item value returned (REQ-010
 * AC-10). Cumulative integer rounding: the shares of all returns of a sale add up to exactly what it charged, and the
 * last return takes the remainder. Cash rounding is never part of it.
 */
class ReturnBillShareService
{
    /**
     * The sale's figures a return shares out, plus the item value its completed returns already returned (their item
     * part, excluding $exceptReturnId).
     *
     * @return array{base:int, service_charge:int, refundable_tax:int, returned_value:int}
     */
    public function billShare(Transaction $sale, ?int $exceptReturnId = null, bool $lock = false): array
    {
        // The sum of the line totals: the base PPN is charged on, and what a share is proportional to.
        $base = (int) $sale->details()->sum('price');
        // A pre-release sale (tax_kind null) is treated as PPN with no service charge (BR-2/A1).
        $service = $sale->tax_kind === null ? 0 : max(0, (int) $sale->service_charge);

        return [
            'base' => $base,
            'service_charge' => $service,
            'refundable_tax' => $this->refundableTax($sale),
            'returned_value' => min($base, max(0, $this->returnedItemValue($sale, $exceptReturnId, $lock))),
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

        return [
            'service_refunded' => $this->share($bill['service_charge'], $to, $base) - $this->share($bill['service_charge'], $from, $base),
            'tax_refunded' => $this->share($bill['refundable_tax'], $to, $base) - $this->share($bill['refundable_tax'], $from, $base),
        ];
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
        $tax = max(0, (int) $sale->tax_total);

        if ($tax < 1) {
            return 0;
        }

        $shippingTax = 0;
        if ($sale->tax_kind !== 'pb1' && (int) $sale->shipping_cost > 0) {
            $shippingTax = (int) round((int) $sale->shipping_cost * (float) $sale->tax_rate / 100);
        }

        return max(0, $tax - $shippingTax);
    }

    /**
     * The item value of the sale's completed returns, excluding $exceptReturnId. A completed return's item part is its
     * total less the shares it already refunded, so an old items-only return counts in full (BR-1).
     */
    private function returnedItemValue(Transaction $sale, ?int $exceptReturnId, bool $lock): int
    {
        $query = SalesReturn::query()
            ->where('transaction_id', $sale->id)
            ->where('status', 'completed')
            ->when($exceptReturnId, fn ($builder, int $id) => $builder->where('id', '!=', $id));

        if ($lock) {
            $query->lockForUpdate();
        }

        return (int) $query
            ->get(['total_return_amount', 'service_refunded', 'tax_refunded'])
            ->sum(fn (SalesReturn $return) => (int) $return->total_return_amount
                - (int) $return->service_refunded
                - (int) $return->tax_refunded);
    }
}
