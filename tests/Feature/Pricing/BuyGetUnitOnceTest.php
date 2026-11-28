<?php

namespace Tests\Feature\Pricing;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** A unit in the cart counts once: as "buy" or "get" of one rule, or as one bundle item (#446). */
class BuyGetUnitOnceTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create();
    }

    public function test_one_unit_is_not_free(): void
    {
        $product = $this->product();
        $this->buyOneGetOne($product, $product);
        $this->cart($product, 1);

        $this->assertTotals($this->preview(), 60000, 0, 0);
    }

    public function test_two_units_one_free(): void
    {
        $product = $this->product();
        $this->buyOneGetOne($product, $product);
        $this->cart($product, 2);

        $this->assertTotals($this->preview(), 60000, 60000, 1);
    }

    public function test_three_units_one_free(): void
    {
        $product = $this->product();
        $this->buyOneGetOne($product, $product);
        $this->cart($product, 3);

        $this->assertTotals($this->preview(), 120000, 60000, 1);
    }

    public function test_rule_repeats_per_pair(): void
    {
        $product = $this->product();
        $this->buyOneGetOne($product, $product);
        $this->cart($product, 4);

        $this->assertTotals($this->preview(), 120000, 120000, 2);
    }

    public function test_units_over_lines_counted_once(): void
    {
        $product = $this->product();
        $this->buyOneGetOne($product, $product);
        PricingRule::create([
            'name' => 'Diskon 10 Persen',
            'is_active' => true,
            'priority' => 100,
            'target_type' => 'product',
            'product_id' => $product->id,
            'customer_scope' => 'all',
            'discount_type' => 'percentage',
            'discount_value' => 10,
        ]);
        $first = $this->cart($product, 1);
        $second = $this->cart($product, 2);

        $preview = $this->preview();

        // One unit bought, one free, and 10% only on the third.
        $this->assertTotals($preview, 114000, 66000, 1);
        $this->assertSame(
            [$first->id => 60000, $second->id => 54000],
            array_column($preview['items'], 'line_total', 'cart_id')
        );
    }

    public function test_cross_product_unchanged(): void
    {
        [$buy, $get] = [$this->product(), $this->product()];
        $this->buyOneGetOne($buy, $get);
        $this->cart($buy, 1);
        $this->cart($get, 1);

        $this->assertTotals($this->preview(), 60000, 60000, 1);
    }

    public function test_two_rules_never_share_a_unit(): void
    {
        $product = $this->product();
        $this->buyOneGetOne($product, $product);
        $this->buyOneGetOne($product, $product);
        $this->cart($product, 3);

        $this->assertTotals($this->preview(), 120000, 60000, 1);
    }

    public function test_bundle_needs_each_listed_unit(): void
    {
        $product = $this->product();
        $this->rule(PricingRule::KIND_BUNDLE_PRICE, 'fixed_price', 100000)->bundleItems()->createMany([
            ['product_id' => $product->id, 'quantity' => 1, 'sort_order' => 0],
            ['product_id' => $product->id, 'quantity' => 1, 'sort_order' => 1],
        ]);
        $cart = $this->cart($product, 1);

        $this->assertTotals($this->preview(), 60000, 0, 0);

        $cart->update(['qty' => 2, 'price' => 120000]);

        $this->assertTotals($this->preview(), 100000, 20000, 1);
    }

    public function test_rule_form_preview_samples_every_listed_unit_of_a_bundle(): void
    {
        $product = $this->product();
        $this->allow('pricing-rules-access');

        $response = $this->actingAs($this->cashier)->postJson(route('pricing-rules.preview'), [
            'name' => 'Paket Isi Dua',
            'kind' => PricingRule::KIND_BUNDLE_PRICE,
            'priority' => 100,
            'target_type' => 'all',
            'customer_scope' => 'all',
            'discount_type' => 'fixed_price',
            'discount_value' => 100000,
            'bundle_items' => [
                ['product_id' => $product->id, 'quantity' => 1],
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertOk();

        $this->assertTotals($response->json('data'), 100000, 20000, 1);
    }

    public function test_recorded_sale_not_recalculated(): void
    {
        $this->allow('transactions-access');
        CashierShift::create([
            'user_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'opened_at' => now(),
            'opening_cash' => 0,
            'expected_cash' => 0,
            'status' => 'open',
        ]);
        $product = $this->product();
        $this->buyOneGetOne($product, $product);
        $this->cart($product, 3);

        $shown = $this->actingAs($this->cashier)
            ->postJson(route('transactions.pricing-preview'))
            ->assertOk()
            ->json('data.summary.subtotal_after_promo');
        $this->post(route('transactions.store'), ['cash' => 200000])->assertSessionHasNoErrors();

        // Checkout charges what the preview showed.
        $this->assertSame(120000, $shown);
        $this->assertSame(120000, (int) Transaction::sole()->grand_total);
        $recorded = TransactionDetail::all()->toArray();
        $this->assertSame(120000, (int) $recorded[0]['price']);

        $this->cart($product, 1);
        $this->preview();

        $this->assertSame($recorded, TransactionDetail::all()->toArray());
    }

    private function assertTotals(array $preview, int $total, int $discount, int $groups): void
    {
        $this->assertSame($total, $preview['summary']['subtotal_after_promo']);
        $this->assertSame($discount, $preview['summary']['promo_discount_total']);
        $this->assertCount($groups, $preview['applied_groups']);
    }

    private function preview(): array
    {
        return app(PricingService::class)->previewCart(Cart::with('product')->orderBy('id')->get());
    }

    private function allow(string $permission): void
    {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        $this->cashier->givePermissionTo($permission);
    }

    private function product(): Product
    {
        $category = Category::create(['name' => 'Menu '.Str::random(6), 'description' => '-', 'image' => 'c.png']);

        return Product::create([
            'category_id' => $category->id,
            'image' => 'p.png',
            'barcode' => 'BRCD-'.Str::random(10),
            'sku' => 'SKU-'.Str::random(8),
            'title' => 'Menu',
            'description' => '-',
            'buy_price' => 45000,
            'sell_price' => 60000,
            'stock' => 25,
            'tax_rate' => 0,
        ]);
    }

    private function cart(Product $product, int $qty): Cart
    {
        return Cart::create([
            'cashier_id' => $this->cashier->id,
            'product_id' => $product->id,
            'qty' => $qty,
            'price' => $product->sell_price * $qty,
        ]);
    }

    private function rule(string $kind, string $discountType, int $value): PricingRule
    {
        return PricingRule::create([
            'name' => 'Promo',
            'kind' => $kind,
            'is_active' => true,
            'priority' => 400,
            'target_type' => 'all',
            'customer_scope' => 'all',
            'discount_type' => $discountType,
            'discount_value' => $value,
        ]);
    }

    private function buyOneGetOne(Product $buy, Product $get): void
    {
        $this->rule(PricingRule::KIND_BUY_X_GET_Y, 'fixed_amount', 0)->buyGetItems()->createMany([
            ['product_id' => $buy->id, 'role' => 'buy', 'quantity' => 1, 'sort_order' => 0],
            ['product_id' => $get->id, 'role' => 'get', 'quantity' => 1, 'sort_order' => 1],
        ]);
    }
}
