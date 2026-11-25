<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\CashierShift;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Testing\E2e\Scenarios;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Str;

/**
 * Retrying a failed POS payment. All names are `posretry.*`.
 */
class PosRetry
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // A QRIS sale of --arg=qty (default 2) x a new 10.000 product whose payment failed, in the cashier's open shift.
            // --arg=status=failed|expired|paid (default failed). --arg=late=1 marks a late payment of a superseded attempt.
            // Stock was taken at sale and stays taken.
            'posretry.failed-sale' => function (array $options): array {
                $qty = (int) ($options['qty'] ?? 2);
                $total = $qty * 10000;
                $product = Scenarios::registry()['product'](['stock' => (string) (10 - $qty)]);
                $cashier = User::where('email', $options['cashier'] ?? 'cashier@e2e.test')->firstOrFail();

                $transaction = Transaction::create([
                    'cashier_id' => $cashier->id,
                    'cashier_shift_id' => CashierShift::open()->where('user_id', $cashier->id)->value('id'),
                    'warehouse_id' => Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail()->id,
                    'invoice' => 'TRX-E2E'.Str::upper(Str::random(8)),
                    'cash' => 0,
                    'change' => 0,
                    'discount' => 0,
                    'shipping_cost' => 0,
                    'grand_total' => $total,
                    'payment_method' => 'qris',
                    'payment_status' => $options['status'] ?? 'failed',
                    'late_superseded_payment_at' => ($options['late'] ?? null) ? now() : null,
                ]);
                $transaction->details()->create([
                    'product_id' => $product['product_id'],
                    'qty' => $qty,
                    'conversion_factor' => 1,
                    'base_unit_price' => 10000,
                    'unit_price' => 10000,
                    'price' => $total,
                    'cost_total' => $qty * 5000,
                ]);
                $transaction->profits()->create(['total' => $qty * 5000]);

                return $product + ['transaction_id' => $transaction->id, 'invoice' => $transaction->invoice, 'total' => $total];
            },

            // Marks --arg=transaction_id as auto-expired and restocked, the way ExpirePendingTransactionsCommand leaves it.
            'posretry.restock' => function (array $options): array {
                $transaction = Transaction::with('details')->findOrFail((int) $options['transaction_id']);
                $detail = $transaction->details->first();

                StockMutation::create([
                    'product_id' => $detail->product_id,
                    'warehouse_id' => $transaction->warehouse_id,
                    'reference_type' => 'transaction_expire',
                    'reference_id' => $transaction->id,
                    'mutation_type' => 'in',
                    'qty' => $detail->qty,
                    'stock_before' => 0,
                    'stock_after' => $detail->qty,
                    'notes' => 'Auto-expire pembayaran '.$transaction->invoice,
                ]);

                return ['transaction_id' => $transaction->id];
            },
        ];
    }
}
