<?php

namespace Tests\Feature\Kitchen;

use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// #237: Mulai buat / Siap / Semua siap on the kitchen display. One item service owns the steps; the dine order follows its items.
class KitchenStepsTest extends TestCase
{
    use RefreshDatabase;

    private User $cook;

    private User $otherCook;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Category $category;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite(); // refusals render the 403 error page
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        [$this->outlet, $this->warehouse] = $this->outletWithWarehouse('A');
        $this->cook = $this->userWith('dine-orders-prepare', $this->outlet, 'Budi');
        $this->otherCook = $this->userWith('dine-orders-prepare', $this->outlet, 'Sari');
    }

    public function test_start_then_ready_records_time_and_actor_per_item(): void
    {
        $item = $this->kitchenItem($this->sale('INV-1'));

        $this->actingAs($this->cook)->post(route('kitchen.items.start', $item))->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame(KitchenItem::STATUS_PREPARING, $item->status);
        $this->assertNotNull($item->preparing_at);
        $this->assertSame($this->cook->id, $item->preparing_by);
        $this->assertNull($item->ready_at);

        $this->actingAs($this->otherCook)->post(route('kitchen.items.ready', $item))->assertSessionHasNoErrors();
        $item->refresh();
        $this->assertSame(KitchenItem::STATUS_READY, $item->status);
        $this->assertNotNull($item->ready_at);
        $this->assertSame($this->otherCook->id, $item->ready_by);
        $this->assertSame($this->cook->id, $item->preparing_by);
    }

    public function test_all_ready_records_preparing_at_the_same_moment_for_unstarted_items(): void
    {
        $sale = $this->sale('INV-2');
        $started = $this->kitchenItem($sale);
        $untouched = $this->kitchenItem($sale, $this->detail($sale, $this->product('Es teh')));
        $done = $this->kitchenItem($sale, $this->detail($sale, $this->product('Kopi')), status: KitchenItem::STATUS_READY);
        $done->update(['ready_at' => now()->subMinutes(5), 'ready_by' => $this->otherCook->id]);
        $this->actingAs($this->cook)->post(route('kitchen.items.start', $started));
        $startedAt = $started->fresh()->preparing_at;

        $this->actingAs($this->cook)->post(route('kitchen.orders.all-ready', $sale))->assertSessionHasNoErrors();

        $started->refresh();
        $untouched->refresh();
        $done->refresh();
        $this->assertSame(KitchenItem::STATUS_READY, $started->status);
        $this->assertEquals($startedAt, $started->preparing_at, 'a started item keeps its own preparing time');
        $this->assertSame(KitchenItem::STATUS_READY, $untouched->status);
        $this->assertNotNull($untouched->preparing_at);
        $this->assertEquals($untouched->preparing_at, $untouched->ready_at);
        $this->assertSame($this->cook->id, $untouched->preparing_by);
        $this->assertSame($this->cook->id, $untouched->ready_by);
        $this->assertSame($this->otherCook->id, $done->ready_by, 'an item that was already ready is untouched');
        $this->assertNull($done->preparing_at);
    }

    public function test_steps_only_go_forward(): void
    {
        $item = $this->kitchenItem($this->sale('INV-3'));

        // Skipping a step is refused.
        $this->actingAs($this->cook)->post(route('kitchen.items.ready', $item))->assertSessionHasErrors(['item' => 'Langkah tidak valid untuk item ini']);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->fresh()->status);

        $this->actingAs($this->cook)->post(route('kitchen.items.start', $item));
        $this->actingAs($this->cook)->post(route('kitchen.items.ready', $item));

        // Going back is refused and nothing changes.
        $before = $item->fresh();
        $this->actingAs($this->cook)->post(route('kitchen.items.start', $item))->assertSessionHasErrors('item');
        $after = $item->fresh();
        $this->assertSame(KitchenItem::STATUS_READY, $after->status);
        $this->assertEquals($before->preparing_at, $after->preparing_at);
    }

    public function test_cancelled_item_cannot_be_started(): void
    {
        $item = $this->kitchenItem($this->sale('INV-4'), status: 'cancelled');

        $this->actingAs($this->cook)->post(route('kitchen.items.start', $item))
            ->assertSessionHasErrors(['item' => 'Sudah dibatalkan']); // #241: a returned item says so, not "invalid step"

        $this->assertSame('cancelled', $item->fresh()->status);
        $this->assertNull($item->fresh()->preparing_at);
    }

    public function test_user_without_prepare_permission_is_refused(): void
    {
        $sale = $this->sale('INV-5');
        $item = $this->kitchenItem($sale);
        $viewer = $this->userWith('dine-orders-handover', $this->outlet, 'Tono');

        $this->actingAs($viewer)->post(route('kitchen.items.start', $item))->assertForbidden();
        $this->actingAs($viewer)->post(route('kitchen.items.ready', $item))->assertForbidden();
        $this->actingAs($viewer)->post(route('kitchen.orders.all-ready', $sale))->assertForbidden();

        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->fresh()->status);
    }

    public function test_cashier_and_manager_can_by_default(): void
    {
        foreach (['cashier', 'manager'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);
            $user->outlets()->attach($this->outlet->id, ['is_default' => true]);
            $item = $this->kitchenItem($this->sale('INV-'.$role));

            $this->actingAs($user)->post(route('kitchen.items.start', $item))->assertSessionHasNoErrors();

            $this->assertSame(KitchenItem::STATUS_PREPARING, $item->fresh()->status, $role);
        }
    }

    public function test_second_tap_on_the_same_step_reports_who_did_it(): void
    {
        $item = $this->kitchenItem($this->sale('INV-6'));

        $this->actingAs($this->cook)->post(route('kitchen.items.start', $item))->assertSessionHasNoErrors();
        $this->actingAs($this->otherCook)->post(route('kitchen.items.start', $item))
            ->assertSessionHasErrors(['item' => 'Sudah diproses oleh Budi']);

        $item->refresh();
        $this->assertSame($this->cook->id, $item->preparing_by, 'the first tap wins');

        $this->actingAs($this->cook)->post(route('kitchen.items.ready', $item));
        $this->actingAs($this->cook)->post(route('kitchen.items.ready', $item))
            ->assertSessionHasErrors(['item' => 'Sudah diproses oleh Budi']);
    }

    public function test_item_of_another_outlet_is_refused(): void
    {
        [, $warehouseB] = $this->outletWithWarehouse('B');
        $sale = $this->sale('INV-7', warehouse: $warehouseB);
        $item = $this->kitchenItem($sale, warehouse: $warehouseB);

        $this->actingAs($this->cook)->post(route('kitchen.items.start', $item))->assertForbidden();
        $this->actingAs($this->cook)->post(route('kitchen.orders.all-ready', $sale))->assertForbidden();

        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->fresh()->status);
    }

    public function test_item_of_the_outlets_second_warehouse_can_be_started(): void
    {
        $second = Warehouse::create([
            'outlet_id' => $this->outlet->id, 'code' => 'WH-A2', 'name' => 'Gudang A2', 'type' => 'branch', 'is_active' => true, 'sort_order' => 9,
        ]);
        $sale = $this->sale('INV-A2', warehouse: $second);
        $item = $this->kitchenItem($sale, warehouse: $second);

        $this->actingAs($this->cook)->post(route('kitchen.items.start', $item))->assertSessionHasNoErrors();

        $this->assertSame(KitchenItem::STATUS_PREPARING, $item->fresh()->status);
    }

    public function test_dine_order_status_follows_its_items(): void
    {
        $sale = $this->sale('INV-8', table: 'Meja 8');
        $first = $this->kitchenItem($sale);
        $second = $this->kitchenItem($sale, $this->detail($sale, $this->product('Es teh')));
        $order = $sale->dineOrder;

        $this->actingAs($this->cook)->post(route('kitchen.items.start', $first));
        $order->refresh();
        $this->assertSame(DineOrder::STATUS_PREPARING, $order->status);
        $this->assertNotNull($order->preparing_at);
        $this->assertSame($this->cook->id, $order->preparing_by);

        $this->actingAs($this->otherCook)->post(route('kitchen.items.start', $second));
        $this->actingAs($this->otherCook)->post(route('kitchen.items.ready', $first));
        $this->assertSame(DineOrder::STATUS_PREPARING, $order->fresh()->status, 'one item is still being made');

        $this->actingAs($this->otherCook)->post(route('kitchen.items.ready', $second));
        $order->refresh();
        $this->assertSame(DineOrder::STATUS_READY, $order->status);
        $this->assertNotNull($order->ready_at);
        $this->assertSame($this->otherCook->id, $order->ready_by);
        $this->assertSame($this->cook->id, $order->preparing_by, 'the preparing trail keeps the first actor');
    }

    public function test_all_ready_takes_a_dine_order_from_accepted_to_ready(): void
    {
        $sale = $this->sale('INV-9', table: 'Meja 9');
        $this->kitchenItem($sale);

        $this->actingAs($this->cook)->post(route('kitchen.orders.all-ready', $sale))->assertSessionHasNoErrors();

        $order = $sale->dineOrder->fresh();
        $this->assertSame(DineOrder::STATUS_READY, $order->status);
        $this->assertNotNull($order->preparing_at);
        $this->assertNotNull($order->ready_at);
    }

    public function test_legacy_ready_on_accepted_order_with_items_acts_as_all_ready(): void
    {
        $sale = $this->sale('INV-11', table: 'Meja 11');
        $item = $this->kitchenItem($sale);

        $this->actingAs($this->cook)->post(route('dine-orders.ready', $sale->dineOrder))->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame(KitchenItem::STATUS_READY, $item->status);
        $this->assertNotNull($item->preparing_at, 'preparing is recorded at the same moment for an unstarted item');
        $this->assertSame(DineOrder::STATUS_READY, $sale->dineOrder->fresh()->status);
    }

    public function test_legacy_prepare_route_starts_all_items(): void
    {
        $sale = $this->sale('INV-10', table: 'Meja 10');
        $first = $this->kitchenItem($sale);
        $second = $this->kitchenItem($sale, $this->detail($sale, $this->product('Es teh')));
        $order = $sale->dineOrder;

        $this->actingAs($this->cook)->post(route('dine-orders.prepare', $order))->assertSessionHasNoErrors();

        foreach ([$first, $second] as $item) {
            $item->refresh();
            $this->assertSame(KitchenItem::STATUS_PREPARING, $item->status);
            $this->assertSame($this->cook->id, $item->preparing_by);
        }
        $this->assertSame(DineOrder::STATUS_PREPARING, $order->fresh()->status);

        $this->actingAs($this->otherCook)->post(route('dine-orders.ready', $order))->assertSessionHasNoErrors();

        foreach ([$first, $second] as $item) {
            $this->assertSame(KitchenItem::STATUS_READY, $item->fresh()->status);
            $this->assertSame($this->otherCook->id, $item->fresh()->ready_by);
        }
        $this->assertSame(DineOrder::STATUS_READY, $order->fresh()->status);
    }

    public function test_legacy_prepare_skips_items_a_cook_already_started_and_repeating_it_is_a_no_op(): void
    {
        $sale = $this->sale('INV-12', table: 'Meja 12');
        $taken = $this->kitchenItem($sale);
        $rest = $this->kitchenItem($sale, $this->detail($sale, $this->product('Es jeruk')));
        $order = $sale->dineOrder;

        // A cook takes one item on the display first; the old per-order route then starts only what is left.
        $this->actingAs($this->otherCook)->post(route('kitchen.items.start', $taken))->assertSessionHasNoErrors();
        $this->actingAs($this->cook)->post(route('dine-orders.prepare', $order))->assertSessionHasNoErrors();

        $this->assertSame($this->otherCook->id, $taken->fresh()->preparing_by, 'the first actor keeps the trail');
        $this->assertSame(KitchenItem::STATUS_PREPARING, $rest->fresh()->status);
        $this->assertSame($this->cook->id, $rest->fresh()->preparing_by);

        $this->actingAs($this->cook)->post(route('dine-orders.prepare', $order))->assertSessionHasNoErrors();
        $this->assertSame($this->otherCook->id, $taken->fresh()->preparing_by);
        $this->assertSame(DineOrder::STATUS_PREPARING, $order->fresh()->status);
    }

    /** @return array{Outlet, Warehouse} */
    private function outletWithWarehouse(string $code): array
    {
        $outlet = Outlet::create(['code' => 'OUT-'.$code, 'name' => 'Outlet '.$code, 'is_active' => true, 'is_sales_enabled' => true]);
        $warehouse = Warehouse::create([
            'outlet_id' => $outlet->id, 'code' => 'WH-'.$code, 'name' => 'Gudang '.$code, 'type' => 'branch', 'is_active' => true,
        ]);

        return [$outlet, $warehouse];
    }

    private function userWith(string $permission, Outlet $outlet, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->givePermissionTo($permission);
        $user->outlets()->attach($outlet->id, ['is_default' => true]);

        return $user;
    }

    private function product(string $title): Product
    {
        return Product::create([
            'title' => $title, 'sku' => 'SKU-'.++$this->seq, 'barcode' => 'BC-'.$this->seq, 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $this->category->id, 'needs_preparation' => true,
        ]);
    }

    /** A paid sale with one line, optionally tied to an accepted dine-in order. */
    private function sale(string $invoice, ?string $table = null, ?Warehouse $warehouse = null): Transaction
    {
        $transaction = Transaction::create([
            'cashier_id' => $this->cook->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'invoice' => $invoice,
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0,
            'grand_total' => 10000,
            'payment_method' => 'cash',
            'payment_status' => 'paid',
        ]);

        $this->detail($transaction, $this->product('Menu '.$invoice));

        if ($table) {
            DineOrder::create([
                'dine_table_id' => DiningTable::create(['name' => $table, 'is_active' => true, 'capacity' => 4, 'sort_order' => 0])->id,
                'transaction_id' => $transaction->id,
                'status' => DineOrder::STATUS_ACCEPTED,
                'payment_option' => DineOrder::PAY_AT_COUNTER,
                'subtotal' => 10000,
                'item_count' => 1,
            ]);
        }

        return $transaction->load('details.product', 'dineOrder');
    }

    private function detail(Transaction $transaction, Product $product): TransactionDetail
    {
        return TransactionDetail::create([
            'transaction_id' => $transaction->id, 'product_id' => $product->id, 'qty' => 1, 'conversion_factor' => 1,
            'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000, 'discount_total' => 0, 'cost_total' => 0,
        ]);
    }

    /** One kitchen item for the given line (default: the sale's first), as intake would have created it. */
    private function kitchenItem(
        Transaction $sale,
        ?TransactionDetail $detail = null,
        ?Warehouse $warehouse = null,
        string $status = KitchenItem::STATUS_ACCEPTED,
    ): KitchenItem {
        $detail ??= $sale->details->first();

        return KitchenItem::create([
            'transaction_id' => $sale->id,
            'transaction_detail_id' => $detail->id,
            'product_id' => $detail->product_id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'qty' => 1,
            'status' => $status,
            'accepted_at' => now(),
        ]);
    }
}
