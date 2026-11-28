<?php

namespace Tests\Feature\Modifiers;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Services\ModifierGroupService;
use App\Services\ThermalPrintService;
use App\Support\Modifiers\ModifierCatalog;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Mockery;

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
            ->where('products.0.menu_price', 20000));

        // Switched off at the outlet, the default is not offered, so nothing is preselected; the menu still sells.
        $this->option('Regular')->disabledOutlets()->attach($this->outlet->id);
        $this->setStock($this->milk, 10);
        $this->get(route('transactions.index'))->assertInertia(fn (Assert $page) => $page
            ->where('products.0.modifiers_sold_out', false)
            ->has("modifierGroups.{$this->menu->id}.0.options", 1)
            ->where("modifierGroups.{$this->menu->id}.0.options.0.is_default", false));
        $this->add(['Large'])->assertSessionHasNoErrors();
    }

    public function test_line_price_is_menu_plus_options_and_ignores_the_client(): void
    {
        $this->add(['Large', 'Boba'], 2, 'less ice', ['sell_price' => 1, 'price' => 1, 'modifier_total' => 0])->assertSessionHasNoErrors();

        $cart = Cart::sole();
        $this->assertSame(56000, (int) $cart->price);
        $this->assertSame(8000, $cart->modifier_total);
        $this->assertSame('less ice', $cart->note);
        $this->assertSame([$this->option('Large')->id, $this->option('Boba')->id], $cart->modifier_option_ids);

        // Another request adds a portion between this one's read of the line and its write: both count.
        Cart::retrieved(fn (Cart $line) => DB::table('carts')->where('id', $line->id)->increment('qty'));
        $this->add(['Large', 'Boba'], 1, 'less ice');
        $this->assertSame(4, (int) DB::table('carts')->value('qty'));
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

        // BR-6 also holds for a line without any selection, as the mobile API and offline sync add it.
        Cart::create(['cashier_id' => $this->cashier->id, 'product_id' => $this->menu->id, 'qty' => 1, 'price' => 20000]);
        ModifierOption::whereIn('name', ['Regular', 'Large'])->get()->each->delete();
        $this->checkout()->assertSessionHasErrors(['modifiers' => $this->refusal('group_unavailable', ['group' => 'Size'])]);
        $this->assertSame(0, Transaction::count());
    }

    public function test_option_without_ingredients_is_refused(): void
    {
        $this->setStock($this->milk, 3);
        $this->add(['Large'])->assertSessionHasNoErrors();

        $this->add(['Large'])->assertSessionHasErrors(['modifiers' => $this->refusal('option_sold_out', ['option' => 'Large'])]);

        $this->assertSame(1, (int) Cart::sole()->qty);
    }

    // Short of ingredients (AC-09) or switched off at the outlet (BR-6): the group cannot reach its minimum.
    public function test_required_group_with_too_few_options_at_an_outlet_blocks_the_menu(): void
    {
        ModifierGroup::where('name', 'Size')->update(['min_select' => 2, 'max_select' => 2]);
        $soldOut = fn (bool $expected) => $this->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page->where('products.0.modifiers_sold_out', $expected));
        $soldOut(false);

        $this->setStock($this->milk, 1);
        $soldOut(true);
        $this->add(['Regular', 'Large'])->assertSessionHasErrors(['modifiers' => $this->refusal('option_sold_out', ['option' => 'Large'])]);

        $this->setStock($this->milk, 10);
        $this->add(['Regular', 'Large'])->assertSessionHasNoErrors();
        $orderable = fn () => ModifierCatalog::forOutlet($this->outlet, collect([$this->menu->id]))[$this->menu->id]['orderable'];
        $this->assertTrue($orderable());

        $this->option('Large')->disabledOutlets()->attach($this->outlet->id);
        $this->assertFalse($orderable());
        $soldOut(true);
        $refused = ['modifiers' => $this->refusal('group_unavailable', ['group' => 'Size'])];
        $this->add(['Regular'])->assertSessionHasErrors($refused);
        $this->checkout()->assertSessionHasErrors($refused);
        $this->assertSame([1, 0], [Cart::count(), Transaction::count()]);
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

    public function test_quantity_changes_and_the_mobile_cart_leave_option_lines_whole(): void
    {
        $this->add(['Large']);
        $this->patch(route('transactions.updateCart', Cart::sole()->id), ['qty' => 2])->assertSessionHasNoErrors();
        $this->add(['Large', 'Boba']);
        Sanctum::actingAs($this->cashier, ['*']);
        $this->putJson(route('api.pos.cart.update', Cart::max('id')), ['qty' => 2])->assertOk();

        $this->postJson('/api/v1/pos/cart', ['product_id' => $this->menu->id, 'qty' => 1])->assertOk();

        $this->assertSame([50000, 56000, 20000], Cart::orderBy('id')->pluck('price')->map(fn ($price) => (int) $price)->all());
    }

    public function test_checkout_snapshots_the_options_and_takes_their_stock(): void
    {
        $this->add(['Large', 'Boba'], 2, 'less ice');

        $this->checkout()->assertSessionHasNoErrors();

        $detail = TransactionDetail::sole();
        $this->assertSame([56000, 28000, 8000, 'less ice'], [(int) $detail->price, (int) $detail->base_unit_price, $detail->modifier_total, $detail->note]);
        $this->assertSame(
            [['Size', 'Large', 5000, $this->option('Large')->id], ['Topping', 'Boba', 3000, $this->option('Boba')->id]],
            $detail->modifiers->map(fn ($row) => [$row->group_name, $row->option_name, $row->price_delta, (int) $row->modifier_option_id])->all()
        );
        $this->assertEquals(
            [[['product_id' => $this->milk->id, 'qty_per_unit' => 2]], [['product_id' => $this->boba->id, 'qty_per_unit' => 1]]],
            $detail->modifiers->pluck('components')->all()
        );
        $this->assertSame(14600, (int) $detail->cost_total);
        $this->assertSame([[48, 48], [6, 6], [8, 8]], [$this->stockOf($this->menu), $this->stockOf($this->milk), $this->stockOf($this->boba)]);
        $mutation = StockMutation::where('product_id', $this->milk->id)->sole();
        $this->assertSame(['out', 4, 10, 6], [$mutation->mutation_type, (int) $mutation->qty, (int) $mutation->stock_before, (int) $mutation->stock_after]);
        $this->assertSame('Penjualan '.Transaction::sole()->invoice.' (opsi Large Kopi Susu)', $mutation->notes);
    }

    public function test_option_shortage_rolls_back_the_sale(): void
    {
        $this->add(['Large'], 3);
        $this->add(['Large'], 3, 'less ice');

        $this->checkout()->assertSessionHasErrors(['modifiers' => $this->refusal('option_sold_out', ['option' => 'Large'])]);

        $this->assertSame([0, 2], [Transaction::count(), Cart::count()]);
        $this->assertSame([[50, 50], [10, 10]], [$this->stockOf($this->menu), $this->stockOf($this->milk)]);
        $this->assertSame(0, StockMutation::count());
    }

    public function test_checkout_uses_latest_option_price_and_takes_both_recipes(): void
    {
        $this->menu->update(['is_composite' => true]);
        $this->menu->components()->attach($this->milk->id, ['qty' => 3]);
        $this->add(['Large']);
        $this->option('Large')->update(['price_delta' => 7000]);

        $this->checkout()->assertSessionHasNoErrors();

        $this->assertSame(27000, (int) Transaction::sole()->grand_total);
        $this->assertSame(7000, TransactionDetail::sole()->modifiers->sole()->price_delta);
        $this->assertSame([5, 5], $this->stockOf($this->milk));
    }

    public function test_checkout_refuses_an_option_no_longer_available(): void
    {
        $this->add(['Regular', 'Boba']);
        $refused = ['modifiers' => $this->refusal('option_unavailable', ['option' => 'Boba'])];

        $this->option('Boba')->disabledOutlets()->attach($this->outlet->id);
        $this->checkout()->assertSessionHasErrors($refused);

        $this->option('Boba')->disabledOutlets()->detach();
        $this->option('Boba')->delete();
        $this->checkout()->assertSessionHasErrors($refused);

        $this->option('Boba')->restore();
        $this->setStock($this->boba, 0);
        $this->checkout()->assertSessionHasErrors(['modifiers' => $this->refusal('option_sold_out', ['option' => 'Boba'])]);

        $this->assertSame([0, 1], [Transaction::count(), Cart::count()]);
        $this->assertSame([50, 50], $this->stockOf($this->menu));
    }

    public function test_component_locks_are_taken_in_product_order(): void
    {
        $this->add(['Regular', 'Boba']);
        $this->add(['Large']);
        DB::enableQueryLog();

        $this->checkout()->assertSessionHasNoErrors();

        $locked = $this->stockReadsBeforeTheFirstWrite()->take(3)->map(fn (array $query) => $query['bindings'][0])->values()->all();
        $this->assertSame([$this->menu->id, $this->milk->id, $this->boba->id], $locked);
    }

    // SQLite shows the order only; that a second transaction waits on these rows is MySQL behaviour.
    public function test_expiry_locks_every_stock_row_in_product_order_before_restocking(): void
    {
        // A composite menu of Boba, sold with Large (milk): the restock itself meets boba before milk.
        $this->menu->update(['is_composite' => true]);
        $this->menu->components()->attach($this->boba->id, ['qty' => 1]);
        $this->add(['Large']);
        $this->checkout()->assertSessionHasNoErrors();
        Transaction::sole()->update(['payment_method' => 'bank_transfer', 'payment_status' => 'pending']);
        $this->travel(25)->hours();
        DB::enableQueryLog();

        $this->artisan('transactions:expire')->assertSuccessful();

        // One locking read, ordered by product_id, covers the menu, its recipe and the option's before any write.
        $lock = $this->stockReadsBeforeTheFirstWrite()->sole();
        $this->assertStringContainsString('order by "product_id" asc', $lock['query']);
        $this->assertSame([$this->menu->id, $this->milk->id, $this->boba->id], array_slice($lock['bindings'], 1));
        $this->assertSame([[10, 10], [10, 10]], [$this->stockOf($this->boba), $this->stockOf($this->milk)]);
    }

    /** @return Collection<int, array<string, mixed>> whole warehouse stock rows read (the locks) before any write */
    private function stockReadsBeforeTheFirstWrite(): Collection
    {
        return collect(DB::getQueryLog())
            // MySQL quotes names with backticks, SQLite with double quotes.
            ->map(fn (array $query) => ['query' => strtr($query['query'], '`', '"')] + $query)
            ->takeUntil(fn (array $query) => str_starts_with($query['query'], 'update "product'))
            ->filter(fn (array $query) => str_starts_with($query['query'], 'select * from "product_warehouse"'));
    }

    public function test_menu_used_by_its_own_option_cannot_go_below_zero(): void
    {
        // Without a warehouse row the menu's check falls back to products.stock, which the option has just taken from.
        CashierShift::query()->update(['warehouse_id' => null]);
        $this->option('Jelly')->components()->sync([$this->menu->id => ['qty' => 1]]);
        $this->add(['Regular', 'Jelly'])->assertSessionHasNoErrors();
        $this->menu->update(['stock' => 1]);

        $this->checkout()->assertSessionHasErrors('stock');
        $this->assertSame(1, (int) $this->menu->fresh()->stock);

        $this->menu->update(['stock' => 2]);
        $this->checkout()->assertSessionHasNoErrors();
        $this->assertSame([[2, 1], [1, 0]], StockMutation::orderBy('id')->get()
            ->map(fn (StockMutation $row) => [(int) $row->stock_before, (int) $row->stock_after])->all());
    }

    public function test_expired_payment_gives_back_the_recipe_as_sold(): void
    {
        $this->add(['Large', 'Boba'], 2);
        $this->checkout();
        Transaction::sole()->update(['payment_method' => 'bank_transfer', 'payment_status' => 'pending']);
        // Edited, then deleted, since the sale; Boba's ingredient is gone from the catalogue altogether.
        $this->option('Large')->components()->sync([$this->milk->id => ['qty' => 5]]);
        $this->option('Large')->delete();
        $this->option('Boba')->delete();
        $this->boba->delete();
        $this->travel(25)->hours();

        $this->artisan('transactions:expire')->assertSuccessful();

        $this->assertSame([[50, 50], [10, 10]], [$this->stockOf($this->menu), $this->stockOf($this->milk)]);
        $this->assertSame(4, (int) StockMutation::where('product_id', $this->milk->id)->where('mutation_type', 'in')->sole()->qty);
    }

    // Each option alone is covered; together they need 11 of 10.
    public function test_options_sharing_an_ingredient_cannot_oversell_without_a_warehouse(): void
    {
        CashierShift::query()->update(['warehouse_id' => null]);
        $this->option('Boba')->components()->sync([$this->milk->id => ['qty' => 9]]);
        $this->add(['Large', 'Boba'])->assertSessionHasNoErrors();

        $this->checkout()->assertSessionHasErrors('modifiers');

        $this->assertSame(10, (int) $this->milk->fresh()->stock);
    }

    public function test_receipts_keep_the_sold_options_after_the_group_is_deleted(): void
    {
        $this->add(['Large', 'Jelly'], 1, 'less ice');
        $this->checkout();
        $groupId = $this->option('Large')->modifier_group_id;
        $this->option('Large')->update(['name' => 'Jumbo', 'price_delta' => 9000]);
        app(ModifierGroupService::class)->delete(ModifierGroup::findOrFail($groupId));
        $sale = Transaction::sole();
        $expected = ['+ Large 5.000', '+ Jelly 2.000', __('messages.modifiers.note_line', ['note' => 'less ice'])];

        $this->assertStringContainsString('  '.implode("\n  ", $expected), app(ThermalPrintService::class)->generateReceiptText($sale, '58mm'));
        $this->get(route('transactions.print', $sale->invoice))->assertInertia(fn (Assert $page) => $page
            ->where('transaction.details.0.note', 'less ice')
            ->where('transaction.details.0.modifiers.0.option_name', 'Large'));
        $this->get(route('portal.transaction', [$sale->invoice, 'token' => $sale->access_token]))->assertInertia(fn (Assert $page) => $page
            ->where('transaction.details.0.note', 'less ice')
            ->where('transaction.details.0.modifiers.0.option_name', 'Large'));

        $html = '';
        Pdf::shouldReceive('loadView')->andReturnUsing(function (string $view, array $data) use (&$html) {
            $html .= view($view, $data)->render();

            return Mockery::mock(PdfDocument::class)->shouldReceive('setPaper')->andReturnSelf()
                ->shouldReceive('stream')->andReturn(response(''))->getMock();
        });

        foreach ([route('pdf.transactions.invoice', $sale->invoice), route('pdf.transactions.receipt', [$sale->invoice, '58']), route('pdf.transactions.receipt', [$sale->invoice, '80'])] as $url) {
            $this->get($url)->assertOk();
        }
        $this->assertSame(3, substr_count($html, '+ Large 5.000'));
    }
}
