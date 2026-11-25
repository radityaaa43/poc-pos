<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// #110, #117, #131: a cart refused for stock answers as an Inertia error (not JSON, not a success
// redirect), and the stock check counts every cart row of the product, in pieces.
class CartStockLimitTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Warehouse $warehouse;

    private Product $product;

    private Unit $pcs;

    private Unit $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();

        $category = Category::create(['name' => 'Kategori Test', 'image' => 'c.jpg', 'description' => 'Test']);
        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 0, null, $this->warehouse->id);

        // 30 pcs in the warehouse; 1 BOX = 12 pcs.
        $this->product = Product::create([
            'title' => 'Produk Box',
            'sku' => 'SKU-BOX',
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 30,
            'image' => 'p.jpg',
            'barcode' => 'BC-BOX',
            'description' => 'Test',
            'tax_rate' => 0,
            'category_id' => $category->id,
        ]);
        $this->warehouse->products()->attach($this->product->id, ['stock' => 30]);

        $this->pcs = Unit::where('code', 'PCS')->firstOrFail();
        $this->box = Unit::where('code', 'BOX')->firstOrFail();
        $this->product->units()->sync([
            $this->pcs->id => ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 5000, 'sell_price' => 10000],
            $this->box->id => ['is_base' => false, 'conversion_factor' => 12, 'buy_price' => 60000, 'sell_price' => 108000],
        ]);

        $this->actingAs($this->cashier);
    }

    private function row(Unit $unit, int $qty): Cart
    {
        $factor = $unit->is($this->box) ? 12 : 1;

        return Cart::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'unit_id' => $unit->id,
            'conversion_factor' => $factor,
            'qty' => $qty,
            'price' => ($factor === 12 ? 108000 : 10000) * $qty,
        ]);
    }

    private function add(Unit $unit, int $qty = 1)
    {
        return $this->from(route('transactions.index'))->post(route('transactions.addToCart'), [
            'product_id' => $this->product->id,
            'qty' => $qty,
            'unit_id' => $unit->id,
        ]);
    }

    public function test_update_cart_at_the_stock_limit_is_an_inertia_error_not_json(): void
    {
        $cart = $this->row($this->pcs, 30);

        $this->from(route('transactions.index'))
            ->patch(route('transactions.updateCart', $cart->id), ['qty' => 31])
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasErrors(['message' => 'Stok tidak mencukupi. Tersedia: 30']);

        $this->assertSame(30, (int) $cart->fresh()->qty);
    }

    public function test_add_to_cart_past_the_stock_is_refused_as_an_error(): void
    {
        $this->row($this->pcs, 30);

        $this->add($this->pcs)
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHasErrors(['message' => 'Stok tidak mencukupi. Tersedia: 30'])
            ->assertSessionMissing('success');

        $this->assertSame(30, (int) Cart::sole()->qty);
    }

    public function test_add_to_cart_counts_the_other_unit_rows_in_pieces(): void
    {
        $this->row($this->box, 2); // 24 pcs, 6 left

        $this->add($this->pcs, 7)->assertSessionHasErrors('message');
        $this->assertSame(1, Cart::count());

        $this->add($this->pcs, 6)->assertSessionHasNoErrors();
        $this->assertSame(2, Cart::count());
    }

    public function test_update_cart_counts_the_other_unit_rows_in_pieces(): void
    {
        $this->row($this->box, 2); // 24 pcs
        $pcsRow = $this->row($this->pcs, 6);

        $this->from(route('transactions.index'))
            ->patch(route('transactions.updateCart', $pcsRow->id), ['qty' => 7])
            ->assertSessionHasErrors('message');
        $this->assertSame(6, (int) $pcsRow->fresh()->qty);

        $this->from(route('transactions.index'))
            ->patch(route('transactions.updateCart', $pcsRow->id), ['qty' => 6])
            ->assertSessionHasNoErrors();
    }

    public function test_api_add_to_cart_counts_the_other_unit_rows_in_pieces(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);
        $this->row($this->box, 2); // 24 pcs, 6 left

        $this->postJson('/api/v1/pos/cart', ['product_id' => $this->product->id, 'qty' => 7, 'unit_id' => $this->pcs->id])
            ->assertStatus(422);

        $this->postJson('/api/v1/pos/cart', ['product_id' => $this->product->id, 'qty' => 6, 'unit_id' => $this->pcs->id])
            ->assertOk();
    }

    public function test_api_update_cart_counts_the_other_unit_rows_in_pieces(): void
    {
        Sanctum::actingAs($this->cashier, ['*']);
        $this->row($this->box, 2); // 24 pcs
        $pcsRow = $this->row($this->pcs, 6);

        $this->putJson("/api/v1/pos/cart/{$pcsRow->id}", ['qty' => 7])->assertStatus(422);
        $this->putJson("/api/v1/pos/cart/{$pcsRow->id}", ['qty' => 6])->assertOk();
    }
}
