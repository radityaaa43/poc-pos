<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Inventory group: recipes and raw materials, low-stock alerts, a second warehouse for transfers.
 * Every title comes from `--arg=prefix=<uniq>` so steps never collide.
 */
class Inventory
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // Americano (18 beans + 200 water + 1 cup, sold at --arg=price) built from three raw materials in
            // the E2E warehouse, plus one regular product (--arg=regular=<stock>, default 7). --arg=beans is the bean stock.
            'inventory.recipe' => function (array $options): array {
                $prefix = $options['prefix'] ?? 'Rcp-'.Str::lower(Str::random(5));
                $warehouse = self::warehouse();
                self::emptyCarts();

                $bean = self::product($warehouse, "$prefix Biji", (int) ($options['beans'] ?? 1000), ['buy_price' => 250, 'is_raw_material' => true]);
                $water = self::product($warehouse, "$prefix Air", 10000, ['buy_price' => 1, 'is_raw_material' => true]);
                $cup = self::product($warehouse, "$prefix Cup", 100, ['buy_price' => 1500, 'is_raw_material' => true]);

                $recipe = self::product($warehouse, "$prefix Americano", 0, [
                    'sell_price' => (int) ($options['price'] ?? 22000),
                    'is_composite' => true,
                ], attach: false);
                $recipe->components()->attach([
                    $bean->id => ['qty' => 18],
                    $water->id => ['qty' => 200],
                    $cup->id => ['qty' => 1],
                ]);

                $regular = self::product($warehouse, "$prefix Croissant", (int) ($options['regular'] ?? 7), ['sell_price' => 22000]);

                return [
                    'recipe' => $recipe->title,
                    'bean' => $bean->title,
                    'bean_id' => $bean->id,
                    'water_id' => $water->id,
                    'cup_id' => $cup->id,
                    'regular' => $regular->title,
                ];
            },

            // PCS (base, content 1) and BOX (content 12, sold at 110000) with --arg=stock pieces in the E2E warehouse.
            'inventory.multi-unit' => function (array $options): array {
                self::emptyCarts();
                $product = self::product(self::warehouse(), $options['prefix'].' Susu', (int) ($options['stock'] ?? 48));
                $pcs = Unit::where('code', 'PCS')->firstOrFail();
                $box = Unit::where('code', 'BOX')->firstOrFail();
                $product->units()->attach([
                    $pcs->id => ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 5000, 'sell_price' => 10000],
                    $box->id => ['is_base' => false, 'conversion_factor' => 12, 'buy_price' => 60000, 'sell_price' => 110000],
                ]);

                return ['product' => $product->title];
            },

            // A named customer, so the POS can complete a sale.
            'inventory.customer' => function (array $options): array {
                $customer = Customer::create([
                    'name' => $options['name'] ?? 'Pelanggan '.Str::random(5),
                    'no_telp' => '08'.random_int(100000000, 999999999),
                    'address' => 'Jl. Uji',
                ]);

                return ['customer' => $customer->name];
            },

            // A raw material already sitting in the cashier's cart (a stale cart from before the product was flagged).
            'inventory.cart-raw-material' => function (array $options): array {
                $cashier = User::where('email', $options['cashier'] ?? 'cashier@e2e.test')->firstOrFail();
                $shift = CashierShift::open()->where('user_id', $cashier->id)->firstOrFail();
                $material = self::product(self::warehouse(), $options['prefix'].' Bahan', 50, ['is_raw_material' => true]);

                Cart::create([
                    'cashier_id' => $cashier->id,
                    'warehouse_id' => $shift->warehouse_id,
                    'product_id' => $material->id,
                    'unit_id' => null,
                    'conversion_factor' => 1,
                    'qty' => 1,
                    'price' => 0,
                ]);

                return ['material' => $material->title];
            },

            // A dine-in table (no area) and its menu path.
            'inventory.dine-table' => function (array $options): array {
                $table = DiningTable::create([
                    'name' => $options['name'] ?? 'Meja '.Str::random(4),
                    'token' => Str::random(24),
                    'is_active' => true,
                    'capacity' => 2,
                    'sort_order' => 0,
                ]);

                return ['path' => '/dine/'.$table->token];
            },

            // Below its minimum stock (--arg=stock, --arg=min, --arg=max), with a batch expiring in 10 days.
            'inventory.low-stock' => function (array $options): array {
                // The bell lists ten batches at most, soonest first: earlier steps' batches must not crowd this one out.
                ProductBatch::query()->update(['stock' => 0]);
                $product = self::product(self::warehouse(), $options['prefix'].' Rendah', (int) ($options['stock'] ?? 2), [
                    'min_stock' => (int) ($options['min'] ?? 5),
                    'max_stock' => (int) ($options['max'] ?? 20),
                ]);
                $batch = ProductBatch::create([
                    'product_id' => $product->id,
                    'warehouse_id' => self::warehouse()->id,
                    'batch_number' => 'BATCH-'.Str::upper(Str::random(5)),
                    'expired_at' => now()->addDays(10)->toDateString(),
                    'received_at' => now()->toDateString(),
                    'stock' => $product->stock,
                ]);

                return ['product' => $product->title, 'batch' => $batch->batch_number];
            },

            // A second warehouse in the E2E outlet, so a manager can move stock between two they may use.
            // Returns a raw material (its per-warehouse stock shows on the Raw Materials page) with --arg=stock
            // units in the first and none in the second.
            'inventory.transfer' => function (array $options): array {
                $warehouse = self::warehouse();
                $second = Warehouse::updateOrCreate(
                    ['code' => 'WH-E2E-B'],
                    ['outlet_id' => $warehouse->outlet_id, 'name' => 'Gudang E2E B', 'type' => 'branch', 'is_active' => true, 'sort_order' => 2],
                );
                $product = self::product($warehouse, $options['prefix'].' Transfer', (int) ($options['stock'] ?? 10), ['is_raw_material' => true]);

                return ['product' => $product->title, 'product_id' => $product->id, 'from' => $warehouse->code, 'to' => $second->code];
            },
        ];
    }

    /** A cashier's cart outlives a step that failed half way; every POS step starts from an empty one. */
    private static function emptyCarts(): void
    {
        Cart::query()->delete();
    }

    private static function warehouse(): Warehouse
    {
        return Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
    }

    /** A product in the E2E warehouse; stock goes to the pivot and to products.stock together. */
    private static function product(Warehouse $warehouse, string $title, int $stock, array $attributes = [], bool $attach = true): Product
    {
        return DB::transaction(function () use ($warehouse, $title, $stock, $attributes, $attach) {
            $product = Product::create([
                'category_id' => Category::where('name', 'E2E Kategori')->firstOrFail()->id,
                'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                'sku' => 'SKU-'.Str::upper(Str::random(10)),
                'title' => $title,
                'buy_price' => 5000,
                'sell_price' => 10000,
                'stock' => $stock,
                'tax_type' => 'exclusive',
                'tax_rate' => 0,
                ...$attributes,
            ]);

            if ($attach && $stock > 0) {
                $warehouse->products()->attach($product->id, ['stock' => $stock]);
            }

            return $product;
        });
    }
}
