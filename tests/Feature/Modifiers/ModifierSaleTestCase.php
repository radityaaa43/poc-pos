<?php

namespace Tests\Feature\Modifiers;

use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A cashier with an open shift and one menu: Size (required, one) = Regular (default) | Large +5.000 using 2 milk,
 * and Topping (optional, up to two) = Boba +3.000 using 1 boba | Jelly +2.000 | Cheese +4.000.
 */
abstract class ModifierSaleTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Outlet $outlet;

    protected Warehouse $warehouse;

    protected Product $menu;

    protected Product $milk;

    protected Product $boba;

    private int $categoryId;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $this->cashier = User::where('email', 'cashier@gmail.com')->firstOrFail();
        $this->cashier->markEmailAsVerified();
        $this->outlet = Outlet::create(['code' => 'O1', 'name' => 'Outlet 1', 'is_active' => true, 'is_sales_enabled' => true]);
        $this->warehouse = Warehouse::create(['code' => 'W1', 'name' => 'Gudang 1', 'status' => 'active', 'outlet_id' => $this->outlet->id]);
        $this->categoryId = Category::create(['name' => 'Menu', 'image' => 'categories/t.jpg', 'description' => 'x'])->id;
        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 0, null, $this->warehouse->id);
        $this->actingAs($this->cashier);

        $this->menu = $this->product('Kopi Susu', 20000, 50);
        $this->milk = $this->product('Susu', 0, 10, ['buy_price' => 700, 'is_raw_material' => true]);
        $this->boba = $this->product('Boba', 0, 10, ['buy_price' => 900, 'is_raw_material' => true]);

        $this->group('Size', true, 1, 1, [
            ['Regular', 0, [], true],
            ['Large', 5000, [$this->milk->id => 2]],
        ]);
        $this->group('Topping', false, 0, 2, [
            ['Boba', 3000, [$this->boba->id => 1]],
            ['Jelly', 2000],
            ['Cheese', 4000],
        ]);
    }

    protected function product(string $title, int $price, int $stock, array $attributes = []): Product
    {
        $n = ++$this->seq;
        $product = Product::create($attributes + [
            'title' => $title, 'sku' => "SKU-SALE-$n", 'barcode' => "BC-SALE-$n", 'description' => 'x',
            'image' => 'products/t.jpg', 'buy_price' => 5000, 'sell_price' => $price, 'stock' => $stock,
            'tax_rate' => 0, 'category_id' => $this->categoryId,
        ]);
        $this->warehouse->products()->attach($product->id, ['stock' => $stock]);

        return $product;
    }

    /** @param  list<array{0: string, 1: int, 2?: array<int, int>, 3?: bool}>  $options  name, price, recipe, default */
    private function group(string $name, bool $required, int $min, int $max, array $options): void
    {
        $group = ModifierGroup::create(['name' => $name, 'is_required' => $required, 'min_select' => $min, 'max_select' => $max]);

        foreach ($options as $sort => $row) {
            $option = $group->options()->create(['name' => $row[0], 'price_delta' => $row[1], 'is_default' => $row[3] ?? false, 'sort' => $sort]);
            $option->components()->sync(collect($row[2] ?? [])->map(fn (int $qty) => ['qty' => $qty])->all());
        }

        $this->menu->modifierGroups()->attach($group->id, ['sort' => $group->id]);
    }

    protected function option(string $name): ModifierOption
    {
        return ModifierOption::withTrashed()->where('name', $name)->firstOrFail();
    }

    /** @param  list<string>  $options */
    protected function add(array $options, int $qty = 1, ?string $note = null, array $extra = []): TestResponse
    {
        return $this->post(route('transactions.addToCart'), $extra + [
            'product_id' => $this->menu->id,
            'qty' => $qty,
            'modifier_option_ids' => array_map(fn (string $name) => $this->option($name)->id, $options),
            'note' => $note,
        ]);
    }

    protected function checkout(array $payload = []): TestResponse
    {
        return $this->post(route('transactions.store'), $payload + ['cash' => 1000000]);
    }

    /** @return array{0: int, 1: int} warehouse stock and global stock */
    protected function stockOf(Product $product): array
    {
        return [
            (int) $this->warehouse->products()->whereKey($product->id)->firstOrFail()->pivot->stock,
            (int) $product->fresh()->stock,
        ];
    }

    protected function setStock(Product $product, int $stock): void
    {
        $this->warehouse->products()->updateExistingPivot($product->id, ['stock' => $stock]);
        $product->update(['stock' => $stock]);
    }

    protected function refusal(string $key, array $replace): string
    {
        return __("messages.modifiers.{$key}", ['menu' => $this->menu->title] + $replace);
    }
}
