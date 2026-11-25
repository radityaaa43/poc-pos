<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Category;
use App\Models\KitchenItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

            // A menu with a 5 minute target and finished kitchen items on --arg=date (default 2025-04-14) in the E2E outlet, one sale each:
            // a 4 min make, a qty-3 line of 7 min, a 3 min make, plus a cancelled and a not collected item that
            // the speed report must leave out. Report: 3 orders, 5 portions, make median 4.0 / avg 4.7, wait + make
            // median 5.0 / avg 6.0, within target 66.7%; handover avg 7.0 over 3 orders.
            'kitchen.speed-items' => function (array $options): array {
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $cashier = User::where('email', 'cashier@e2e.test')->firstOrFail();
                $product = Product::create([
                    'category_id' => Category::firstOrCreate(['name' => 'E2E Kategori'])->id,
                    'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                    'title' => 'Soto '.Str::upper(Str::random(6)),
                    'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 20, 'tax_type' => 'exclusive', 'tax_rate' => 0,
                    'needs_preparation' => true, 'prep_target_minutes' => 5,
                ]);
                $warehouse->products()->attach($product->id, ['stock' => 20]);

                // [status, qty, minutes after 10:00 of: preparing, ready, served]
                $lines = [
                    ['served', 1, 1, 5, 6],
                    ['served', 3, 62, 69, 70],
                    ['served', 1, 121, 124, 125],
                    ['cancelled', 1, null, null, null],
                    ['not_collected', 1, 181, 185, null],
                ];
                $day = $options['date'] ?? '2025-04-14'; // a step passes its own day, so reruns on one database do not add up
                $at = fn (?int $minutes) => $minutes === null ? null : Carbon::parse($day.' 10:00:00')->addMinutes($minutes);
                foreach ($lines as $n => [$status, $qty, $preparing, $ready, $served]) {
                    $transaction = Transaction::create([
                        'cashier_id' => $cashier->id, 'warehouse_id' => $warehouse->id, 'invoice' => 'KS'.Str::upper(Str::random(8)),
                        'cash' => 10000 * $qty, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => 10000 * $qty,
                        'payment_method' => 'cash', 'payment_status' => 'paid', 'tax_rate' => 0, 'tax_total' => 0,
                    ]);
                    $detail = $transaction->details()->create([
                        'product_id' => $product->id, 'conversion_factor' => 1, 'qty' => $qty, 'base_unit_price' => 10000,
                        'unit_price' => 10000, 'price' => 10000 * $qty, 'discount_total' => 0, 'cost_total' => 5000 * $qty,
                    ]);
                    KitchenItem::create([
                        'transaction_id' => $transaction->id, 'transaction_detail_id' => $detail->id, 'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id, 'qty' => $qty, 'status' => $status, 'accepted_at' => $at($n * 60),
                        'preparing_at' => $at($preparing), 'ready_at' => $at($ready), 'served_at' => $at($served),
                    ]);
                }

                return [
                    'title' => $product->title, 'date' => $day, 'target' => 5, 'count_rows' => 3, 'count_portions' => 5,
                    'make_median' => 4.0, 'make_avg' => 4.7, 'wait_make_median' => 5.0, 'wait_make_avg' => 6.0,
                    'within_target_pct' => 66.7, 'orders' => 3, 'handover_avg' => 7.0,
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
