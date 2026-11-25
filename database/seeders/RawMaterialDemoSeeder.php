<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryCostService;
use App\Services\StockMutationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Coffee-shop raw materials and recipes for the REQ-002 demo (central vs outlet purchasing,
 * purchase units, recipe cost, reorder drafts). Generic names only: the demo tenant is an example.
 */
class RawMaterialDemoSeeder extends Seeder
{
    /** Quantities are in the base unit; stock is [central warehouse, each outlet warehouse, WH-MAL override]. */
    private const MATERIALS = [
        ['RM-0001', 'Biji Kopi', 'GRAM', 'KG', 1000, 180000, 'central', 2000, 12000, [10000, 3000]],
        ['RM-0002', 'Susu Segar', 'ML', 'LITER', 1000, 18000, 'central', 10000, 40000, [30000, 12000]],
        ['RM-0003', 'Air Mineral', 'ML', 'LITER', 1000, 4000, 'outlet', 20000, 80000, [5000, 50000]],
        ['RM-0004', 'Gula Aren Cair', 'ML', 'LITER', 1000, 45000, 'outlet', 3000, 12000, [1000, 6000]],
        ['RM-0005', 'Sirup Karamel', 'ML', 'BOTOL', 750, 95000, 'central', 1500, 6000, [5000, 2000]],
        // Matcha is below its minimum at the central warehouse so reorder:generate drafts a central PO.
        ['RM-0006', 'Bubuk Matcha', 'GRAM', 'PACK', 500, 175000, 'central', 500, 3000, [300, 800]],
        ['RM-0007', 'Bubuk Kakao', 'GRAM', 'PACK', 500, 90000, 'central', 500, 3000, [2500, 800]],
        ['RM-0008', 'Bubuk Red Velvet', 'GRAM', 'PACK', 500, 80000, 'central', 500, 3000, [2500, 800]],
        ['RM-0009', 'Teh Hitam', 'GRAM', 'KG', 1000, 120000, 'central', 500, 3000, [2500, 800]],
        // Lemon juice is below its minimum at Outlet Malabar so reorder:generate drafts an outlet PO.
        ['RM-0010', 'Air Jeruk Nipis', 'ML', 'BOTOL', 600, 30000, 'outlet', 1000, 5000, [500, 2500, 600]],
        ['RM-0011', 'Es Batu', 'GRAM', 'KG', 1000, 3000, 'outlet', 10000, 40000, [2000, 20000]],
        ['RM-0012', 'Cup 12oz', 'PCS', 'BOX', 50, 45000, 'central', 200, 1000, [800, 300]],
    ];

    /** Recipe quantities are in the materials' base units. */
    private const RECIPES = [
        ['MNR-0001', 'Americano', 18000, ['Biji Kopi' => 18, 'Air Mineral' => 200, 'Cup 12oz' => 1]],
        ['MNR-0002', 'Cafe Latte', 25000, ['Biji Kopi' => 18, 'Susu Segar' => 150, 'Cup 12oz' => 1]],
        ['MNR-0003', 'Es Kopi Susu Gula Aren', 22000, ['Biji Kopi' => 18, 'Susu Segar' => 120, 'Gula Aren Cair' => 20, 'Es Batu' => 150, 'Cup 12oz' => 1]],
        ['MNR-0004', 'Matcha Latte', 26000, ['Bubuk Matcha' => 8, 'Susu Segar' => 150, 'Es Batu' => 100, 'Cup 12oz' => 1]],
        ['MNR-0005', 'Es Teh Lemon', 15000, ['Teh Hitam' => 10, 'Air Jeruk Nipis' => 30, 'Gula Aren Cair' => 15, 'Es Batu' => 150, 'Cup 12oz' => 1]],
    ];

    private const UNITS = [
        'GRAM' => ['Gram', 'g'],
        'ML' => ['Mililiter', 'ml'],
        'PCS' => ['Pieces', 'pcs'],
        'KG' => ['Kilogram', 'kg'],
        'LITER' => ['Liter', 'l'],
        'BOTOL' => ['Botol', 'btl'],
        'PACK' => ['Pak', 'pak'],
        'BOX' => ['Box', 'box'],
    ];

    public function run(InventoryCostService $costs, StockMutationService $mutations): void
    {
        $warehouses = Warehouse::whereIn('code', ['PUSAT', 'WH-MAL', 'WH-TKB', 'WH-PUT'])->get()->keyBy('code');

        if ($warehouses->count() < 4) {
            $this->command?->warn('Skipping RawMaterialDemoSeeder because the demo warehouses are missing.');

            return;
        }

        $units = collect(self::UNITS)->map(fn ($u, $code) => Unit::firstOrCreate(['code' => $code], ['name' => $u[0], 'symbol' => $u[1]]));
        $category = Category::firstOrCreate(['name' => 'Bahan Baku'], ['description' => 'Bahan baku untuk resep dan produksi.']);
        $userId = User::where('email', 'arya@gmail.com')->value('id');

        foreach (self::MATERIALS as [$code, $title, $base, $purchase, $factor, $price, $by, $min, $max, $stock]) {
            // Re-running the seeder on its own leaves an existing material (and its stock) untouched.
            if (Product::where('barcode', $code)->exists()) {
                continue;
            }

            DB::transaction(function () use ($costs, $mutations, $warehouses, $units, $category, $userId, $code, $title, $base, $purchase, $factor, $price, $by, $min, $max, $stock) {
                $unitCost = $price / $factor;
                $product = Product::create([
                    'barcode' => $code,
                    'sku' => $code,
                    'title' => $title,
                    'description' => $title,
                    'category_id' => $category->id,
                    'buy_price' => (int) round($unitCost),
                    'sell_price' => 0,
                    'stock' => 0,
                    'min_stock' => $min,
                    'max_stock' => $max,
                    'tax_type' => 'exclusive',
                    'tax_rate' => 0,
                    'is_raw_material' => true,
                    'purchased_by' => $by,
                ]);
                $product->units()->sync([
                    $units[$base]->id => ['is_base' => true, 'is_purchase_default' => false, 'conversion_factor' => 1, 'buy_price' => (int) round($unitCost), 'sell_price' => 0],
                    $units[$purchase]->id => ['is_base' => false, 'is_purchase_default' => true, 'conversion_factor' => $factor, 'buy_price' => $price, 'sell_price' => 0],
                ]);

                foreach ($warehouses as $warehouseCode => $warehouse) {
                    $qty = match ($warehouseCode) {
                        'PUSAT' => $stock[0],
                        'WH-MAL' => $stock[2] ?? $stock[1],
                        default => $stock[1],
                    };

                    // Same path as received stock: sets the warehouse average cost, then the opening mutation.
                    $costs->receive($product, $warehouse->id, $qty, $unitCost);
                    $mutations->recordMutation($product, $warehouse->id, 'product_create', $product->id, 'in', $qty, 0, $qty, 'Initial stock saat produk dibuat.', $userId);
                    $product->stock += $qty;
                }

                $product->save();
            });
        }

        $materials = Product::where('is_raw_material', true)->pluck('id', 'title');
        $drinks = Category::firstOrCreate(['name' => 'Minuman'], ['description' => 'Minuman']);

        foreach (self::RECIPES as [$code, $title, $sellPrice, $components]) {
            $recipe = Product::updateOrCreate(['barcode' => $code], [
                'sku' => $code,
                'title' => $title,
                'description' => $title,
                'category_id' => $drinks->id,
                'buy_price' => 0,
                'sell_price' => $sellPrice,
                'stock' => 0,
                'tax_type' => 'exclusive',
                'tax_rate' => 0,
                'is_composite' => true,
            ]);
            $recipe->components()->sync(collect($components)->mapWithKeys(fn ($qty, $name) => [$materials[$name] => ['qty' => $qty]])->all());
        }

        $this->command?->info('  - Raw materials and recipes seeded (REQ-002 demo).');
    }
}
