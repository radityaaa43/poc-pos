<?php

namespace Tests\Feature\Kitchen;

use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\KitchenStation;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// REQ-006 S2 (#344): the display payload carries each item's station and the active stations; "Semua siap" can be limited to stations.
class KitchenStationDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $cook;

    private Warehouse $warehouse;

    private Category $category;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite(); // refusals render the 422/403 error page
        foreach (['dine-orders-prepare', 'dine-orders-handover'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        $outlet = Outlet::create(['code' => 'OUT-A', 'name' => 'Outlet A', 'is_active' => true, 'is_sales_enabled' => true]);
        $this->warehouse = Warehouse::create([
            'outlet_id' => $outlet->id, 'code' => 'WH-A', 'name' => 'Gudang A', 'type' => 'branch', 'is_active' => true,
        ]);
        $this->cook = User::factory()->create();
        $this->cook->givePermissionTo('dine-orders-prepare');
        $this->cook->outlets()->attach($outlet->id, ['is_default' => true]);
    }

    public function test_payload_carries_each_items_station_and_the_active_stations_default_first(): void
    {
        $dapur = KitchenStation::where('is_default', true)->firstOrFail();
        $bar = KitchenStation::create(['name' => 'Bar']);
        $gone = KitchenStation::create(['name' => 'Panggang']);
        $sale = $this->sale();
        $this->item($sale, $bar);
        $this->item($sale, $dapur);
        $gone->delete();

        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.0.items.0.station_id', $bar->id)
                ->where('orders.0.items.0.station', 'Bar')
                ->where('orders.0.items.1.station_id', $dapur->id)
                ->where('orders.0.items.1.station', 'Dapur')
                ->has('stations', 2)
                ->where('stations.0.id', $dapur->id)
                ->where('stations.0.is_default', true)
                ->where('stations.1.name', 'Bar')
                ->where('stations.1.is_default', false)
            );
    }

    public function test_an_item_of_a_deleted_station_keeps_its_name_while_stations_omits_the_station(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $sale = $this->sale();
        $this->item($sale, $bar);
        $bar->delete();

        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.items.0.station_id', $bar->id)
                ->where('orders.0.items.0.station', 'Bar')
                ->has('stations', 1)
                ->where('stations.0.name', 'Dapur')
            );
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_items(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $this->item($this->sale(), $bar);
        $this->queriesForDisplay(); // warm-up: permission and route caches fill on the first request
        $one = $this->queriesForDisplay();

        foreach (range(1, 9) as $ignored) {
            $this->item($this->sale(), $bar);
        }

        $this->assertSame($one, $this->queriesForDisplay());
    }

    public function test_all_ready_with_station_ids_moves_only_those_stations_items(): void
    {
        $dapur = KitchenStation::where('is_default', true)->firstOrFail();
        $bar = KitchenStation::create(['name' => 'Bar']);
        $sale = $this->sale();
        $drink = $this->item($sale, $bar);
        $food = $this->item($sale, $dapur);

        $this->actingAs($this->cook)
            ->post(route('kitchen.orders.all-ready', $sale), ['station_ids' => [$bar->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(KitchenItem::STATUS_READY, $drink->fresh()->status);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $food->fresh()->status);
    }

    public function test_all_ready_on_a_dapur_display_also_covers_the_items_of_a_deleted_station(): void
    {
        $dapur = KitchenStation::where('is_default', true)->firstOrFail();
        $bar = KitchenStation::create(['name' => 'Bar']);
        $gone = KitchenStation::create(['name' => 'Sementara']);
        $sale = $this->sale();
        $drink = $this->item($sale, $bar);
        $orphan = $this->item($sale, $gone);
        $gone->delete();

        $this->actingAs($this->cook)
            ->post(route('kitchen.orders.all-ready', $sale), ['station_ids' => [$dapur->id]])
            ->assertSessionHasNoErrors();

        $this->assertSame(KitchenItem::STATUS_READY, $orphan->fresh()->status);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $drink->fresh()->status);
    }

    public function test_all_ready_scopes_by_station_and_never_leaks_into_another_sale(): void
    {
        $dapur = KitchenStation::where('is_default', true)->firstOrFail();
        $bar = KitchenStation::create(['name' => 'Bar']);
        $gone = KitchenStation::create(['name' => 'Sementara']);

        $build = function () use ($dapur, $bar, $gone) {
            $sale = $this->sale();
            $items = [
                'dapur' => $this->item($sale, $dapur),
                'bar' => $this->item($sale, $bar),
                'null' => tap($this->item($sale, $dapur))->update(['kitchen_station_id' => null]),
                'gone' => $this->item($sale, $gone),
            ];

            return [$sale, $items];
        };
        [$sale, $items] = $build();
        [$other, $otherItems] = $build();
        $gone->delete();

        $statuses = fn (array $set) => array_map(fn (KitchenItem $item) => $item->fresh()->status, $set);
        $open = KitchenItem::STATUS_ACCEPTED;
        $ready = KitchenItem::STATUS_READY;

        $this->actingAs($this->cook)->post(route('kitchen.orders.all-ready', $sale), ['station_ids' => [$dapur->id]])->assertSessionHasNoErrors();
        $this->assertSame(['dapur' => $ready, 'bar' => $open, 'null' => $ready, 'gone' => $ready], $statuses($items));
        $this->assertSame(['dapur' => $open, 'bar' => $open, 'null' => $open, 'gone' => $open], $statuses($otherItems));

        // A Bar display on the other sale marks only Bar, and the first sale is not touched again.
        $this->actingAs($this->cook)->post(route('kitchen.orders.all-ready', $other), ['station_ids' => [$bar->id]])->assertSessionHasNoErrors();
        $this->assertSame(['dapur' => $open, 'bar' => $ready, 'null' => $open, 'gone' => $open], $statuses($otherItems));
        $this->assertSame(['dapur' => $ready, 'bar' => $open, 'null' => $ready, 'gone' => $ready], $statuses($items));
    }

    // #238: Serahkan semua follows the same station rule as Semua siap, through the shared KitchenItem::scopeInStations.
    public function test_hand_over_all_scopes_by_station_and_never_leaks_into_another_sale(): void
    {
        $this->cook->givePermissionTo('dine-orders-handover');
        $dapur = KitchenStation::where('is_default', true)->firstOrFail();
        $bar = KitchenStation::create(['name' => 'Bar']);
        $gone = KitchenStation::create(['name' => 'Sementara']);

        $build = function () use ($dapur, $bar, $gone) {
            $sale = $this->sale();
            $items = [
                'dapur' => $this->item($sale, $dapur),
                'bar' => $this->item($sale, $bar),
                'null' => tap($this->item($sale, $dapur))->update(['kitchen_station_id' => null]),
                'gone' => $this->item($sale, $gone),
            ];
            KitchenItem::where('transaction_id', $sale->id)->update(['status' => KitchenItem::STATUS_READY]);

            return [$sale, $items];
        };
        [$sale, $items] = $build();
        [$other, $otherItems] = $build();
        $gone->delete();

        $statuses = fn (array $set) => array_map(fn (KitchenItem $item) => $item->fresh()->status, $set);
        $ready = KitchenItem::STATUS_READY;
        $served = KitchenItem::STATUS_SERVED;

        // The Dapur display hands over Dapur's items, those with no station and those of a removed one; Bar's stays.
        $this->actingAs($this->cook)->post(route('kitchen.orders.hand-over-all', $sale), ['station_ids' => [$dapur->id]])->assertSessionHasNoErrors();
        $this->assertSame(['dapur' => $served, 'bar' => $ready, 'null' => $served, 'gone' => $served], $statuses($items));
        $this->assertSame(['dapur' => $ready, 'bar' => $ready, 'null' => $ready, 'gone' => $ready], $statuses($otherItems));

        // A Bar display on the other sale hands over only Bar, and the first sale is not touched again.
        $this->actingAs($this->cook)->post(route('kitchen.orders.hand-over-all', $other), ['station_ids' => [$bar->id]])->assertSessionHasNoErrors();
        $this->assertSame(['dapur' => $ready, 'bar' => $served, 'null' => $ready, 'gone' => $ready], $statuses($otherItems));
        $this->assertSame(['dapur' => $served, 'bar' => $ready, 'null' => $served, 'gone' => $served], $statuses($items));

        // Without station ids (the display shows every station) everything ready goes.
        $this->actingAs($this->cook)->post(route('kitchen.orders.hand-over-all', $other))->assertSessionHasNoErrors();
        $this->assertSame(['dapur' => $served, 'bar' => $served, 'null' => $served, 'gone' => $served], $statuses($otherItems));
    }

    public function test_hand_over_all_with_an_unknown_station_id_changes_nothing(): void
    {
        $this->cook->givePermissionTo('dine-orders-handover');
        $sale = $this->sale();
        $item = $this->item($sale, KitchenStation::create(['name' => 'Bar']));
        $item->update(['status' => KitchenItem::STATUS_READY]);

        $this->actingAs($this->cook)
            ->post(route('kitchen.orders.hand-over-all', $sale), ['station_ids' => [999999]])
            ->assertSessionHasNoErrors();

        $this->assertSame(KitchenItem::STATUS_READY, $item->fresh()->status);
    }

    public function test_all_ready_with_an_unknown_station_id_changes_nothing(): void
    {
        $sale = $this->sale();
        $item = $this->item($sale, KitchenStation::create(['name' => 'Bar']));

        $this->actingAs($this->cook)
            ->post(route('kitchen.orders.all-ready', $sale), ['station_ids' => [999999]])
            ->assertSessionHasNoErrors();

        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->fresh()->status);
    }

    public function test_all_ready_without_station_ids_moves_every_open_item(): void
    {
        $sale = $this->sale();
        $drink = $this->item($sale, KitchenStation::create(['name' => 'Bar']));
        $food = $this->item($sale, KitchenStation::where('is_default', true)->firstOrFail());

        $this->actingAs($this->cook)->post(route('kitchen.orders.all-ready', $sale))->assertSessionHasNoErrors();

        $this->assertSame(KitchenItem::STATUS_READY, $drink->fresh()->status);
        $this->assertSame(KitchenItem::STATUS_READY, $food->fresh()->status);
    }

    public function test_all_ready_refuses_station_ids_that_are_not_an_array_of_integers(): void
    {
        $sale = $this->sale();
        $item = $this->item($sale, KitchenStation::create(['name' => 'Bar']));

        foreach (['bar', ['bar'], [[1]]] as $bad) {
            $this->actingAs($this->cook)
                ->post(route('kitchen.orders.all-ready', $sale), ['station_ids' => $bad])
                ->assertSessionHasErrors();
        }

        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->fresh()->status);
    }

    public function test_a_dine_order_is_ready_only_when_every_station_is_ready(): void
    {
        $dapur = KitchenStation::where('is_default', true)->firstOrFail();
        $bar = KitchenStation::create(['name' => 'Bar']);
        $sale = $this->sale(table: 'Meja 5');
        $this->item($sale, $bar);
        $this->item($sale, $dapur);

        $this->actingAs($this->cook)->post(route('kitchen.orders.all-ready', $sale), ['station_ids' => [$bar->id]]);
        $this->assertSame(DineOrder::STATUS_PREPARING, $sale->dineOrder->fresh()->status, 'the kitchen items are not ready yet');

        $this->actingAs($this->cook)->post(route('kitchen.orders.all-ready', $sale), ['station_ids' => [$dapur->id]]);
        $this->assertSame(DineOrder::STATUS_READY, $sale->dineOrder->fresh()->status);
    }

    /** Queries a display poll runs. */
    private function queriesForDisplay(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->cook)->get(route('kitchen.index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function sale(?string $table = null): Transaction
    {
        $transaction = Transaction::create([
            'cashier_id' => $this->cook->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => 'INV-'.++$this->seq,
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => 10000,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);

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

        return $transaction;
    }

    /** One line with its own product and the kitchen item intake would have made for it, in the given station. */
    private function item(Transaction $sale, KitchenStation $station): KitchenItem
    {
        $product = Product::create([
            'title' => 'Menu '.++$this->seq, 'sku' => 'SKU-'.$this->seq, 'barcode' => 'BC-'.$this->seq, 'buy_price' => 5000,
            'sell_price' => 10000, 'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $this->category->id, 'needs_preparation' => true,
        ]);
        $detail = TransactionDetail::create([
            'transaction_id' => $sale->id, 'product_id' => $product->id, 'qty' => 1, 'conversion_factor' => 1,
            'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000, 'discount_total' => 0, 'cost_total' => 0,
        ]);

        return KitchenItem::create([
            'transaction_id' => $sale->id, 'transaction_detail_id' => $detail->id, 'product_id' => $product->id,
            'warehouse_id' => $this->warehouse->id, 'qty' => 1, 'status' => KitchenItem::STATUS_ACCEPTED,
            'accepted_at' => now(), 'kitchen_station_id' => $station->id,
        ]);
    }
}
