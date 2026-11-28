<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Profit;
use App\Models\StockWaste;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Waste group (e2e folder 82); its products come from inventory.recipe. */
class Waste
{
    /**
     * @return array<string, \Closure(array<string, string>): mixed>
     */
    public static function registry(): array
    {
        return [
            // For --arg=ids=1,2: each product's [stock in the E2E warehouse, products.stock], in that order.
            'waste.stock' => function (array $options): array {
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();

                return array_map(fn (string $id): array => [
                    (int) ProductWarehouse::where(['product_id' => $id, 'warehouse_id' => $warehouse->id])->value('stock'),
                    (int) Product::whereKey($id)->value('stock'),
                ], explode(',', $options['ids']));
            },

            // For the waste report (#460): a sale with a fixed profit plus recorded and cancelled waste on
            // one random far day, so the report's date filter sees only this run. The cancelled record was
            // recorded the day before, so its value subtracts in the reported period.
            'waste.seed-report' => function (array $options): array {
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $cashier = User::where('email', 'cashier@e2e.test')->firstOrFail();
                $category = Category::firstOrCreate(['name' => 'E2E Waste Report']);
                $prefix = 'WST'.strtoupper(Str::random(6));
                // A random past day: the report's date filter sees only this run, and the sale does not top the
                // (newest-first) transaction history where other specs take their first row.
                $day = Carbon::create(2023, 1, 1)->addDays(random_int(0, 700));
                $at = $day->toDateString();
                $before = $day->copy()->subDay()->toDateString();

                $beras = Product::create([
                    'category_id' => $category->id, 'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                    'title' => "Beras {$prefix}", 'buy_price' => 200, 'sell_price' => 400, 'stock' => 100,
                    'tax_type' => 'exclusive', 'tax_rate' => 0,
                ]);

                // Revenue 40.000, locked HPP 25.000: profit 15.000, whatever the buy price says later.
                $sale = new Transaction([
                    'cashier_id' => $cashier->id, 'warehouse_id' => $warehouse->id, 'customer_id' => null,
                    'invoice' => "{$prefix}-A1", 'cash' => 40000, 'change' => 0, 'discount' => 0,
                    'shipping_cost' => 0, 'grand_total' => 40000, 'payment_method' => 'cash',
                    'payment_status' => 'paid', 'tax_rate' => 0, 'tax_total' => 0,
                ]);
                $sale->forceFill(['created_at' => "{$at} 10:00:00", 'updated_at' => "{$at} 10:00:00"])->save();
                $sale->details()->create([
                    'product_id' => $beras->id, 'conversion_factor' => 1, 'qty' => 1,
                    'base_unit_price' => 40000, 'unit_price' => 40000, 'price' => 40000,
                    'discount_total' => 0, 'cost_total' => 25000,
                ]);
                Profit::create(['transaction_id' => $sale->id, 'total' => 15000]);

                $record = function (int $value, string $status, string $recordedAt, ?string $cancelledAt, int $qty) use ($warehouse, $beras): void {
                    $waste = StockWaste::create([
                        'warehouse_id' => $warehouse->id, 'status' => $status, 'reason' => 'expired',
                        'total_value' => $value, 'recorded_at' => $recordedAt, 'cancelled_at' => $cancelledAt,
                    ]);
                    $line = $waste->lines()->create(['product_id' => $beras->id, 'qty' => $qty]);
                    $waste->moves()->create([
                        'line_id' => $line->getKey(), 'product_id' => $beras->id, 'qty' => $qty,
                        'unit_cost' => $value / max(1, $qty), 'value' => $value,
                    ]);
                };
                // 10 units worth 2.000 in the period; 2 units worth 400 recorded before it and cancelled here.
                $record(1000, StockWaste::STATUS_RECORDED, "{$at} 11:00:00", null, 5);
                $record(1000, StockWaste::STATUS_RECORDED, "{$at} 12:00:00", null, 5);
                $record(400, StockWaste::STATUS_CANCELLED, "{$before} 09:00:00", "{$at} 13:00:00", 2);

                return ['date' => $at, 'invoice' => $sale->invoice, 'item' => $beras->title];
            },
        ];
    }
}
