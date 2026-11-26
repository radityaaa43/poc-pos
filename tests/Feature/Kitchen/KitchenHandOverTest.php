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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// #238: Serahkan / Serahkan semua on the kitchen display. Hand-over is one more item step; the dine order follows its items.
class KitchenHandOverTest extends TestCase
{
    use RefreshDatabase;

    private User $waiter;

    private User $otherWaiter;

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
        $this->waiter = $this->userWith('dine-orders-handover', $this->outlet, 'Budi');
        $this->otherWaiter = $this->userWith('dine-orders-handover', $this->outlet, 'Sari');
    }

    public function test_hand_over_serves_a_ready_item_with_time_and_actor(): void
    {
        $item = $this->kitchenItem($this->sale('INV-1'));

        $this->actingAs($this->waiter)->post(route('kitchen.items.hand-over', $item))->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertSame(KitchenItem::STATUS_SERVED, $item->status);
        $this->assertNotNull($item->served_at);
        $this->assertSame($this->waiter->id, $item->served_by);
    }

    public function test_dine_order_completes_when_all_served_and_paid(): void
    {
        $sale = $this->sale('INV-2', table: 'Meja 2');
        $first = $this->kitchenItem($sale);
        $second = $this->kitchenItem($sale, $this->detail($sale, $this->product('Es teh')));
        $order = $sale->dineOrder;

        $this->actingAs($this->waiter)->post(route('kitchen.items.hand-over', $first));
        $this->assertSame(DineOrder::STATUS_READY, $order->fresh()->status, 'one item still waiting');

        $this->actingAs($this->otherWaiter)->post(route('kitchen.items.hand-over', $second));

        $order->refresh();
        $this->assertSame(DineOrder::STATUS_COMPLETED, $order->status);
        $this->assertNotNull($order->served_at);
        $this->assertSame($this->otherWaiter->id, $order->served_by, 'the last item hand-over');
        $this->assertNotNull($order->completed_at);
    }

    public function test_hand_over_all_leaves_unready_items(): void
    {
        $sale = $this->sale('INV-3', table: 'Meja 3');
        $ready = $this->kitchenItem($sale);
        $preparing = $this->kitchenItem($sale, $this->detail($sale, $this->product('Es teh')), status: KitchenItem::STATUS_PREPARING);
        $accepted = $this->kitchenItem($sale, $this->detail($sale, $this->product('Kopi')), status: KitchenItem::STATUS_ACCEPTED);

        $this->actingAs($this->waiter)->post(route('kitchen.orders.hand-over-all', $sale))->assertSessionHasNoErrors();

        $this->assertSame(KitchenItem::STATUS_SERVED, $ready->fresh()->status);
        $this->assertSame($this->waiter->id, $ready->fresh()->served_by);
        $this->assertSame(KitchenItem::STATUS_PREPARING, $preparing->fresh()->status);
        $this->assertNull($preparing->fresh()->served_at);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $accepted->fresh()->status);
        $this->assertSame(DineOrder::STATUS_READY, $sale->dineOrder->fresh()->status, 'the order waits for its other items');
    }

    public function test_cannot_hand_over_an_item_that_is_not_ready(): void
    {
        $sale = $this->sale('INV-4');
        $accepted = $this->kitchenItem($sale, status: KitchenItem::STATUS_ACCEPTED);
        $preparing = $this->kitchenItem($sale, $this->detail($sale, $this->product('Es teh')), status: KitchenItem::STATUS_PREPARING);

        foreach ([$accepted, $preparing] as $item) {
            $this->actingAs($this->waiter)->post(route('kitchen.items.hand-over', $item))
                ->assertSessionHasErrors(['item' => 'Langkah tidak valid untuk item ini']);
            $this->assertNull($item->fresh()->served_at);
        }
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $accepted->fresh()->status);
        $this->assertSame(KitchenItem::STATUS_PREPARING, $preparing->fresh()->status);
    }

    public function test_pay_later_order_completes_when_paid_later(): void
    {
        $sale = $this->sale('INV-5', table: 'Meja 5');
        $sale->update(['payment_status' => 'unpaid', 'payment_method' => 'pay_later']);
        $this->kitchenItem($sale);
        $order = $sale->dineOrder;

        $this->actingAs($this->waiter)->post(route('kitchen.orders.hand-over-all', $sale))->assertSessionHasNoErrors();

        $this->assertSame(DineOrder::STATUS_SERVED, $order->fresh()->status, 'served but not paid: stays served');

        Transaction::find($sale->id)->update(['payment_status' => 'paid']);

        $this->assertSame(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
    }

    public function test_user_without_handover_is_refused(): void
    {
        $sale = $this->sale('INV-6');
        $item = $this->kitchenItem($sale);
        $nobody = $this->userWith(null, $this->outlet, 'Tono');

        $this->actingAs($nobody)->post(route('kitchen.items.hand-over', $item))->assertForbidden();
        $this->actingAs($nobody)->post(route('kitchen.orders.hand-over-all', $sale))->assertForbidden();

        $this->assertSame(KitchenItem::STATUS_READY, $item->fresh()->status);
    }

    public function test_prepare_only_role_sees_no_hand_over(): void
    {
        $sale = $this->sale('INV-7');
        $item = $this->kitchenItem($sale);
        $cook = $this->userWith('dine-orders-prepare', $this->outlet, 'Joko');

        $this->actingAs($cook)->post(route('kitchen.items.hand-over', $item))->assertForbidden();
        $this->actingAs($cook)->post(route('kitchen.orders.hand-over-all', $sale))->assertForbidden();

        $this->assertSame(KitchenItem::STATUS_READY, $item->fresh()->status);
    }

    public function test_second_hand_over_reports_who_did_it(): void
    {
        $item = $this->kitchenItem($this->sale('INV-8'));

        $this->actingAs($this->waiter)->post(route('kitchen.items.hand-over', $item))->assertSessionHasNoErrors();
        $this->actingAs($this->otherWaiter)->post(route('kitchen.items.hand-over', $item))
            ->assertSessionHasErrors(['item' => 'Sudah diproses oleh Budi']);

        $this->assertSame($this->waiter->id, $item->fresh()->served_by, 'the first tap wins');
    }

    public function test_legacy_serve_route_hands_over_all_ready_items(): void
    {
        $sale = $this->sale('INV-9', table: 'Meja 9');
        $first = $this->kitchenItem($sale);
        $second = $this->kitchenItem($sale, $this->detail($sale, $this->product('Es teh')));
        $order = $sale->dineOrder;

        $this->actingAs($this->waiter)->post(route('dine-orders.serve', $order))->assertSessionHasNoErrors();

        foreach ([$first, $second] as $item) {
            $this->assertSame(KitchenItem::STATUS_SERVED, $item->fresh()->status);
            $this->assertSame($this->waiter->id, $item->fresh()->served_by);
        }
        $this->assertSame(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
    }

    // The Orders page keeps Serahkan only for a ready order the kitchen display does not track (no kitchen items), its only exit.
    public function test_the_orders_page_marks_which_orders_have_kitchen_items(): void
    {
        $tracked = $this->sale('INV-11', table: 'Meja 11');
        $this->kitchenItem($tracked);
        $untracked = $this->sale('INV-12', table: 'Meja 12'); // e.g. paid before the display existed, or nothing to make
        $admin = User::factory()->create();
        $admin->assignRole('super-admin'); // sees every outlet; the test tables have no area

        $this->actingAs($admin)->get(route('dine-orders.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/DineIn/Orders/Index')
                ->has('orders', 2)
                // By id, not by position: two orders created in the same second have no guaranteed order on MySQL.
                ->where('orders', fn ($orders) => collect($orders)->firstWhere('id', $tracked->dineOrder->id)['has_kitchen_items'] === true
                    && collect($orders)->firstWhere('id', $untracked->dineOrder->id)['has_kitchen_items'] === false)
            );
    }

    public function test_item_of_another_outlet_is_refused(): void
    {
        [, $warehouseB] = $this->outletWithWarehouse('B');
        $sale = $this->sale('INV-10', warehouse: $warehouseB);
        $item = $this->kitchenItem($sale, warehouse: $warehouseB);

        $this->actingAs($this->waiter)->post(route('kitchen.items.hand-over', $item))->assertForbidden();
        $this->actingAs($this->waiter)->post(route('kitchen.orders.hand-over-all', $sale))->assertForbidden();

        $this->assertSame(KitchenItem::STATUS_READY, $item->fresh()->status);
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

    private function userWith(?string $permission, Outlet $outlet, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        if ($permission) {
            $user->givePermissionTo($permission);
        }
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

    /** A paid sale with one line; with a table, an order whose kitchen has finished (ready). */
    private function sale(string $invoice, ?string $table = null, ?Warehouse $warehouse = null): Transaction
    {
        $transaction = Transaction::create([
            'cashier_id' => $this->waiter->id,
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
                'status' => DineOrder::STATUS_READY,
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

    /** One kitchen item for the given line (default: the sale's first); ready unless said otherwise. */
    private function kitchenItem(
        Transaction $sale,
        ?TransactionDetail $detail = null,
        ?Warehouse $warehouse = null,
        string $status = KitchenItem::STATUS_READY,
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
            'ready_at' => $status === KitchenItem::STATUS_READY ? now() : null,
        ]);
    }
}
