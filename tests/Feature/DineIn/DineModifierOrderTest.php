<?php

namespace Tests\Feature\DineIn;

use App\Models\DineArea;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\KitchenPrintDevice;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Services\InventoryCostService;
use App\Services\KitchenTicketService;
use App\Support\Kitchen\KitchenOrders;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Modifiers\ModifierSaleTestCase;

/** REQ-008 T4: options and note on the table-QR order and in the kitchen (AC-15..AC-18, BR-1..BR-3). */
class DineModifierOrderTest extends ModifierSaleTestCase
{
    private DiningTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $area = DineArea::create(['outlet_id' => $this->outlet->id, 'name' => 'Area', 'is_active' => true]);
        $this->table = DiningTable::create(['dine_area_id' => $area->id, 'name' => 'M1', 'capacity' => 4, 'is_active' => true]);
        Setting::setForOutlet('pay_later_enabled', '1', $this->outlet);
    }

    /** @param  list<array<string, mixed>>  $items */
    private function submit(array $items, string $paymentOption = DineOrder::PAY_AT_COUNTER): TestResponse
    {
        return $this->post(route('dine-order.store', $this->table->token), [
            'items' => $items,
            'payment_option' => $paymentOption,
        ]);
    }

    public function test_guest_submit_stores_options_note_and_price(): void
    {
        $large = $this->option('Large');

        $this->submit([
            ['product_id' => $this->menu->id, 'qty' => 2, 'modifier_option_ids' => [$large->id], 'note' => 'es sedikit'],
        ])->assertSessionHasNoErrors();

        $order = DineOrder::firstOrFail();
        $item = $order->items()->firstOrFail();

        $this->assertSame([$large->id], $item->modifier_option_ids);
        $this->assertSame('es sedikit', $item->note);
        // menu 20.000 + Large 5.000, both server-computed
        $this->assertSame(25000, (int) $item->price);
        $this->assertSame(50000, (int) $order->subtotal);
        $this->assertSame(2, (int) $order->item_count);
    }

    public function test_qr_menu_hides_options_switched_off_at_the_outlet(): void
    {
        // BR-1: an option off at the outlet never reaches the guest's page.
        $sizeGroupId = $this->option('Regular')->modifier_group_id;
        $this->option('Large')->disabledOutlets()->attach($this->outlet->id);

        $this->get(route('dine.menu', $this->table->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has("modifierGroups.{$sizeGroupId}.options", 1)
                ->where("modifierGroups.{$sizeGroupId}.options.0.name", 'Regular'));
    }

    public function test_guest_submit_refuses_an_option_switched_off_at_the_outlet(): void
    {
        $large = $this->option('Large');
        $large->disabledOutlets()->attach($this->outlet->id);

        $this->submit([
            ['product_id' => $this->menu->id, 'qty' => 1, 'modifier_option_ids' => [$large->id]],
        ])->assertSessionHasErrors(['modifiers' => $this->refusal('option_unavailable', ['option' => 'Large'])]);

        $this->assertDatabaseCount('dine_orders', 0);
    }

    public function test_accept_writes_option_snapshot_note_and_price(): void
    {
        $large = $this->option('Large');
        $this->submit([
            ['product_id' => $this->menu->id, 'qty' => 1, 'modifier_option_ids' => [$large->id], 'note' => 'tanpa gula'],
        ])->assertSessionHasNoErrors();

        $order = DineOrder::firstOrFail();
        $this->post(route('dine-orders.accept', $order))->assertSessionHasNoErrors();

        $order = $order->fresh();
        $this->assertSame(DineOrder::STATUS_ACCEPTED, $order->status);

        $transaction = Transaction::findOrFail($order->transaction_id);
        $this->assertSame(25000, (int) $transaction->grand_total);

        $detail = $transaction->details()->firstOrFail();
        $this->assertSame('tanpa gula', $detail->note);
        $this->assertSame(5000, (int) $detail->modifier_total);
        $this->assertSame(25000, (int) $detail->unit_price);
        $this->assertSame(25000, (int) $detail->price);

        $row = $detail->modifiers()->firstOrFail();
        $this->assertSame($large->id, (int) $row->modifier_option_id);
        $this->assertSame('Size', $row->group_name);
        $this->assertSame('Large', $row->option_name);
    }

    public function test_accept_refuses_option_no_longer_available(): void
    {
        $large = $this->option('Large');
        $this->submit([
            ['product_id' => $this->menu->id, 'qty' => 1, 'modifier_option_ids' => [$large->id]],
        ])->assertSessionHasNoErrors();

        $order = DineOrder::firstOrFail();
        // Switched off after the guest sent the order (BR-3): the accept must refuse, naming the menu and option.
        $large->disabledOutlets()->attach($this->outlet->id);

        $this->from(route('dine-orders.index'))
            ->post(route('dine-orders.accept', $order))
            ->assertSessionHasErrors(['modifiers' => $this->refusal('option_unavailable', ['option' => 'Large'])]);

        $order = $order->fresh();
        $this->assertSame(DineOrder::STATUS_SUBMITTED, $order->status);
        $this->assertNull($order->transaction_id);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('transaction_details', 0);
    }

    public function test_accept_takes_option_recipe_stock(): void
    {
        $large = $this->option('Large'); // recipe: 2 x milk per portion
        $this->assertSame([10, 10], $this->stockOf($this->milk));

        $this->submit([
            ['product_id' => $this->menu->id, 'qty' => 2, 'modifier_option_ids' => [$large->id]],
        ])->assertSessionHasNoErrors();

        $order = DineOrder::firstOrFail();
        $this->post(route('dine-orders.accept', $order))->assertSessionHasNoErrors();

        $this->assertSame([6, 6], $this->stockOf($this->milk)); // 2 portions x 2 units
        $this->assertSame([48, 48], $this->stockOf($this->menu)); // the menu itself is taken too
    }

    public function test_accept_keeps_the_submit_time_option_price(): void
    {
        $large = $this->option('Large');
        $this->submit([
            ['product_id' => $this->menu->id, 'qty' => 1, 'modifier_option_ids' => [$large->id]],
        ])->assertSessionHasNoErrors();
        $order = DineOrder::firstOrFail();

        // The owner raises the option price after the guest sent the order: the accepted line keeps the shown price.
        $large->update(['price_delta' => 9000]);

        $this->post(route('dine-orders.accept', $order))->assertSessionHasNoErrors();

        $detail = Transaction::findOrFail($order->fresh()->transaction_id)->details()->firstOrFail();
        $this->assertSame(5000, (int) $detail->modifier_total);
        $this->assertSame(5000, (int) $detail->modifiers()->firstOrFail()->price_delta);
        $this->assertSame(25000, (int) $detail->unit_price);
        $this->assertSame(25000, (int) $detail->price);
    }

    public function test_an_order_already_in_the_kitchen_keeps_its_note(): void
    {
        $this->menu->update(['needs_preparation' => true]);
        $device = new KitchenPrintDevice(['outlet_id' => $this->outlet->id, 'name' => 'Dapur']);
        $device->created_at = now()->subMinute();
        $device->save();

        $this->submit([
            ['product_id' => $this->menu->id, 'qty' => 1, 'note' => 'alergi kacang'],
        ], DineOrder::PAY_LATER)->assertSessionHasNoErrors();
        $order = DineOrder::firstOrFail();
        $this->post(route('dine-orders.accept', $order))->assertSessionHasNoErrors();

        // In flight: the item is in the kitchen; its note survives on the read model and on the ticket payload.
        $items = KitchenItem::with(KitchenOrders::RELATIONS)->get();
        $this->assertSame('alergi kacang', KitchenOrders::fromItems($items)->first()['items']->first()['note']);
        $this->assertSame('alergi kacang', app(KitchenTicketService::class)->pending($device)->first()['items'][0]['note']);
    }

    public function test_an_add_on_order_carries_options_and_note_to_the_kitchen(): void
    {
        $this->menu->update(['needs_preparation' => true]);
        $large = $this->option('Large');

        // A first order on the table, accepted and already in the kitchen.
        $this->submit([['product_id' => $this->menu->id, 'qty' => 1]], DineOrder::PAY_LATER)->assertSessionHasNoErrors();
        $first = DineOrder::firstOrFail();
        $this->post(route('dine-orders.accept', $first))->assertSessionHasNoErrors();

        // The guest adds another order on the same table with an option and a note (the add-on path uses the same submit).
        $this->submit([
            ['product_id' => $this->menu->id, 'qty' => 1, 'modifier_option_ids' => [$large->id], 'note' => 'tambah pedas'],
        ], DineOrder::PAY_LATER)->assertSessionHasNoErrors();
        $addOn = DineOrder::orderByDesc('id')->firstOrFail();
        $this->assertNotSame($first->id, $addOn->id);
        $this->post(route('dine-orders.accept', $addOn))->assertSessionHasNoErrors();

        $transaction = Transaction::findOrFail($addOn->fresh()->transaction_id);
        $item = KitchenItem::where('transaction_id', $transaction->id)->sole();
        $this->assertSame('tambah pedas', $item->note);
        $presented = KitchenOrders::fromItems(KitchenItem::with(KitchenOrders::RELATIONS)->where('transaction_id', $transaction->id)->get());
        $this->assertSame(['Large'], $presented->first()['items']->first()['modifiers']);
    }

    public function test_option_recipe_cost_matches_the_pos_frozen_cost(): void
    {
        $large = $this->option('Large');

        // POS checkout of 2 portions with Large: the path that already snapshots the option cost.
        $this->add(['Large'], 2);
        $this->checkout()->assertSessionHasNoErrors();
        $pos = Transaction::latest('id')->firstOrFail();
        $posDetail = $pos->details()->firstOrFail();

        // The same menu, quantity and option through the table-QR submit and the cashier's accept.
        $this->submit([
            ['product_id' => $this->menu->id, 'qty' => 2, 'modifier_option_ids' => [$large->id]],
        ])->assertSessionHasNoErrors();
        $order = DineOrder::firstOrFail();
        $this->post(route('dine-orders.accept', $order))->assertSessionHasNoErrors();
        $dine = Transaction::latest('id')->firstOrFail();
        $dineDetail = $dine->details()->firstOrFail();

        // Same frozen cost basis and profit, as a POS sale of the same menu, quantity and options.
        $this->assertSame((int) $posDetail->cost_total, (int) $dineDetail->cost_total);
        $this->assertSame((int) $pos->profits()->sum('total'), (int) $dine->profits()->sum('total'));
        // The option's ingredient is part of the dine line's frozen cost, not only the menu's.
        $menuOnly = app(InventoryCostService::class)->lineCost($this->menu, $this->warehouse->id, 2, 1.0);
        $this->assertGreaterThan($menuOnly, (int) $dineDetail->cost_total);
    }

    public function test_kitchen_reads_each_lines_own_note_and_options(): void
    {
        $this->menu->update(['needs_preparation' => true]);
        $large = $this->option('Large');
        $regular = $this->option('Regular');

        $this->submit([
            ['product_id' => $this->menu->id, 'qty' => 1, 'modifier_option_ids' => [$large->id], 'note' => 'es sedikit'],
            ['product_id' => $this->menu->id, 'qty' => 1, 'modifier_option_ids' => [$regular->id], 'note' => 'panas'],
        ], DineOrder::PAY_LATER)->assertSessionHasNoErrors();

        $order = DineOrder::firstOrFail();
        $this->post(route('dine-orders.accept', $order))->assertSessionHasNoErrors();

        $items = KitchenItem::with(KitchenOrders::RELATIONS)->orderBy('id')->get();
        $this->assertCount(2, $items);

        foreach ($items as $item) {
            $detail = TransactionDetail::findOrFail($item->transaction_detail_id);
            $this->assertSame($detail->note, $item->note); // the detail is the one source; no product+unit guess
        }
        $this->assertEqualsCanonicalizing(['es sedikit', 'panas'], $items->pluck('note')->all());

        // The read model the display and tickets share: free and paid options, in snapshot order.
        $presented = KitchenOrders::fromItems($items);
        $this->assertEqualsCanonicalizing(['Large', 'Regular'], $presented->flatMap(fn (array $order) => $order['items'])->pluck('modifiers')->flatten()->all());
    }
}
