<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\PricingRuleBuyGetItem;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\CheckoutService;
use App\Support\Checkout\CheckoutContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// #106: a line sold in a non-base unit (BOX) is charged the unit's own sell price.
class UnitLinePricingTest extends TestCase
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

        // PCS base at 10.000, BOX = 12 pcs at 108.000; 30 pcs in the warehouse.
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

    private function addToCart(int $unitId, int $qty = 1): void
    {
        $this->post(route('transactions.addToCart'), [
            'product_id' => $this->product->id,
            'qty' => $qty,
            'unit_id' => $unitId,
        ])->assertSessionHasNoErrors();
    }

    private function checkout()
    {
        return app(CheckoutService::class)->execute(new CheckoutContext(
            userId: $this->cashier->id,
            customer: null,
            voucher: null,
            manualDiscount: 0,
            shippingCost: 0,
            requestedRedeemPoints: 0,
            isPayLater: false,
            dueDate: null,
            orderType: null,
            note: null,
            customerNpwp: null,
            isCashPayment: true,
            cashAmount: 1000000,
            paymentGateway: null,
            useTenders: false,
            tenderInput: [],
            outlet: null,
            bankAccountId: null,
        ));
    }

    private function pricingPreviewTotal(): int
    {
        return (int) $this->postJson(route('transactions.pricing-preview'), [])
            ->assertOk()
            ->json('data.summary.subtotal_after_promo');
    }

    public function test_checkout_of_one_box_charges_the_box_price_and_deducts_its_pieces(): void
    {
        $this->addToCart($this->box->id);

        $this->assertSame(108000, $this->pricingPreviewTotal());

        $result = $this->checkout();

        $this->assertDatabaseHas('transaction_details', [
            'transaction_id' => $result->transaction->id,
            'unit_id' => $this->box->id,
            'qty' => 1,
            'base_unit_price' => 108000,
            'unit_price' => 108000,
            'price' => 108000,
        ]);
        $this->assertSame(108000, (int) $result->transaction->grand_total);
        $this->assertSame(18, (int) $this->warehouse->products()->find($this->product->id)->pivot->stock);
    }

    public function test_base_unit_sale_is_unchanged(): void
    {
        $this->addToCart($this->pcs->id, 3);

        $result = $this->checkout();

        $this->assertDatabaseHas('transaction_details', [
            'transaction_id' => $result->transaction->id,
            'unit_id' => $this->pcs->id,
            'qty' => 3,
            'base_unit_price' => 10000,
            'price' => 30000,
        ]);
        $this->assertSame(27, (int) $this->warehouse->products()->find($this->product->id)->pivot->stock);
    }

    public function test_adding_pieces_after_a_box_keeps_two_cart_rows(): void
    {
        $this->addToCart($this->box->id);
        $this->addToCart($this->pcs->id, 2);
        $this->addToCart($this->box->id);

        $carts = Cart::orderBy('id')->get();
        $this->assertCount(2, $carts);
        $this->assertSame([$this->box->id, 2, 216000], [(int) $carts[0]->unit_id, (int) $carts[0]->qty, (int) $carts[0]->price]);
        $this->assertSame([$this->pcs->id, 2, 20000], [(int) $carts[1]->unit_id, (int) $carts[1]->qty, (int) $carts[1]->price]);

        $result = $this->checkout();

        $this->assertSame(236000, (int) $result->transaction->grand_total);
        // 2 boxes (24 pcs) + 2 pcs out of 30.
        $this->assertSame(4, (int) $this->warehouse->products()->find($this->product->id)->pivot->stock);
    }

    public function test_updating_a_box_line_quantity_keeps_the_box_price(): void
    {
        $this->addToCart($this->box->id);
        $cart = Cart::firstOrFail();

        $this->patch(route('transactions.updateCart', $cart->id), ['qty' => 2])->assertSessionHasNoErrors();

        $this->assertSame(216000, $this->pricingPreviewTotal());
    }

    public function test_pricing_rules_do_not_touch_a_box_line_but_still_hit_pieces(): void
    {
        // Per-piece promo: pieces cost 8.000 each. It must not turn a 108.000 box into 8.000.
        PricingRule::create([
            'name' => 'Harga promo per pcs',
            'is_active' => true,
            'priority' => 100,
            'target_type' => 'product',
            'product_id' => $this->product->id,
            'customer_scope' => 'all',
            'discount_type' => 'fixed_price',
            'discount_value' => 8000,
        ]);

        $this->addToCart($this->box->id);
        $this->assertSame(108000, $this->pricingPreviewTotal());

        $this->addToCart($this->pcs->id, 2);
        $this->assertSame(108000 + 16000, $this->pricingPreviewTotal());

        $this->assertSame(124000, (int) $this->checkout()->transaction->grand_total);
    }

    public function test_buy_x_get_y_counts_pieces_not_boxes(): void
    {
        $rule = PricingRule::create([
            'name' => 'Beli 1 gratis 1',
            'kind' => PricingRule::KIND_BUY_X_GET_Y,
            'is_active' => true,
            'priority' => 100,
            'target_type' => 'product',
            'product_id' => $this->product->id,
            'customer_scope' => 'all',
            'discount_type' => 'percentage',
            'discount_value' => 0,
        ]);
        $rule->buyGetItems()->createMany([
            ['product_id' => $this->product->id, 'role' => PricingRuleBuyGetItem::ROLE_BUY, 'quantity' => 1],
            ['product_id' => $this->product->id, 'role' => PricingRuleBuyGetItem::ROLE_GET, 'quantity' => 1],
        ]);

        $this->addToCart($this->box->id, 2);

        $this->assertSame(216000, $this->pricingPreviewTotal());
    }
}
