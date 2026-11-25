<?php

namespace Tests\Feature\Products;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Coffee-shop recipes: a menu item (composite) built from raw materials.
 */
class RecipeProductTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected Warehouse $warehouse;

    protected Category $category;

    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $this->category = Category::create(['name' => 'Kopi']);

        $this->cashier = User::factory()->create();
        $this->cashier->givePermissionTo(['transactions-access', 'cashier-shifts-access', 'cashier-shifts-open']);
        CashierShift::create([
            'user_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'expected_cash' => 100000,
            'status' => 'open',
            'warehouse_id' => $this->warehouse->id,
        ]);
    }

    private function product(array $attributes, int $stock): Product
    {
        self::$seq++;
        $product = Product::create(array_merge([
            'category_id' => $this->category->id,
            'barcode' => 'BC-RCP-'.self::$seq,
            'sku' => 'SKU-RCP-'.self::$seq,
            'title' => 'Produk '.self::$seq,
            'description' => '-',
            'buy_price' => 0,
            'sell_price' => 0,
            'stock' => $stock,
            'tax_rate' => 0,
        ], $attributes));

        if ($stock > 0) {
            $this->warehouse->products()->attach($product->id, ['stock' => $stock]);
        }

        return $product;
    }

    /** Americano = 18 g beans + 200 ml water + 1 cup, sold at Rp 22.000. */
    private function americano(int $beanStock = 1000, int $sellPrice = 22000): array
    {
        $bean = $this->product(['title' => 'Biji Kopi (gram)', 'buy_price' => 250, 'is_raw_material' => true], $beanStock);
        $water = $this->product(['title' => 'Air (ml)', 'buy_price' => 1, 'is_raw_material' => true], 10000);
        $cup = $this->product(['title' => 'Cup (pcs)', 'buy_price' => 1500, 'is_raw_material' => true], 100);

        $americano = $this->product(['title' => 'Americano', 'sell_price' => $sellPrice, 'is_composite' => true], 0);
        $americano->components()->attach([
            $bean->id => ['qty' => 18],
            $water->id => ['qty' => 200],
            $cup->id => ['qty' => 1],
        ]);

        return [$americano, $bean, $water, $cup];
    }

    private function posProducts(): array
    {
        return $this->actingAs($this->cashier)
            ->get(route('transactions.index'))
            ->assertOk()
            ->viewData('page')['props']['products'];
    }

    public function test_recipe_sells_at_menu_price_and_consumes_ingredients(): void
    {
        [$americano, $bean, $water, $cup] = $this->americano();

        $this->actingAs($this->cashier)
            ->post(route('transactions.addToCart'), ['product_id' => $americano->id, 'qty' => 2])
            ->assertRedirect(route('transactions.index'));

        $cart = Cart::where('cashier_id', $this->cashier->id)->firstOrFail();
        $this->assertSame(44000, (int) $cart->price);

        $this->actingAs($this->cashier)
            ->post(route('transactions.store'), ['cash' => 50000]);

        $transaction = Transaction::latest('id')->firstOrFail();
        $this->assertSame(44000, (int) $transaction->grand_total);
        $this->assertSame('paid', $transaction->payment_status);

        // HPP = 2 × (18×250 + 200×1 + 1×1500) = 12.400 → laba 31.600
        $this->assertSame(31600, (int) $transaction->profits()->sum('total'));

        $stockOf = fn (Product $p) => (int) $p->fresh()->warehouses()->where('warehouse_id', $this->warehouse->id)->first()->pivot->stock;
        $this->assertSame(1000 - 36, $stockOf($bean));
        $this->assertSame(10000 - 400, $stockOf($water));
        $this->assertSame(100 - 2, $stockOf($cup));
        $this->assertSame(964, $bean->fresh()->stock);
    }

    public function test_composite_without_own_price_still_uses_component_total(): void
    {
        $a = $this->product(['sell_price' => 15000], 10);
        $b = $this->product(['sell_price' => 5000], 10);
        $bundle = $this->product(['title' => 'Paket', 'is_composite' => true, 'sell_price' => 0], 0);
        $bundle->components()->attach([$a->id => ['qty' => 1], $b->id => ['qty' => 2]]);

        $this->assertSame(25000, $bundle->fresh()->compositeSellPrice());
    }

    public function test_pos_lists_recipes_with_portions_and_hides_raw_materials(): void
    {
        [$americano] = $this->americano(beanStock: 100); // 100 g → 5 portions
        $regular = $this->product(['title' => 'Croissant', 'sell_price' => 22000], 7);

        $products = collect($this->posProducts())->keyBy('id');

        $this->assertTrue($products->has($americano->id));
        $this->assertSame(5, $products[$americano->id]['stock']);
        $this->assertSame(22000, $products[$americano->id]['sell_price']);
        $this->assertSame(7, $products[$regular->id]['stock']);
        $this->assertCount(2, $products, 'raw materials must not be offered on the POS');
    }

    public function test_pos_hides_recipe_when_an_ingredient_runs_out(): void
    {
        [$americano] = $this->americano(beanStock: 10); // < 18 g

        $ids = collect($this->posProducts())->pluck('id');

        $this->assertNotContains($americano->id, $ids);
    }

    public function test_raw_material_cannot_be_added_to_cart(): void
    {
        [, $bean] = $this->americano();

        $this->actingAs($this->cashier)
            ->from(route('transactions.index'))
            ->post(route('transactions.addToCart'), ['product_id' => $bean->id, 'qty' => 1])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, Cart::count());
    }

    public function test_checkout_rejects_raw_material_already_in_cart(): void
    {
        [, $bean] = $this->americano();
        Cart::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $bean->id,
            'unit_id' => null,
            'conversion_factor' => 1,
            'qty' => 1,
            'price' => 0,
        ]);

        $this->actingAs($this->cashier)
            ->post(route('transactions.store'), ['cash' => 0])
            ->assertSessionHasErrors('stock');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(1000, $bean->fresh()->stock);
    }

    public function test_recipe_quantity_can_be_updated_in_cart(): void
    {
        [$americano] = $this->americano();

        $this->actingAs($this->cashier)
            ->post(route('transactions.addToCart'), ['product_id' => $americano->id, 'qty' => 1]);
        $cart = Cart::firstOrFail();

        $this->actingAs($this->cashier)
            ->patch(route('transactions.updateCart', $cart->id), ['qty' => 3])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, (int) $cart->fresh()->qty);
        $this->assertSame(66000, (int) $cart->fresh()->price);
    }

    public function test_api_pos_lists_recipe_and_rejects_raw_material(): void
    {
        [$americano, $bean] = $this->americano();
        Sanctum::actingAs($this->cashier, ['*']);

        $rows = collect($this->getJson('/api/v1/pos/products')->assertOk()->json('data'))->keyBy('id');
        $this->assertTrue($rows->has($americano->id));
        $this->assertSame(22000, $rows[$americano->id]['sell_price']);
        // limited by the scarcest ingredient: water 10000 ml / 200 ml = 50 (beans would allow 55)
        $this->assertSame(50, $rows[$americano->id]['stock']);
        $this->assertFalse($rows->has($bean->id));

        $this->postJson('/api/v1/pos/cart', ['product_id' => $bean->id, 'qty' => 1])->assertStatus(422);
        $this->postJson('/api/v1/pos/cart', ['product_id' => $americano->id, 'qty' => 1])->assertSuccessful();
        $this->assertSame(22000, (int) Cart::where('product_id', $americano->id)->value('price'));
    }

    public function test_dine_in_menu_renders_recipes_without_raw_materials(): void
    {
        [$americano, $bean] = $this->americano();
        $table = DiningTable::create(['name' => 'Meja 1', 'is_active' => true, 'capacity' => 2, 'sort_order' => 0]);

        $props = $this->get(route('dine.menu', $table->token))->assertOk()->viewData('page')['props'];
        $ids = collect($props['products'])->pluck('id');

        $this->assertContains($americano->id, $ids);
        $this->assertNotContains($bean->id, $ids);
        $this->assertSame(22000, collect($props['products'])->firstWhere('id', $americano->id)['sell_price']);
    }

    public function test_product_form_saves_raw_material_flag(): void
    {
        $admin = User::where('email', 'arya@gmail.com')->firstOrFail();
        $admin->markEmailAsVerified();

        $this->actingAs($admin)->post(route('products.store'), [
            'barcode' => 'RAW-SUSU',
            'sku' => 'RAW-SUSU',
            'title' => 'Susu Segar (ml)',
            'category_id' => $this->category->id,
            'buy_price' => 20,
            'sell_price' => 0,
            'stock' => 5000,
            'warehouse_id' => $this->warehouse->id,
            'tax_rate' => 0,
            'is_raw_material' => '1',
            'purchased_by' => 'central',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Product::where('sku', 'RAW-SUSU')->firstOrFail()->is_raw_material);
    }
}
