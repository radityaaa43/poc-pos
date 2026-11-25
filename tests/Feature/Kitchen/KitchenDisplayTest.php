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
use App\Services\KitchenIntakeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// #258: the kitchen display lists the open kitchen items of the user's current outlet, per sale, oldest first.
class KitchenDisplayTest extends TestCase
{
    use RefreshDatabase;

    private User $cook;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Category $category;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['dine-orders-prepare', 'dine-orders-handover', 'dine-orders-access'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        [$this->outlet, $this->warehouse] = $this->outletWithWarehouse('A');
        $this->cook = $this->userWith('dine-orders-prepare', $this->outlet);
    }

    public function test_queue_merges_dine_in_and_pos_oldest_first(): void
    {
        $pos = $this->sale('INV-POS');
        $dine = $this->sale('INV-DINE', table: 'Meja 5');
        $release = $this->sale('INV-RELEASE');

        $this->kitchenItem($pos, acceptedAt: now()->subMinutes(5));
        $this->kitchenItem($dine, acceptedAt: now()->subMinutes(10));
        // Rows from the #236 backfill have no accepted_at: their created_at places them in the queue.
        $this->kitchenItem($release, createdAt: now()->subMinutes(30), backfilled: true);

        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Kitchen/Index')
                ->has('orders', 3)
                ->where('orders.0.id', $release->id)
                ->where('orders.1.id', $dine->id)
                ->where('orders.2.id', $pos->id)
                ->where('outlet.id', $this->outlet->id)
            );
    }

    public function test_only_the_current_outlet_is_shown(): void
    {
        [$outletB, $warehouseB] = $this->outletWithWarehouse('B');
        $this->cook->outlets()->attach($outletB->id);
        $here = $this->sale('INV-HERE');
        $there = $this->sale('INV-THERE', warehouse: $warehouseB);
        $this->kitchenItem($here);
        $this->kitchenItem($there, warehouse: $warehouseB);

        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.id', $here->id)
            );

        $this->actingAs($this->cook)->withSession(['active_outlet_id' => $outletB->id])->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.id', $there->id)
                ->where('outlet.id', $outletB->id)
            );
    }

    public function test_a_sale_from_the_outlets_second_warehouse_is_shown(): void
    {
        $second = Warehouse::create([
            'outlet_id' => $this->outlet->id, 'code' => 'WH-A2', 'name' => 'Gudang A2', 'type' => 'branch', 'is_active' => true, 'sort_order' => 9,
        ]);
        $sale = $this->sale('INV-SECOND', warehouse: $second);
        $this->kitchenItem($sale, warehouse: $second);

        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.id', $sale->id)
            );
    }

    public function test_user_without_an_outlet_gets_an_empty_queue(): void
    {
        $loner = $this->userWith('dine-orders-prepare');
        $this->kitchenItem($this->sale('INV-HERE'));

        $this->actingAs($loner)->get(route('kitchen.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders', [])
                ->where('outlet', null)
            );
    }

    public function test_shows_table_name_or_invoice_and_type(): void
    {
        $dine = $this->sale('INV-DINE', table: 'Meja 5');
        $takeaway = $this->sale('INV-TAKEAWAY', orderType: 'takeaway');
        $this->kitchenItem($dine, acceptedAt: now()->subMinutes(2), qty: 3);
        $this->kitchenItem($takeaway, acceptedAt: now()->subMinute());

        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.0.number', 'Meja 5')
                ->where('orders.0.type', 'dine_in')
                ->where('orders.0.unpaid', false)
                ->where('orders.0.items.0.title', $dine->details->first()->product->title)
                ->where('orders.0.items.0.qty', 3)
                ->where('orders.0.items.0.note', null)
                ->where('orders.0.items.0.status', 'accepted')
                ->where('orders.1.number', 'INV-TAKEAWAY')
                ->where('orders.1.type', 'takeaway')
            );
    }

    public function test_item_note_is_shown(): void
    {
        if (! Schema::hasColumn('kitchen_items', 'note')) {
            $this->markTestSkipped('kitchen_items.note arrives with a later #236 change.');
        }

        $this->kitchenItem($this->sale('INV-NOTE'), note: 'tanpa pedas');

        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page->where('orders.0.items.0.note', 'tanpa pedas'));
    }

    public function test_unpaid_dine_in_bill_is_marked_unpaid(): void
    {
        $bill = $this->sale('INV-BILL', table: 'Meja 7', paymentStatus: 'pending');
        $this->kitchenItem($bill);

        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('orders.0.number', 'Meja 7')
                ->where('orders.0.unpaid', true)
            );
    }

    public function test_pending_transfer_is_absent_until_confirmed(): void
    {
        $sale = $this->sale('INV-TRANSFER', paymentStatus: 'pending', flagged: true);

        app(KitchenIntakeService::class)->intake($sale->fresh());
        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page->has('orders', 0));

        $sale->update(['payment_status' => 'paid']);
        app(KitchenIntakeService::class)->intake($sale->fresh());
        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.id', $sale->id)
            );
    }

    public function test_mixed_cart_shows_only_flagged_lines(): void
    {
        $sale = $this->sale('INV-MIXED', flagged: true);
        $plain = $this->product('Air mineral', flagged: false);
        $this->detail($sale, $plain);

        app(KitchenIntakeService::class)->intake($sale->fresh());

        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->has('orders.0.items', 1)
                ->where('orders.0.items.0.title', $sale->details->first()->product->title)
            );
    }

    public function test_served_items_leave_the_queue(): void
    {
        $sale = $this->sale('INV-SERVE');
        $second = $this->sale('INV-STILL');
        $this->kitchenItem($sale, status: KitchenItem::STATUS_SERVED);
        $this->kitchenItem($second, status: KitchenItem::STATUS_READY);

        $this->actingAs($this->cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.id', $second->id)
                ->where('orders.0.items.0.status', 'ready')
            );
    }

    public function test_users_without_prepare_or_handover_are_refused(): void
    {
        $this->actingAs($this->userWith('dine-orders-access', $this->outlet))
            ->get(route('kitchen.index'))
            ->assertForbidden();
    }

    public function test_either_permission_is_enough(): void
    {
        $this->actingAs($this->cook)->get(route('kitchen.index'))->assertOk();

        $this->actingAs($this->userWith('dine-orders-handover', $this->outlet))
            ->get(route('kitchen.index'))
            ->assertOk();
    }

    public function test_expired_session_poll_redirects_to_login(): void
    {
        // The display polls with plain authenticated GETs: past the absolute lifetime it must see the login page, not an empty queue.
        $this->withSession(['security.session_started_at' => now()->subHours(13)->timestamp])
            ->actingAs($this->cook)
            ->get(route('kitchen.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
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

    private function userWith(string $permission, ?Outlet $outlet = null): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permission);
        if ($outlet) {
            $user->outlets()->attach($outlet->id, ['is_default' => true]);
        }

        return $user;
    }

    private function product(string $title, bool $flagged = true): Product
    {
        return Product::create([
            'title' => $title, 'sku' => 'SKU-'.++$this->seq, 'barcode' => 'BC-'.$this->seq, 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $this->category->id, 'needs_preparation' => $flagged,
        ]);
    }

    /** A sale with one line (a flagged product), optionally tied to a dine-in table. */
    private function sale(
        string $invoice,
        ?string $table = null,
        ?string $orderType = null,
        string $paymentStatus = 'paid',
        ?Warehouse $warehouse = null,
        bool $flagged = true,
        ?string $note = null,
    ): Transaction {
        $transaction = Transaction::create(array_filter([
            'cashier_id' => $this->cook->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'invoice' => $invoice,
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0,
            'grand_total' => 10000,
            'payment_method' => 'cash',
            'payment_status' => $paymentStatus,
            'order_type' => $orderType,
        ], fn ($value) => $value !== null));

        $this->detail($transaction, $this->product('Menu '.$invoice, $flagged));

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

        return $transaction->load('details.product');
    }

    private function detail(Transaction $transaction, Product $product): TransactionDetail
    {
        return TransactionDetail::create([
            'transaction_id' => $transaction->id, 'product_id' => $product->id, 'qty' => 1, 'conversion_factor' => 1,
            'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000, 'discount_total' => 0, 'cost_total' => 0,
        ]);
    }

    /** One kitchen item for the sale's first line, as intake would have created it. */
    private function kitchenItem(
        Transaction $sale,
        ?Warehouse $warehouse = null,
        string $status = KitchenItem::STATUS_ACCEPTED,
        ?Carbon $acceptedAt = null,
        ?Carbon $createdAt = null,
        ?string $note = null,
        int $qty = 1,
        bool $backfilled = false,
    ): KitchenItem {
        $detail = $sale->details->first();
        $item = new KitchenItem([
            'transaction_id' => $sale->id,
            'transaction_detail_id' => $detail->id,
            'product_id' => $detail->product_id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'qty' => $qty,
            'status' => $status,
            'accepted_at' => $backfilled ? null : ($acceptedAt ?? now()),
        ] + ($note ? ['note' => $note] : []));
        if ($createdAt) {
            $item->created_at = $createdAt;
        }
        $item->save();

        return $item;
    }
}
