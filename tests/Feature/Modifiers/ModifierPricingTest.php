<?php

namespace Tests\Feature\Modifiers;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\PricingRule;
use App\Services\LoyaltyService;
use App\Services\PricingService;

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

    public function test_preview_splits_menu_and_option_price(): void
    {
        $this->add(['Large', 'Boba']);

        $item = $this->preview()['items'][0];

        $this->assertSame([20000, 8000, 28000, 28000], [$item['menu_unit_price'], $item['option_unit_total'], $item['base_unit_price'], $item['line_total']]);
        $this->assertSame(['Large', 'Boba'], array_column($item['options'], 'name'));
    }

    public function test_percent_promo_applies_to_menu_plus_options(): void
    {
        $this->rule(['discount_value' => 10]);
        $this->add(['Large'], 2);

        $this->assertSame(45000, $this->preview()['items'][0]['line_total']);
    }

    public function test_fixed_price_and_qty_break_replace_only_the_menu_price(): void
    {
        $rule = $this->rule(['discount_type' => PricingRule::TYPE_FIXED_PRICE, 'discount_value' => 10000]);
        $this->add(['Large'], 2);

        $this->assertSame(30000, $this->preview()['items'][0]['line_total']);

        $rule->update(['kind' => PricingRule::KIND_QTY_BREAK]);
        $rule->qtyBreaks()->create(['min_qty' => 2, 'discount_type' => PricingRule::TYPE_PERCENTAGE, 'discount_value' => 50, 'sort_order' => 0]);

        $this->assertSame(30000, $this->preview()['items'][0]['line_total']);
    }

    public function test_buy_x_get_y_frees_only_the_menu_price(): void
    {
        $rule = $this->rule(['kind' => PricingRule::KIND_BUY_X_GET_Y]);
        $rule->buyGetItems()->createMany([
            ['role' => 'buy', 'product_id' => $this->menu->id, 'quantity' => 1],
            ['role' => 'get', 'product_id' => $this->menu->id, 'quantity' => 1],
        ]);
        $this->add(['Large'], 2);

        // 2 x 25.000, one menu (20.000) free: its Large is still charged.
        $this->assertSame(30000, $this->preview()['items'][0]['line_total']);
    }

    public function test_bundle_price_sets_only_the_menu_part(): void
    {
        $rule = $this->rule(['kind' => PricingRule::KIND_BUNDLE_PRICE, 'discount_value' => 30000]);
        $rule->bundleItems()->create(['product_id' => $this->menu->id, 'quantity' => 2]);
        $this->add(['Large']);
        $this->add(['Regular', 'Boba']);

        // Bundle 30.000 for both menus, plus Large 5.000 and Boba 3.000.
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
