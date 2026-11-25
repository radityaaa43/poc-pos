<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\KitchenItem;
use App\Models\Product;
use App\Models\Transaction;

/**
 * Arrange scenarios for the kitchen (`kitchen.*`): flag a product, read the kitchen items back, change a payment
 * status. Intake has no screen of its own yet, so the steps read it here (the display comes in a later ticket).
 */
class Kitchen
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // Flags --arg=product_id=<id> as needing preparation, like the product form does.
            'kitchen.flag' => function (array $options): array {
                Product::findOrFail($options['product_id'])->update(['needs_preparation' => true]);

                return ['product_id' => (int) $options['product_id']];
            },

            // The kitchen items of the sale --arg=invoice=<invoice>: how many, and each one's product and status.
            'kitchen.items' => function (array $options): array {
                $transaction = Transaction::where('invoice', $options['invoice'])->firstOrFail();
                $items = KitchenItem::where('transaction_id', $transaction->id)->orderBy('id')->get();

                return [
                    'count' => $items->count(),
                    'product_ids' => $items->pluck('product_id')->all(),
                    'statuses' => $items->pluck('status')->unique()->values()->all(),
                ];
            },

            // Sets the sale --arg=invoice=<invoice> to --arg=status=failed|expired|pending (default failed), as the
            // gateway's webhook or the expiry job does; `paid` flips it to paid again (a retried webhook).
            'kitchen.set-status' => function (array $options): array {
                $transaction = Transaction::where('invoice', $options['invoice'])->firstOrFail();
                $transaction->update(['payment_status' => $options['status'] ?? 'failed']);

                return ['invoice' => $transaction->invoice, 'payment_status' => $transaction->payment_status];
            },
        ];
    }
}
