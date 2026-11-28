<?php

namespace App\Testing\E2e;

use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Arrange scenarios for `php artisan e2e:arrange <name> --arg=key=value`.
 * Each closure takes the parsed options and returns the created ids. A feature group adds its own
 * class in Scenarios/ (`App\Testing\E2e\Scenarios\<Name>` with a static registry()), so parallel
 * PRs never edit the same array.
 */
class Scenarios
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        $groups = [];
        foreach (glob(__DIR__.'/Scenarios/*.php') ?: [] as $file) {
            $groups[] = (__NAMESPACE__.'\\Scenarios\\'.basename($file, '.php'))::registry();
        }

        return array_merge(self::base(), ...$groups);
    }

    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    private static function base(): array
    {
        return [
            // Opens a shift for --arg=cashier=<email> (default cashier@e2e.test) with --arg=cash=<amount>.
            'open-shift' => function (array $options): array {
                $cashier = User::where('email', $options['cashier'] ?? 'cashier@e2e.test')->firstOrFail();
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();

                // Re-arranging must not fail on a shift left open by an earlier step.
                CashierShift::open()->where('user_id', $cashier->id)
                    ->update(['status' => CashierShift::STATUS_CLOSED, 'closed_at' => now()]);

                $shift = app(CashierShiftService::class)->openShift(
                    $cashier, $cashier, (int) ($options['cash'] ?? 100000), null, $warehouse->id,
                );

                return ['shift_id' => $shift->id, 'cashier_id' => $cashier->id];
            },

            // Creates a product with --arg=stock=<n> (default 10), --arg=title=<text> and optional --arg=purchased_by=central|outlet
            // in the E2E warehouse.
            'product' => function (array $options): array {
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $category = Category::where('name', 'E2E Kategori')->firstOrFail();
                $stock = (int) ($options['stock'] ?? 10);

                $product = DB::transaction(function () use ($options, $warehouse, $category, $stock) {
                    $product = Product::create([
                        'category_id' => $category->id,
                        'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                        // The product form needs a SKU to save an edit.
                        'sku' => 'E2E-'.Str::upper(Str::random(10)),
                        'title' => $options['title'] ?? 'Produk E2E '.Str::upper(Str::random(5)),
                        'description' => 'Produk E2E',
                        'buy_price' => 5000,
                        'sell_price' => 10000,
                        'stock' => $stock,
                        'tax_type' => 'exclusive',
                        'tax_rate' => $options['tax_rate'] ?? 0,
                        'purchased_by' => $options['purchased_by'] ?? null,
                    ]);
                    $warehouse->products()->attach($product->id, ['stock' => $stock]);

                    return $product;
                });

                return ['product_id' => $product->id, 'title' => $product->title, 'barcode' => $product->barcode];
            },

            // Creates a recipe --arg=title=<text> of 2 x a component averaging 300 + 3 x one averaging 250 in the E2E
            // warehouse. Every buy price is 5000 or 6200 on purpose: only the averages can give the returned cost.
            'recipe' => function (array $options): array {
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $category = Category::where('name', 'E2E Kategori')->firstOrFail();

                $recipe = DB::transaction(function () use ($options, $warehouse, $category) {
                    $make = fn (string $title, array $attributes) => Product::create($attributes + [
                        'category_id' => $category->id,
                        'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                        'title' => $title,
                        'sell_price' => 10000,
                        'tax_type' => 'exclusive',
                        'tax_rate' => 0,
                        'stock' => 0,
                    ]);
                    $component = function (string $title, int $avg) use ($make, $warehouse) {
                        $product = $make($title.' '.Str::upper(Str::random(4)), ['buy_price' => 5000, 'stock' => 100, 'is_raw_material' => true]);
                        $warehouse->products()->attach($product->id, ['stock' => 100, 'avg_cost' => $avg]);

                        return $product;
                    };

                    $recipe = $make($options['title'] ?? 'Resep E2E '.Str::upper(Str::random(5)), ['buy_price' => 6200, 'sell_price' => 20000, 'is_composite' => true]);
                    $recipe->components()->attach([
                        $component('Bahan A', 300)->id => ['qty' => 2],
                        $component('Bahan B', 250)->id => ['qty' => 3],
                    ]);

                    return $recipe;
                });

                return ['product_id' => $recipe->id, 'title' => $recipe->title, 'cost' => 2 * 300 + 3 * 250, 'outlet' => E2eSeeder::OUTLET_CODE];
            },
        ];
    }
}
