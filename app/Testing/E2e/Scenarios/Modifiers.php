<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Services\ModifierGroupService;
use App\Support\Modifiers\ModifierCatalog;
use Illuminate\Support\Str;

/** Arrange scenarios for modifier groups (REQ-008). */
class Modifiers
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // A menu the product form can save as it is (SKU included). --arg=title=<text>
            'modifier-menu' => function (array $options): array {
                $product = Product::create([
                    'category_id' => Category::where('name', 'E2E Kategori')->firstOrFail()->id,
                    'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                    'sku' => 'E2E-'.Str::upper(Str::random(10)),
                    'title' => $options['title'],
                    'description' => 'Menu E2E',
                    'buy_price' => 5000,
                    'sell_price' => 10000,
                    'stock' => 0,
                    'tax_rate' => 0,
                ]);

                return ['product_id' => $product->id, 'title' => $product->title];
            },

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

            // The option names the selling channels get for --arg=product=<id> at --arg=outlet=<code>.
            'modifier-catalog' => function (array $options): array {
                $outlet = Outlet::where('code', $options['outlet'])->firstOrFail();
                $groups = ModifierCatalog::forOutlet($outlet, collect([(int) $options['product']]))->get((int) $options['product'], []);

                return ['options' => collect($groups)->pluck('options.*.name')->flatten()->all()];
            },
        ];
    }
}
