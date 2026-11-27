<?php

namespace Tests\Feature\Modifiers;

use App\Models\Cart;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;

// REQ-008 AC-06..AC-10, AC-13: choosing options on the POS cart.
class PosModifierCheckoutTest extends ModifierSaleTestCase
{
    public function test_pos_page_offers_the_groups_with_availability_and_no_recipe(): void
    {
        $this->setStock($this->milk, 1);

        $this->get(route('transactions.index'))->assertInertia(fn (Assert $page) => $page
            ->where("modifierGroups.{$this->menu->id}.0.name", 'Size')
            ->where("modifierGroups.{$this->menu->id}.0.options.0.available", true)
            ->where("modifierGroups.{$this->menu->id}.0.options.1.available", false)
            ->missing("modifierGroups.{$this->menu->id}.0.options.1.components")
            ->where('products.0.menu_price', 20000)
            ->where('products.0.modifiers_sold_out', false));
    }

    public function test_line_price_is_menu_plus_options(): void
    {
        $this->add(['Large', 'Boba'], 2, 'less ice')->assertSessionHasNoErrors();

        $cart = Cart::sole();
        $this->assertSame(56000, (int) $cart->price);
        $this->assertSame(8000, $cart->modifier_total);
        $this->assertSame('less ice', $cart->note);
        $this->assertSame([$this->option('Large')->id, $this->option('Boba')->id], $cart->modifier_option_ids);
    }

    public function test_client_price_is_ignored(): void
    {
        $this->add(['Large'], 1, null, ['sell_price' => 1, 'price' => 1, 'modifier_total' => 0]);

        $this->assertSame(25000, (int) Cart::sole()->price);
    }

    public function test_selection_rules_are_enforced_on_the_server(): void
    {
        $this->add([])->assertSessionHasErrors(['modifiers' => $this->refusal('group_min', ['group' => 'Size', 'min' => 1])]);
        $this->add(['Regular', 'Boba', 'Jelly', 'Cheese'])
            ->assertSessionHasErrors(['modifiers' => $this->refusal('group_max', ['group' => 'Topping', 'max' => 2])]);
        $this->add(['Regular'], 1, str_repeat('a', 101))->assertSessionHasErrors('note');

        $this->option('Jelly')->disabledOutlets()->attach($this->outlet->id);
        $this->add(['Regular', 'Jelly'])
            ->assertSessionHasErrors(['modifiers' => $this->refusal('option_unavailable', ['option' => 'Jelly'])]);

        $this->assertSame(0, Cart::count());
    }

    public function test_option_without_ingredients_is_refused(): void
    {
        $this->setStock($this->milk, 3);
        $this->add(['Large'])->assertSessionHasNoErrors();

        // The second portion needs 4 milk.
        $this->add(['Large'])->assertSessionHasErrors(['modifiers' => $this->refusal('option_sold_out', ['option' => 'Large'])]);

        $this->assertSame(1, (int) Cart::sole()->qty);
    }

    public function test_menu_with_required_group_out_cannot_be_added(): void
    {
        $this->option('Regular')->components()->attach($this->milk->id, ['qty' => 1]);
        $this->setStock($this->milk, 0);

        $this->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page->where('products.0.modifiers_sold_out', true));
        $this->add(['Regular'])->assertSessionHasErrors('modifiers');
        $this->assertSame(0, Cart::count());
    }

    public function test_cart_lines_merge_only_on_same_options_and_note(): void
    {
        $this->add(['Large', 'Boba'], 1, 'less ice');
        $this->add(['Boba', 'Large'], 1, 'less ice');
        $this->add(['Large', 'Boba']);
        $this->add(['Large']);

        $this->assertSame([[2, 56000], [1, 28000], [1, 25000]], Cart::orderBy('id')->get()
            ->map(fn (Cart $cart) => [(int) $cart->qty, (int) $cart->price])->all());
    }

    public function test_changing_the_quantity_keeps_the_option_price(): void
    {
        $this->add(['Large']);
        $cart = Cart::sole();

        $this->patch(route('transactions.updateCart', $cart->id), ['qty' => 3])->assertSessionHasNoErrors();
        $this->assertSame(75000, (int) $cart->fresh()->price);
    }

    public function test_mobile_cart_never_merges_into_a_line_with_options(): void
    {
        $this->add(['Large']);
        Sanctum::actingAs($this->cashier, ['*']);

        $this->postJson('/api/v1/pos/cart', ['product_id' => $this->menu->id, 'qty' => 1])->assertOk();

        $this->assertSame([25000, 20000], Cart::orderBy('id')->pluck('price')->map(fn ($price) => (int) $price)->all());
    }
}
