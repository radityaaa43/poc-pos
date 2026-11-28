<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Warehouse;
use Database\Seeders\E2eSeeder;

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
        ];
    }
}
