<?php

namespace Tests\Feature\Products;

use App\Exports\ProductsExport;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\InventoryCostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * #73: a recipe has no manual buy price. Its cost per portion is the sum of its components' unit
 * cost x recipe qty, where a component costs: the outlet warehouse average, else the central
 * warehouse average, else its reference buy_price (InventoryCostService::unitCost).
 */
class RecipeCostFromComponentsTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Outlet $akar1;

    private Outlet $akar2;

    private Warehouse $central;

    private Warehouse $wh1;

    private Warehouse $wh2;

    private Product $bean;

    private Product $milk;

    private Product $cup;

    private Product $recipe;

    private Product $plain;

    private static int $seq = 0;

    /** Akar 1: bean 300 (own), milk 120 (central), cup 1500 (buy price). Recipe: 2 bean + 3 milk + 1 cup. */
    private const COST_AKAR1 = 2 * 300 + 3 * 120 + 1500;

    /** Akar 2: bean 400 (own), milk 110 (own), cup 1500 (buy price). */
    private const COST_AKAR2 = 2 * 400 + 3 * 110 + 1500;

    protected function setUp(): void
    {
        parent::setUp();

        $names = ['products-access', 'products-create', 'products-edit', 'products-export', 'transactions-access', 'cashier-shifts-access', 'cashier-shifts-open', 'cashier-shifts-close'];
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $ctr = Outlet::create(['code' => 'CTR', 'name' => 'Pusat', 'is_active' => true, 'is_sales_enabled' => false]);
        $this->akar1 = Outlet::create(['code' => 'AKAR-1', 'name' => 'Akar 1', 'is_active' => true, 'is_sales_enabled' => true]);
        $this->akar2 = Outlet::create(['code' => 'AKAR-2', 'name' => 'Akar 2', 'is_active' => true, 'is_sales_enabled' => true]);
        $this->central = Warehouse::create(['outlet_id' => $ctr->id, 'code' => 'WH-CTR', 'name' => 'Pusat', 'type' => 'main', 'sort_order' => 0]);
        $this->wh1 = Warehouse::create(['outlet_id' => $this->akar1->id, 'code' => 'WH-A1', 'name' => 'Gudang A1', 'type' => 'branch', 'sort_order' => 1]);
        $this->wh2 = Warehouse::create(['outlet_id' => $this->akar2->id, 'code' => 'WH-A2', 'name' => 'Gudang A2', 'type' => 'branch', 'sort_order' => 1]);

        $this->manager = User::factory()->create();
        $this->manager->givePermissionTo($names);
        $this->manager->outlets()->attach($this->akar1->id, ['is_default' => true]);
        $this->manager->outlets()->attach($this->akar2->id, ['is_default' => false]);

        $category = Category::create(['name' => 'Kategori', 'image' => '', 'description' => '']);
        $this->bean = $this->product($category, 'Biji Kopi', 250, [[$this->central, 280], [$this->wh1, 300], [$this->wh2, 400]]);
        $this->milk = $this->product($category, 'Susu', 100, [[$this->central, 120], [$this->wh2, 110]]);
        $this->cup = $this->product($category, 'Cup', 1500, []);
        $this->plain = $this->product($category, 'Roti', 7000, [[$this->wh1, 6000]]);
        // The stored buy price (6200) is stale on purpose: nothing may read it for a recipe.
        $this->recipe = $this->product($category, 'Americano', 6200, [], composite: true);
        $this->recipe->components()->attach([
            $this->bean->id => ['qty' => 2],
            $this->milk->id => ['qty' => 3],
            $this->cup->id => ['qty' => 1],
        ]);
    }

    /** @param  array<int, array{0: Warehouse, 1: int}>  $averages */
    private function product(Category $category, string $title, int $buyPrice, array $averages, bool $composite = false): Product
    {
        self::$seq++;
        $product = Product::create([
            'title' => $title,
            'sku' => 'SKU-RC-'.self::$seq,
            'barcode' => 'BC-RC-'.self::$seq,
            'image' => '',
            'description' => '',
            'buy_price' => $buyPrice,
            'sell_price' => 20000,
            'stock' => 1000,
            'tax_rate' => 0,
            'category_id' => $category->id,
            'is_composite' => $composite,
        ]);

        foreach ($averages as [$warehouse, $avg]) {
            $product->warehouses()->attach($warehouse->id, ['stock' => 1000, 'avg_cost' => $avg]);
        }

        return $product;
    }

    private function inAkar2(): static
    {
        return $this->withSession(['active_outlet_id' => $this->akar2->id]);
    }

    private function formCost(array $products, Product $recipe): int
    {
        $costs = collect($products)->pluck('unit_cost', 'id');

        return (int) round($recipe->components->sum(fn ($c) => $costs[$c->id] * $c->pivot->qty));
    }

    public function test_unit_cost_prefers_outlet_average_then_central_then_buy_price(): void
    {
        $service = app(InventoryCostService::class);

        $this->assertSame(300.0, $service->unitCost($this->bean, $this->wh1->id));
        $this->assertSame(120.0, $service->unitCost($this->milk, $this->wh1->id));
        $this->assertSame(1500.0, $service->unitCost($this->cup, $this->wh1->id));
        $this->assertSame(1500.0, $service->unitCost($this->cup, null));
    }

    public function test_central_is_the_lowest_sort_order_warehouse_of_a_non_selling_outlet(): void
    {
        $other = Outlet::create(['code' => 'CTR-2', 'name' => 'Pusat 2', 'is_active' => true, 'is_sales_enabled' => false]);
        $second = Warehouse::create(['outlet_id' => $other->id, 'code' => 'WH-CTR2', 'name' => 'Pusat 2', 'type' => 'main', 'sort_order' => 5]);
        $this->milk->warehouses()->attach($second->id, ['stock' => 5, 'avg_cost' => 777]);
        $this->cup->warehouses()->attach($second->id, ['stock' => 5, 'avg_cost' => 999]);

        $service = app(InventoryCostService::class);

        $this->assertSame(120.0, $service->unitCost($this->milk, $this->wh1->id));
        // The central warehouse is chosen once, not per product: the second one is never consulted.
        $this->assertSame(1500.0, $service->unitCost($this->cup, $this->wh1->id));
    }

    public function test_edit_form_gets_active_outlet_label_and_component_unit_costs(): void
    {
        $this->actingAs($this->manager)
            ->get(route('products.edit', $this->recipe))
            ->assertInertia(function (Assert $page) {
                $page->component('Dashboard/Products/Edit')->where('cost_outlet', 'AKAR-1');
                $this->assertSame(self::COST_AKAR1, $this->formCost($page->toArray()['props']['products'], $this->recipe));
            });
    }

    public function test_edit_form_follows_the_outlet_switch(): void
    {
        $this->actingAs($this->manager)->post(route('outlet.switch'), ['outlet_id' => $this->akar2->id]);

        $this->get(route('products.edit', $this->recipe))
            ->assertInertia(function (Assert $page) {
                $page->where('cost_outlet', 'AKAR-2');
                $this->assertSame(self::COST_AKAR2, $this->formCost($page->toArray()['props']['products'], $this->recipe));
            });
    }

    public function test_create_form_gets_the_same_unit_costs(): void
    {
        $this->actingAs($this->manager)
            ->get(route('products.create'))
            ->assertInertia(fn (Assert $page) => $page->component('Dashboard/Products/Create')
                ->where('cost_outlet', 'AKAR-1')
                ->where('products', fn ($products) => $products->firstWhere('id', $this->bean->id)['unit_cost'] == 300));
    }

    public function test_products_list_shows_recipe_cost_and_plain_buy_price(): void
    {
        $page = fn (Assert $page) => $page->where('products.data', fn ($rows) => collect($rows)->pluck('cost', 'id')->all() == [
            $this->recipe->id => self::COST_AKAR1,
            $this->plain->id => 7000,
            $this->cup->id => 1500,
            $this->milk->id => 100,
            $this->bean->id => 250,
        ]);

        $this->actingAs($this->manager)->get(route('products.index'))->assertInertia($page);
    }

    public function test_products_list_follows_the_active_outlet(): void
    {
        $this->actingAs($this->manager)->inAkar2()->get(route('products.index'))
            ->assertInertia(fn (Assert $page) => $page->where('products.data', fn ($rows) => collect($rows)->firstWhere('id', $this->recipe->id)['cost'] === self::COST_AKAR2));
    }

    public function test_api_returns_the_computed_cost_for_a_recipe_and_the_typed_price_for_a_plain_product(): void
    {
        Sanctum::actingAs($this->manager, ['*']);

        $this->getJson(route('api.products.show', $this->recipe))->assertOk()->assertJsonPath('data.buy_price', self::COST_AKAR1);
        $this->getJson(route('api.products.show', $this->plain))->assertOk()->assertJsonPath('data.buy_price', 7000);
    }

    public function test_export_writes_the_computed_cost_for_a_recipe(): void
    {
        $export = new ProductsExport($this->wh1->id);
        $rows = $export->collection()->mapWithKeys(fn ($p) => [$p->id => $export->map($p)[4]]);

        $this->assertSame(self::COST_AKAR1, $rows[$this->recipe->id]);
        $this->assertSame(7000, $rows[$this->plain->id]);
    }

    public function test_user_without_an_outlet_sees_reference_buy_prices(): void
    {
        $nobody = User::factory()->create();
        $nobody->givePermissionTo(['products-access', 'products-edit']);

        $this->actingAs($nobody)->get(route('products.edit', $this->recipe))
            ->assertInertia(function (Assert $page) {
                $page->where('cost_outlet', null);
                $this->assertSame(2 * 250 + 3 * 100 + 1500, $this->formCost($page->toArray()['props']['products'], $this->recipe));
            });
    }

    private function recipePayload(array $override = []): array
    {
        return array_merge([
            'barcode' => 'NEW-RECIPE', 'sku' => 'NEW-RECIPE', 'title' => 'Latte', 'category_id' => $this->recipe->category_id,
            'sell_price' => 25000, 'stock' => 0, 'tax_rate' => 0, 'is_composite' => true,
            'components' => [['component_product_id' => $this->bean->id, 'qty' => 1]],
        ], $override);
    }

    public function test_recipe_can_be_saved_without_a_buy_price_and_stores_zero(): void
    {
        $this->actingAs($this->manager)->post(route('products.store'), $this->recipePayload())->assertSessionHasNoErrors();
        $this->assertSame(0, (int) Product::where('sku', 'NEW-RECIPE')->value('buy_price'));

        $this->put(route('products.update', $this->recipe), $this->recipePayload([
            'barcode' => $this->recipe->barcode, 'sku' => $this->recipe->sku, 'title' => 'Americano',
            'buy_price' => 6200,
        ]))->assertSessionHasNoErrors();
        $this->assertSame(0, (int) $this->recipe->fresh()->buy_price);
    }

    public function test_plain_product_still_requires_a_buy_price(): void
    {
        $payload = $this->recipePayload(['is_composite' => false, 'components' => []]);

        $this->actingAs($this->manager)->post(route('products.store'), $payload)->assertSessionHasErrors('buy_price');
        $this->put(route('products.update', $this->plain), $payload + ['barcode' => $this->plain->barcode, 'sku' => $this->plain->sku])
            ->assertSessionHasErrors('buy_price');
    }

    public function test_sale_in_the_outlet_freezes_the_cost_the_form_showed(): void
    {
        $this->actingAs($this->manager);
        $formCost = null;
        $this->get(route('products.edit', $this->recipe))->assertInertia(function (Assert $page) use (&$formCost) {
            $formCost = $this->formCost($page->toArray()['props']['products'], $this->recipe);
        });

        app(CashierShiftService::class)->openShift(
            cashier: $this->manager, actor: $this->manager, openingCash: 0, notes: null, warehouseId: $this->wh1->id,
        );
        Cart::create([
            'cashier_id' => $this->manager->id, 'warehouse_id' => $this->wh1->id, 'product_id' => $this->recipe->id,
            'qty' => 1, 'price' => 20000,
        ]);

        $this->post(route('transactions.store'), ['discount' => 0, 'grand_total' => 0, 'cash' => 100000, 'change' => 0])
            ->assertSessionHasNoErrors();

        $this->assertSame($formCost, (int) Transaction::latest('id')->firstOrFail()->details()->value('cost_total'));
        $this->assertSame(self::COST_AKAR1, $formCost);
    }
}
