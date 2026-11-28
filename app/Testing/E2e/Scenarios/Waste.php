<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockWasteService;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Str;

/** Waste group (e2e folder 82); its products come from inventory.recipe. */
class Waste
{
    /**
     * @return array<string, \Closure(array<string, string>): array<int, array<int, int>>>
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
            // Sets the E2E outlet's waste approval limit (--arg=value=0 makes every record wait), returns it.
            'waste.limit' => function (array $options): array {
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $limit = (int) ($options['value'] ?? 0);
                Setting::setForOutlet(StockWasteService::LIMIT_KEY, (string) $limit, $warehouse->outlet);

                return ['limit' => $limit];
            },
            // Seeds two pending rows and one recorded row (creator = a separate user) for the approval-journey step.
            // --arg=product_id=<id>&qty=10&note_a=..&note_b=..&note_c=..
            'waste.seed' => function (array $options): array {
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $product = Product::whereKey((int) $options['product_id'])->firstOrFail();
                $qty = (int) ($options['qty'] ?? 10);
                $creator = User::factory()->create(['name' => 'Pencatat E2E']);

                $record = function (string $limit, ?string $note) use ($warehouse, $product, $qty, $creator): array {
                    Setting::setForOutlet(StockWasteService::LIMIT_KEY, $limit, $warehouse->outlet);
                    $waste = app(StockWasteService::class)->record([
                        'warehouse_id' => $warehouse->id, 'reason' => 'damaged', 'note' => $note,
                        'client_key' => (string) Str::uuid(),
                        'lines' => [['product_id' => $product->id, 'qty' => $qty]],
                    ], $creator);

                    return ['id' => $waste->id, 'status' => $waste->status, 'note' => $note];
                };

                return [
                    'pending_a' => $record('0', $options['note_a'] ?? null),
                    'pending_b' => $record('0', $options['note_b'] ?? null),
                    'recorded' => $record('999999999', $options['note_c'] ?? null),
                ];
            },
        ];
    }
}
