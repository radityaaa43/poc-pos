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
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    // BR-2, TC-6
    public function test_kitchen_query_groups_add_on_items_under_the_root(): void
    {
        $root = $this->sale('INV-ROOT');
        $first = $this->item($root, minutesAgo: 10);
        $other = $this->sale('INV-OTHER');
        $this->item($other, minutesAgo: 5);
        $addOn = $this->sale('INV-ADD', parent: $root);
        $second = $this->item($addOn, minutesAgo: 1);

        $this->display()->assertInertia(fn (Assert $page) => $page
            ->has('orders', 2)
            ->where('orders.0.id', $root->id)
            ->has('orders.0.items', 2)
            ->where('orders.0.items.0.id', $first->id)
            ->missing('orders.0.items.0.add_on')
            ->where('orders.0.items.1.id', $second->id)
            ->where('orders.0.items.1.add_on.id', $addOn->id)
            ->where('orders.1.id', $other->id)
        );
    }

    // BR-8: the card keeps the original order's number, also once the original's own items are gone
    public function test_add_on_shows_under_the_original_invoice(): void
    {
        $root = $this->sale('INV-ROOT');
        $this->item($root, status: KitchenItem::STATUS_SERVED);
        $addOn = $this->sale('INV-ADD', parent: $root);
        $this->item($addOn);

        $this->display()->assertInertia(fn (Assert $page) => $page
            ->has('orders', 1)
            ->where('orders.0.id', $root->id)
            ->where('orders.0.number', 'INV-ROOT')
            ->where('orders.0.items.0.add_on.id', $addOn->id)
        );
    }

    public function test_a_dine_in_original_names_the_card_by_its_table(): void
    {
        $root = $this->sale('INV-ROOT', table: 'Meja 4');
        $addOn = $this->sale('INV-ADD', parent: $root);
        $this->item($addOn);

        $this->display()->assertInertia(fn (Assert $page) => $page
            ->where('orders.0.number', 'Meja 4')
            ->where('orders.0.type', 'dine_in')
        );
    }

    public function test_the_card_age_is_its_oldest_item_and_the_unpaid_mark_stays_with_its_sale(): void
    {
        $root = $this->sale('INV-ROOT');
        $oldest = $this->item($root, minutesAgo: 10);
        $this->item($this->sale('INV-ADD', parent: $root, status: 'unpaid'), minutesAgo: 2);

        $this->display()->assertInertia(fn (Assert $page) => $page
            ->where('orders.0.unpaid', false)
            ->where('orders.0.items.1.add_on.unpaid', true)
            ->where('orders.0.since', $oldest->accepted_at->toJSON())
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
