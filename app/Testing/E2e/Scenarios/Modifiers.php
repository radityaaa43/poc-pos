<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Category;
use App\Models\ModifierOption;
use App\Models\Outlet;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Warehouse;
use App\Services\ModifierGroupService;
use App\Support\Modifiers\ModifierCatalog;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Str;

class Modifiers
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // A required group --arg=name=<text> with Regular (default) and Large, optionally on --arg=product=<id>.
            'modifier-group' => function (array $options): array {
                $group = app(ModifierGroupService::class)->save(null, [
                    'name' => $options['name'],
                    'is_required' => true,
                    'min_select' => 1,
                    'max_select' => 1,
                    'options' => [
                        ['name' => 'Regular', 'price_delta' => 0, 'is_default' => true],
                        ['name' => 'Large', 'price_delta' => 5000, 'is_default' => false],
                    ],
                ]);

                if (isset($options['product'])) {
                    $group->products()->attach($options['product']);
                }

                return ['group_id' => $group->id, 'name' => $group->name];
            },

            // A second outlet; its code sorts after E2E so no other step's default outlet moves.
            'modifier-outlet' => function (): array {
                $suffix = Str::upper(Str::random(5));
                $outlet = Outlet::create(['code' => "ZMOD{$suffix}", 'name' => "Outlet Modifier {$suffix}", 'is_active' => true, 'is_sales_enabled' => true]);

                return ['code' => $outlet->code, 'name' => $outlet->name];
            },

            // Menu --arg=title (20.000, stock 20): Size (Large +5.000 uses 2 milk), Topping max 1 (Boba is out), required
            // Gula (default Normal is out; Less too with --arg=sold_out=1). --arg=percent=<n> adds a menu promo.
            'modifier-menu' => function (array $options): array {
                $product = fn (string $title, int $stock, bool $raw = false) => tap(Product::create([
                    'category_id' => Category::where('name', 'E2E Kategori')->value('id'), 'title' => $title,
                    'barcode' => 'E2E-'.Str::upper(Str::random(10)), 'sku' => 'E2E-'.Str::upper(Str::random(10)),
                    'description' => 'Produk E2E', 'buy_price' => 5000, 'sell_price' => 20000, 'stock' => $stock,
                    'tax_type' => 'exclusive', 'tax_rate' => 0, 'is_raw_material' => $raw,
                ]), fn (Product $made) => Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail()
                    ->products()->attach($made->id, ['stock' => $stock]));
                $menu = $product($options['title'], 20);
                $milk = $product("Susu {$options['title']}", 10, true);
                $empty = $product("Kosong {$options['title']}", 0, true);
                $using = fn (Product $ingredient, int $qty) => [['product_id' => $ingredient->id, 'qty' => $qty]];
                $sugar = ['name' => 'Less', 'price_delta' => 0, 'is_default' => false]
                    + (isset($options['sold_out']) ? ['components' => $using($empty, 1)] : []);
                $groups = [
                    ['Size', 1, [['name' => 'Regular', 'price_delta' => 0, 'is_default' => true], ['name' => 'Large', 'price_delta' => 5000, 'is_default' => false, 'components' => $using($milk, 2)]]],
                    ['Topping', 0, [['name' => 'Jelly', 'price_delta' => 2000, 'is_default' => false], ['name' => 'Keju', 'price_delta' => 4000, 'is_default' => false], ['name' => 'Boba', 'price_delta' => 3000, 'is_default' => false, 'components' => $using($empty, 1)]]],
                    ['Gula', 1, [['name' => 'Normal', 'price_delta' => 0, 'is_default' => true, 'components' => $using($empty, 1)], $sugar]],
                ];

                foreach ($groups as $sort => [$name, $min, $rows]) {
                    app(ModifierGroupService::class)->save(null, ['name' => $name, 'is_required' => $min > 0, 'min_select' => $min, 'max_select' => 1, 'options' => $rows])
                        ->products()->attach($menu->id, ['sort' => $sort]);
                }

                if (isset($options['percent'])) {
                    PricingRule::create([
                        'name' => "Promo {$menu->title}", 'is_active' => true, 'priority' => 100, 'target_type' => PricingRule::TARGET_PRODUCT,
                        'product_id' => $menu->id, 'customer_scope' => PricingRule::SCOPE_ALL,
                        'discount_type' => PricingRule::TYPE_PERCENTAGE, 'discount_value' => (int) $options['percent'],
                    ]);
                }

                return ['product_id' => $menu->id, 'milk_id' => $milk->id];
            },

            // After a line is in the cart: --arg=product=<menu id> --arg=option=<name> is switched off at the E2E
            // outlet, or renamed and repriced with --arg=rename=<text>.
            'modifier-change' => function (array $options): array {
                $groups = Product::findOrFail($options['product'])->modifierGroups->modelKeys();
                $option = ModifierOption::where('name', $options['option'])->whereIn('modifier_group_id', $groups)->firstOrFail();

                if (isset($options['rename'])) {
                    $option->update(['name' => $options['rename'], 'price_delta' => 9000]);
                } else {
                    $option->disabledOutlets()->attach(Outlet::where('code', 'E2E')->value('id'));
                }

                return ['option_id' => $option->id];
            },

            // Stock of --arg=product=<id> in the E2E warehouse.
            'modifier-stock' => fn (array $options): array => [
                'stock' => (int) ProductWarehouse::where('product_id', $options['product'])
                    ->where('warehouse_id', Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->value('id'))->value('stock'),
            ],

            // The option names the selling channels get for --arg=product=<id> at --arg=outlet=<code>.
            'modifier-catalog' => function (array $options): array {
                $outlet = Outlet::where('code', $options['outlet'])->firstOrFail();
                $groups = ModifierCatalog::forOutlet($outlet, collect([(int) $options['product']]))->get((int) $options['product'])['groups'] ?? [];

                return ['options' => collect($groups)->pluck('options.*.name')->flatten()->all()];
            },
        ];
    }
}
