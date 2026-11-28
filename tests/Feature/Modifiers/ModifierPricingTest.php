<?php

namespace Tests\Feature\Modifiers;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\PricingRule;
use App\Models\TransactionDetail;
use App\Services\LoyaltyService;
use App\Services\PricingService;
use PHPUnit\Framework\Attributes\TestWith;

// REQ-008 AC-11, BR-1: promos, vouchers, tax and points on a line with options. Menu 20.000, Large +5.000.
class ModifierPricingTest extends ModifierSaleTestCase
{
    private function rule(array $attributes): PricingRule
    {
        return PricingRule::create($attributes + [
            'name' => 'Promo', 'is_active' => true, 'priority' => 10, 'target_type' => PricingRule::TARGET_PRODUCT,
            'product_id' => $this->menu->id, 'customer_scope' => PricingRule::SCOPE_ALL,
            'discount_type' => PricingRule::TYPE_PERCENTAGE, 'discount_value' => 0,
        ]);
    }

    private function preview(?Customer $customer = null): array
    {
        return app(PricingService::class)->previewCart(Cart::with('product')->orderBy('id')->get(), $customer, null, $this->outlet);
    }

    #[TestWith([PricingRule::TYPE_PERCENTAGE, 10, 45000])]
    #[TestWith([PricingRule::TYPE_FIXED_PRICE, 10000, 30000])]
    public function test_a_line_discount_on_menu_plus_options(string $type, int $value, int $total): void
    {
        $this->rule(['discount_type' => $type, 'discount_value' => $value]);
        $this->add(['Large'], 2);

        $item = $this->preview()['items'][0];

        $this->assertSame([20000, 5000, 25000, $total], [$item['menu_unit_price'], $item['option_unit_total'], $item['base_unit_price'], $item['line_total']]);
        $this->assertSame(['Large'], array_column($item['options'], 'name'));
    }

    #[TestWith([['Regular'], null, 10000])]
    #[TestWith([['Large'], 'Tanpa gula', 15000])]
    public function test_qty_break_counts_every_line_of_the_same_menu(array $options, ?string $note, int $lastTotal): void
    {
        $rule = $this->rule(['kind' => PricingRule::KIND_QTY_BREAK, 'target_type' => PricingRule::TARGET_ALL, 'product_id' => null]);
        $rule->qtyBreaks()->create(['min_qty' => 3, 'discount_type' => PricingRule::TYPE_PERCENTAGE, 'discount_value' => 50, 'sort_order' => 0]);
        $this->add(['Large'], 2);
        $this->post(route('transactions.addToCart'), ['product_id' => $this->product('Teh', 8000, 5)->id, 'qty' => 1]);
        // As on a single line before options: a box (non-base unit) takes no rule and does not count.
        $box = Cart::create([
            'cashier_id' => $this->cashier->id, 'product_id' => $this->menu->id,
            'qty' => 1, 'conversion_factor' => 12, 'price' => 200000,
        ]);

        $this->assertSame([50000, 8000, 200000], array_column($this->preview()['items'], 'line_total'));

        $box->delete();
        $this->add($options, 1, $note);

        $this->assertSame([30000, 8000, $lastTotal], array_column($this->preview()['items'], 'line_total'));
        $this->checkout()->assertSessionHasNoErrors();
        $this->assertEquals([30000, 8000, $lastTotal], TransactionDetail::orderBy('id')->pluck('price')->all());
    }

    // The preview loads the cart newest first and the sale does not order it: both must use the same lines.
    public function test_preview_total_equals_the_sold_total_with_split_lines(): void
    {
        $this->rule(['kind' => PricingRule::KIND_BUNDLE_PRICE, 'discount_value' => 30000, 'priority' => 20])
            ->bundleItems()->create(['product_id' => $this->menu->id, 'quantity' => 2]);
        $this->rule(['discount_value' => 10]);
        $this->add(['Large']);
        $this->travel(1)->minutes();
        $this->add(['Regular'], 2);

        $previewed = $this->postJson(route('transactions.pricing-preview'))->json('data.summary.subtotal_after_promo');
        $this->checkout()->assertSessionHasNoErrors();

        $this->assertSame([53000, 53000], [$previewed, (int) TransactionDetail::sum('price')]);
    }

    public function test_buy_x_get_y_frees_only_the_menu_price(): void
    {
        $rule = $this->rule(['kind' => PricingRule::KIND_BUY_X_GET_Y]);
        $rule->buyGetItems()->createMany([
            ['role' => 'buy', 'product_id' => $this->menu->id, 'quantity' => 1],
            ['role' => 'get', 'product_id' => $this->menu->id, 'quantity' => 1],
        ]);
        $this->add(['Large'], 2);

        $this->assertSame(30000, $this->preview()['items'][0]['line_total']);
    }

    public function test_bundle_price_sets_only_the_menu_part(): void
    {
        $rule = $this->rule(['kind' => PricingRule::KIND_BUNDLE_PRICE, 'discount_value' => 30000]);
        $rule->bundleItems()->create(['product_id' => $this->menu->id, 'quantity' => 2]);
        $this->add(['Large']);
        $this->add(['Regular', 'Boba']);

        $this->assertSame(38000, $this->preview()['summary']['subtotal_after_promo']);
    }

    public function test_tax_and_points_use_the_full_line(): void
    {
        $this->menu->update(['tax_rate' => 10, 'tax_type' => 'exclusive']);
        $customer = Customer::create(['name' => 'Member', 'no_telp' => '081200000009', 'address' => 'x', 'is_loyalty_member' => true]);
        $this->add(['Large'], 2);

        $summary = app(LoyaltyService::class)->previewCheckout($this->preview($customer), $customer, [], null, $this->outlet)['summary'];
        $this->assertSame([50000, 5000, 55000], [$summary['subtotal_after_promo'], $summary['tax_total'], $summary['grand_total']]);

        $this->checkout(['customer_id' => $customer->id])->assertSessionHasNoErrors();
        $this->assertSame($summary['points_earned_preview'], (int) $customer->fresh()->loyalty_points);
        $this->assertGreaterThan(0, $summary['points_earned_preview']);
    }
}
