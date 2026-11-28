<?php

namespace Tests\Feature\Kitchen;

use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\KitchenPrintDevice;
use App\Models\KitchenStation;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DineOrderService;
use App\Services\KitchenItemService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

// #251: the display shows an add-on's items inside the card of its original order; tickets stay one per sale.
class KitchenAddOnGroupTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Product $nasi;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        $this->outlet = Outlet::create(['code' => 'OUT-A', 'name' => 'Outlet A', 'is_active' => true, 'is_sales_enabled' => true]);
        $this->warehouse = Warehouse::create(['outlet_id' => $this->outlet->id, 'code' => 'WH-A', 'name' => 'Gudang A', 'type' => 'branch', 'is_active' => true]);
        $this->staff = User::factory()->create();
        $this->staff->givePermissionTo(['dine-orders-prepare', 'dine-orders-handover']);
        $this->staff->outlets()->attach($this->outlet->id, ['is_default' => true]);

        $category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        $this->nasi = Product::create([
            'title' => 'Nasi goreng', 'sku' => 'SKU-1', 'barcode' => 'BC-1', 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $category->id, 'needs_preparation' => true,
        ]);
    }

    // BR-2, TC-6: one card, aged by its oldest item; Belum lunas stays with the sale that owes
    public function test_kitchen_query_groups_add_on_items_under_the_root(): void
    {
        $root = $this->sale('INV-ROOT');
        $first = $this->item($root, minutesAgo: 10);
        $other = $this->sale('INV-OTHER');
        $this->item($other, minutesAgo: 5);
        $addOn = $this->sale('INV-ADD', parent: $root, status: 'unpaid');
        $second = $this->item($addOn, minutesAgo: 1);

        $this->display()->assertInertia(fn (Assert $page) => $page
            ->has('orders', 2)
            ->where('orders.0.id', $root->id)
            ->where('orders.0.unpaid', false)
            ->where('orders.0.since', $first->accepted_at->toJSON())
            ->has('orders.0.items', 2)
            ->where('orders.0.items.0.id', $first->id)
            ->missing('orders.0.items.0.add_on')
            ->where('orders.0.items.1.id', $second->id)
            ->where('orders.0.items.1.add_on.id', $addOn->id)
            ->where('orders.0.items.1.add_on.unpaid', true)
            ->where('orders.1.id', $other->id)
        );
    }

    // BR-8: the card keeps the original order's number (invoice or table), also once the original's own items are gone
    public function test_add_on_shows_under_the_original_invoice(): void
    {
        $root = $this->sale('INV-ROOT');
        $this->item($root, status: KitchenItem::STATUS_SERVED);
        $addOn = $this->sale('INV-ADD', parent: $root);
        $this->item($addOn, minutesAgo: 2);
        $this->item($this->sale('INV-ADD-2', parent: $this->sale('INV-TABLE', table: 'Meja 4')));

        $this->display()->assertInertia(fn (Assert $page) => $page
            ->has('orders', 2)
            ->where('orders.0.id', $root->id)
            ->where('orders.0.number', 'INV-ROOT')
            ->where('orders.0.items.0.add_on.id', $addOn->id)
            ->where('orders.1.number', 'Meja 4')
            ->where('orders.1.type', 'dine_in')
        );
    }

    // TC-6
    public function test_hand_over_all_serves_the_original_and_its_add_ons_only(): void
    {
        $root = $this->sale('INV-ROOT', table: 'Meja 4', dineStatus: DineOrder::STATUS_READY);
        $mine = $this->item($root, status: KitchenItem::STATUS_READY);
        $addOn = $this->sale('INV-ADD', parent: $root, table: 'Meja 4', dineStatus: DineOrder::STATUS_READY);
        $theirs = $this->item($addOn, status: KitchenItem::STATUS_READY);
        $waiting = $this->item($addOn, status: KitchenItem::STATUS_PREPARING);
        $other = $this->item($this->sale('INV-OTHER'), status: KitchenItem::STATUS_READY);

        $this->actingAs($this->staff)->post(route('kitchen.orders.hand-over-all', $root))->assertSessionHasNoErrors();

        $this->assertSame(KitchenItem::STATUS_SERVED, $mine->fresh()->status);
        $this->assertSame(KitchenItem::STATUS_SERVED, $theirs->fresh()->status);
        $this->assertSame(KitchenItem::STATUS_PREPARING, $waiting->fresh()->status);
        $this->assertSame(KitchenItem::STATUS_READY, $other->fresh()->status);
        // Each sale's dine order follows its own items.
        $this->assertSame(DineOrder::STATUS_COMPLETED, $root->dineOrder->fresh()->status);
        $this->assertSame(DineOrder::STATUS_READY, $addOn->dineOrder->fresh()->status);
    }

    public function test_all_ready_covers_the_whole_card_within_the_chosen_stations(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $root = $this->sale('INV-ROOT');
        $drink = $this->item($root, station: $bar->id);
        $food = $this->item($root);
        $addOn = $this->sale('INV-ADD', parent: $root);
        $addedDrink = $this->item($addOn, station: $bar->id);

        $this->actingAs($this->staff)->post(route('kitchen.orders.all-ready', $root), ['station_ids' => [$bar->id]])->assertSessionHasNoErrors();

        $this->assertSame(KitchenItem::STATUS_READY, $drink->fresh()->status);
        $this->assertSame(KitchenItem::STATUS_READY, $addedDrink->fresh()->status);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $food->fresh()->status);
    }

    // Two stations at once: taps on one order queue on its lock, taken newest sale first as a checkout does. SQLite
    // ignores FOR UPDATE, so this pins the statement order; CI's MySQL job also sees the clause.
    public function test_every_kitchen_tap_locks_the_dine_order_before_reading_its_items(): void
    {
        $root = $this->sale('INV-ROOT', table: 'Meja 4');
        $item = $this->item($root);
        $addOn = $this->sale('INV-ADD', parent: $root, table: 'Meja 4');
        $this->item($addOn);
        $return = SalesReturn::create([
            'code' => 'RET-1', 'transaction_id' => $root->id, 'cashier_id' => $this->staff->id,
            'status' => 'draft', 'return_type' => 'refund_cash', 'total_return_amount' => 0,
        ]);
        $this->actingAs($this->staff);
        $kitchen = app(KitchenItemService::class);
        $log = [];
        DB::listen(function (QueryExecuted $query) use (&$log) {
            if (DB::transactionLevel() > 1 && preg_match('/^select .* from .(dine_orders|kitchen_items|transactions)./', $query->sql)) {
                $log[] = $query;
            }
        });

        foreach ([
            'start preparing' => [fn () => app(DineOrderService::class)->startPreparing($root->dineOrder), $root],
            'one item' => [fn () => $kitchen->ready($item->fresh(), $this->staff), $root],
            'all ready' => [fn () => $kitchen->allReadyOrderGroup($root, $this->staff), $addOn],
            'hand over all' => [fn () => $kitchen->handOverOrderGroup($root, $this->staff), $addOn],
            'return' => [fn () => DB::transaction(fn () => $kitchen->cancelForReturn($return)), $root],
        ] as $tap => [$run, $first]) {
            $log = [];
            $run();
            $locks = array_values(array_filter($log, fn ($query) => ! str_contains($query->sql, 'parent_transaction_id')));
            $this->assertStringContainsString('dine_orders', $locks[0]->sql, $tap);
            $this->assertSame([$first->id], array_slice($locks[0]->bindings, -1), $tap);
            if (DB::getDriverName() === 'mysql') {
                // Every read of the items, and the bill's payment status, sees the latest committed rows.
                foreach (preg_grep('/from .kitchen_items.|^select .payment_status./', array_column($log, 'sql')) as $sql) {
                    $this->assertStringContainsString('for update', $sql, $tap);
                }
            }
        }
        $this->assertSame(DineOrder::STATUS_COMPLETED, $root->dineOrder->fresh()->status);
    }

    // A payment and a hand-over can commit side by side: neither side may decide from what it loaded earlier.
    public function test_settling_reads_the_current_order_and_payment(): void
    {
        $sale = $this->sale('INV-ROOT', status: 'unpaid', table: 'Meja 4', dineStatus: DineOrder::STATUS_READY);
        $loadedEarlier = $sale->dineOrder()->with('transaction')->first();
        DB::table('dine_orders')->update(['status' => DineOrder::STATUS_SERVED]);
        DB::table('transactions')->update(['payment_status' => 'paid']);

        $loadedEarlier->completeIfSettled();

        $this->assertSame(DineOrder::STATUS_COMPLETED, $sale->dineOrder->fresh()->status);
    }

    // Tech Lead note on #251: grouping is display only
    public function test_kitchen_tickets_stay_one_per_sale(): void
    {
        $device = new KitchenPrintDevice(['outlet_id' => $this->outlet->id, 'name' => 'Dapur 1']);
        $device->created_at = now()->subHour();
        $device->save();
        $root = $this->sale('INV-ROOT');
        $this->item($root, minutesAgo: 3);
        $addOn = $this->sale('INV-ADD', parent: $root);
        $this->item($addOn);

        $response = $this->actingAs($this->staff)->getJson(route('kitchen.print-devices.tickets', $device))->assertOk();

        $this->assertSame(['t'.$root->id, 't'.$addOn->id], $response->json('tickets.*.key'));
        $this->assertSame(['INV-ROOT', 'INV-ADD'], $response->json('tickets.*.number'));
    }

    private function display()
    {
        return $this->actingAs($this->staff)->get(route('kitchen.index'))->assertOk();
    }

    private function sale(
        string $invoice,
        ?Transaction $parent = null,
        string $status = 'paid',
        ?string $table = null,
        string $dineStatus = DineOrder::STATUS_ACCEPTED,
    ): Transaction {
        $sale = Transaction::create([
            'cashier_id' => $this->staff->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => $invoice,
            'parent_transaction_id' => $parent?->id, 'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0,
            'grand_total' => 10000, 'payment_method' => 'cash', 'payment_status' => $status,
        ]);

        if ($table) {
            DineOrder::create([
                'dine_table_id' => DiningTable::firstOrCreate(['name' => $table], ['is_active' => true, 'capacity' => 4, 'sort_order' => 0])->id,
                'transaction_id' => $sale->id, 'status' => $dineStatus, 'payment_option' => DineOrder::PAY_AT_COUNTER,
                'subtotal' => 10000, 'item_count' => 1,
            ]);
        }

        return $sale;
    }

    private function item(Transaction $sale, string $status = KitchenItem::STATUS_ACCEPTED, int $minutesAgo = 0, ?int $station = null): KitchenItem
    {
        $detail = $sale->details()->create([
            'transaction_id' => $sale->id, 'product_id' => $this->nasi->id, 'qty' => 1, 'conversion_factor' => 1,
            'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000, 'discount_total' => 0, 'cost_total' => 0,
        ]);

        return KitchenItem::create([
            'transaction_id' => $sale->id, 'transaction_detail_id' => $detail->id, 'product_id' => $this->nasi->id,
            'warehouse_id' => $this->warehouse->id, 'kitchen_station_id' => $station, 'qty' => 1, 'status' => $status,
            'accepted_at' => now()->subMinutes($minutesAgo)->startOfSecond(), 'ready_at' => $status === KitchenItem::STATUS_READY ? now() : null,
        ]);
    }
}
