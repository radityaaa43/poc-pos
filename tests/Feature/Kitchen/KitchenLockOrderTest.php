<?php

namespace Tests\Feature\Kitchen;

use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\KitchenPrintDevice;
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
use Tests\TestCase;

// #251: every kitchen write takes a shift close's lock order, and settling an order reads current rows.
class KitchenLockOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $staff;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Product $nasi;

    protected function setUp(): void
    {
        parent::setUp();

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

    // SQLite ignores FOR UPDATE: only CI's MySQL job sees the lock clause.
    public function test_every_kitchen_tap_locks_the_items_then_the_dine_orders_before_it_reads_them(): void
    {
        $sale = $this->sale('INV-ROOT', table: 'Meja 4');
        $item = $this->item($sale);
        $this->item($sale);
        $return = SalesReturn::create([
            'code' => 'RET-1', 'transaction_id' => $sale->id, 'cashier_id' => $this->staff->id,
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
            'start preparing' => fn () => app(DineOrderService::class)->startPreparing($sale->dineOrder),
            'one item' => fn () => $kitchen->ready($item->fresh(), $this->staff),
            'all ready' => fn () => $kitchen->allReady($sale, $this->staff),
            'hand over all' => fn () => $kitchen->handOverAll($sale, $this->staff),
            'return' => fn () => DB::transaction(fn () => $kitchen->cancelForReturn($return)),
        ] as $tap => $run) {
            $log = [];
            $run();
            foreach (['kitchen_items', 'dine_orders'] as $step => $table) {
                $this->assertStringContainsString($table, $log[$step]->sql, $tap);
                $this->assertSame([$sale->id], $log[$step]->bindings, $tap);
            }
            if (DB::getDriverName() === 'mysql') {
                // Every read of the items and dine orders, and the bill's payment status, sees the latest committed rows.
                foreach (preg_grep('/from .(kitchen_items|dine_orders).|^select .payment_status./', array_column($log, 'sql')) as $sql) {
                    $this->assertStringContainsString('for update', $sql, $tap);
                }
            }
        }
        $this->assertSame(DineOrder::STATUS_COMPLETED, $sale->dineOrder->fresh()->status);
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
        // The add-on's own invoice is INV-ADD, but its ticket names the original the kitchen knows (#314 BR-1).
        $this->assertSame(['INV-ROOT', 'INV-ROOT'], $response->json('tickets.*.number'));
    }

    private function sale(string $invoice, string $status = 'paid', ?string $table = null, string $dineStatus = DineOrder::STATUS_ACCEPTED, ?Transaction $parent = null): Transaction
    {
        $sale = Transaction::create([
            'cashier_id' => $this->staff->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => $invoice,
            'parent_transaction_id' => $parent?->id,
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0,
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

    private function item(Transaction $sale, int $minutesAgo = 0): KitchenItem
    {
        $detail = $sale->details()->create([
            'transaction_id' => $sale->id, 'product_id' => $this->nasi->id, 'qty' => 1, 'conversion_factor' => 1,
            'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000, 'discount_total' => 0, 'cost_total' => 0,
        ]);

        return KitchenItem::create([
            'transaction_id' => $sale->id, 'transaction_detail_id' => $detail->id, 'product_id' => $this->nasi->id,
            'warehouse_id' => $this->warehouse->id, 'qty' => 1, 'status' => KitchenItem::STATUS_ACCEPTED,
            'accepted_at' => now()->subMinutes($minutesAgo)->startOfSecond(),
        ]);
    }
}
