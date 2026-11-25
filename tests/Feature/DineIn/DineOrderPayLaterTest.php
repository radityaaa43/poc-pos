<?php

namespace Tests\Feature\DineIn;

use App\Models\Category;
use App\Models\DineArea;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\KitchenIntakeService;
use App\Services\KitchenItemService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

// #279: a guest orders from the table QR and chooses Bayar Belakangan; it reaches the kitchen at accept, unpaid.
class DineOrderPayLaterTest extends TestCase
{
    use RefreshDatabase;

    private const REFUSAL = 'Bayar Belakangan tidak tersedia di outlet ini. Pilih Bayar di kasir.';

    private User $cashier;

    private User $admin;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private DiningTable $table;

    private Product $nasi;

    private Product $mineral;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();
        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();

        $this->outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true, 'is_sales_enabled' => true]);
        $this->warehouse = Warehouse::create([
            'outlet_id' => $this->outlet->id, 'code' => 'WH-1', 'name' => 'Gudang 1', 'type' => 'branch', 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->table = $this->tableAt($this->outlet, 'Meja 7');
        Setting::setForOutlet('pay_later_enabled', '1', $this->outlet);

        $category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        $this->nasi = $this->product('Nasi goreng', $category, flagged: true);
        $this->mineral = $this->product('Air mineral', $category, flagged: false);

        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);
    }

    private function tableAt(Outlet $outlet, string $name): DiningTable
    {
        $area = DineArea::create(['outlet_id' => $outlet->id, 'name' => 'Area '.$name, 'is_active' => true]);

        return DiningTable::create(['dine_area_id' => $area->id, 'name' => $name, 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);
    }

    private function product(string $title, Category $category, bool $flagged): Product
    {
        $product = Product::create([
            'title' => $title, 'sku' => 'SKU-'.uniqid(), 'barcode' => 'BC-'.uniqid(), 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $category->id, 'needs_preparation' => $flagged,
        ]);
        $product->warehouses()->attach($this->warehouse->id, ['stock' => 100]);

        return $product;
    }

    /** @param  array<int, array{0: Product, 1: int}>  $lines */
    private function payload(array $lines, string $option): array
    {
        return [
            'items' => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'qty' => $line[1]], $lines),
            'payment_option' => $option,
        ];
    }

    /** A guest QR order (10000 per unit) with the given payment option, still waiting for staff. */
    private function placeOrder(array $lines, string $option = DineOrder::PAY_LATER): DineOrder
    {
        $this->post(route('dine-order.store', $this->table->token), $this->payload($lines, $option))->assertSessionHasNoErrors();

        return DineOrder::latest('id')->firstOrFail();
    }

    private function accept(DineOrder $order): DineOrder
    {
        $this->actingAs($this->cashier)->post(route('dine-orders.accept', $order))->assertSessionHasNoErrors();

        return $order->fresh();
    }

    /** Settle the whole bill in cash, as $by (the cashier by default). */
    private function pay(DineOrder $order, ?User $by = null): void
    {
        $total = (int) $order->fresh()->transaction->grand_total;

        $this->actingAs($by ?? $this->cashier)
            ->post(route('dine-orders.pay', $order), ['tenders' => [['method' => 'cash', 'amount' => $total, 'cash_received' => $total]]])
            ->assertSessionHasNoErrors();
    }

    /** Kitchen starts it and marks it ready, then someone serves it. */
    private function fulfil(DineOrder $order): void
    {
        $this->actingAs($this->cashier)->post(route('dine-orders.prepare', $order))->assertSessionHasNoErrors();
        $this->actingAs($this->cashier)->post(route('dine-orders.ready', $order))->assertSessionHasNoErrors();
        $this->actingAs($this->cashier)->post(route('dine-orders.serve', $order))->assertSessionHasNoErrors();
    }

    public function test_menu_exposes_pay_later_enabled_per_outlet(): void
    {
        $other = Outlet::create(['code' => 'OUT-2', 'name' => 'Outlet 2', 'is_active' => true, 'is_sales_enabled' => true]);
        $otherTable = $this->tableAt($other, 'Meja B');
        $noOutletTable = DiningTable::create(['name' => 'Meja Lepas', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);

        // on for this outlet; the other outlet has no row at all, so it is off, and so is a table without an outlet
        $this->get(route('dine.menu', $this->table->token))->assertInertia(fn (Assert $page) => $page->where('payLaterEnabled', true));
        $this->get(route('dine.menu', $otherTable->token))->assertInertia(fn (Assert $page) => $page->where('payLaterEnabled', false));
        $this->get(route('dine.menu', $noOutletTable->token))->assertInertia(fn (Assert $page) => $page->where('payLaterEnabled', false));

        // switching this outlet off leaves the other one as it was
        Setting::setForOutlet('pay_later_enabled', '0', $this->outlet);
        Setting::setForOutlet('pay_later_enabled', '1', $other);
        $this->get(route('dine.menu', $this->table->token))->assertInertia(fn (Assert $page) => $page->where('payLaterEnabled', false));
        $this->get(route('dine.menu', $otherTable->token))->assertInertia(fn (Assert $page) => $page->where('payLaterEnabled', true));
    }

    public function test_store_refuses_pay_later_when_setting_off(): void
    {
        Setting::setForOutlet('pay_later_enabled', '0', $this->outlet);
        $noOutletTable = DiningTable::create(['name' => 'Meja Lepas', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);

        $this->post(route('dine-order.store', $this->table->token), $this->payload([[$this->nasi, 1]], DineOrder::PAY_LATER))
            ->assertSessionHasErrors(['order' => self::REFUSAL]);
        // a table with no outlet has no setting to read: off
        $this->post(route('dine-order.store', $noOutletTable->token), $this->payload([[$this->nasi, 1]], DineOrder::PAY_LATER))
            ->assertSessionHasErrors(['order' => self::REFUSAL]);

        $this->assertDatabaseCount('dine_orders', 0);
    }

    public function test_store_accepts_pay_later_when_on(): void
    {
        $order = $this->placeOrder([[$this->nasi, 2]]);

        $this->assertSame(DineOrder::STATUS_SUBMITTED, $order->status);
        $this->assertSame(DineOrder::PAY_LATER, $order->payment_option);
        $this->assertNull($order->transaction_id);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('kitchen_items', 0);
    }

    public function test_store_refuses_with_message_when_setting_turned_off_after_menu_load(): void
    {
        $this->get(route('dine.menu', $this->table->token))->assertInertia(fn (Assert $page) => $page->where('payLaterEnabled', true));

        Setting::setForOutlet('pay_later_enabled', '0', $this->outlet); // the owner switches it off while the guest has the menu open

        $this->post(route('dine-order.store', $this->table->token), $this->payload([[$this->nasi, 1]], DineOrder::PAY_LATER))
            ->assertSessionHasErrors(['order' => self::REFUSAL]);
        $this->assertDatabaseCount('dine_orders', 0);

        // Bayar di kasir is still open to the guest
        $this->placeOrder([[$this->nasi, 1]], DineOrder::PAY_AT_COUNTER);
        $this->assertDatabaseCount('dine_orders', 1);
    }

    public function test_accepting_a_pay_later_order_creates_kitchen_items_unpaid(): void
    {
        $order = $this->accept($this->placeOrder([[$this->nasi, 2]]));
        $transaction = $order->transaction;

        $this->assertSame('unpaid', $transaction->payment_status);
        $this->assertTrue($transaction->is_pay_later);
        $this->assertSame(Transaction::METHOD_OPEN_BILL, $transaction->payment_method);

        $item = KitchenItem::sole();
        $this->assertEquals($transaction->id, $item->transaction_id);
        $this->assertEquals($this->nasi->id, $item->product_id);
        $this->assertEquals($this->warehouse->id, $item->warehouse_id);
        $this->assertSame(2, $item->qty);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->status);
        $this->assertNotNull($item->accepted_at);
        $this->assertEquals($this->cashier->id, $item->accepted_by);
        $this->assertSame(DineOrder::STATUS_ACCEPTED, $order->status); // something to make: the kitchen drives it from here
    }

    public function test_pay_later_qr_bill_has_no_receivable_and_is_listed_under_the_table(): void
    {
        $order = $this->accept($this->placeOrder([[$this->nasi, 2]]));
        $transaction = $order->transaction;

        $this->assertDatabaseCount('receivables', 0);
        $this->assertNull($transaction->customer_id);
        $this->assertContains($transaction->id, Transaction::openBills()->pluck('id')->all());
        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('openBills', 1)
                ->where('openBills.0.id', $transaction->id)
                ->where('openBills.0.label', 'Meja 7')
                ->where('openBills.0.table', 'Meja 7')
                ->where('openBills.0.grand_total', 20000));
    }

    public function test_bill_belongs_to_the_accepting_shift(): void
    {
        $order = $this->accept($this->placeOrder([[$this->nasi, 1]]));
        $shift = $this->cashier->cashierShifts()->open()->firstOrFail();

        $this->assertEquals($shift->id, $order->transaction->cashier_shift_id);
        $this->assertEquals($this->cashier->id, $order->transaction->cashier_id);
    }

    public function test_settle_in_another_shift_moves_the_payment_to_that_shift(): void
    {
        $order = $this->accept($this->placeOrder([[$this->nasi, 1]]));
        $adminShift = app(CashierShiftService::class)->openShift($this->admin, $this->admin, 0, null, $this->warehouse->id);

        $this->pay($order, $this->admin);

        $transaction = $order->transaction->fresh();
        $this->assertSame('paid', $transaction->payment_status);
        $this->assertEquals($this->admin->id, $transaction->cashier_id);
        $this->assertEquals($adminShift->id, $transaction->cashier_shift_id);
    }

    public function test_rejected_pay_later_order_has_no_kitchen_items(): void
    {
        $order = $this->placeOrder([[$this->nasi, 2]]);

        $this->actingAs($this->cashier)->post(route('dine-orders.reject', $order), ['reason' => 'Habis'])->assertSessionHasNoErrors();

        $this->assertSame(DineOrder::STATUS_REJECTED, $order->fresh()->status);
        $this->assertDatabaseCount('kitchen_items', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_failed_accept_leaves_no_kitchen_items(): void
    {
        // A rolled-back accept never reaches the after-commit kitchen step.
        $kitchen = $this->mock(KitchenIntakeService::class);
        $kitchen->shouldNotReceive('intakeUnpaid');
        $this->mock(KitchenItemService::class)->shouldNotReceive('serveIfNoKitchenItems');
        $order = $this->placeOrder([[$this->nasi, 5]]);

        // stock drops below the ordered quantity after the guest submitted
        $this->warehouse->products()->updateExistingPivot($this->nasi->id, ['stock' => 2]);
        $this->nasi->update(['stock' => 2]);

        $this->actingAs($this->cashier)->from(route('dine-orders.index'))
            ->post(route('dine-orders.accept', $order))
            ->assertSessionHasErrors('stock');

        $this->assertSame(DineOrder::STATUS_SUBMITTED, $order->fresh()->status);
        $this->assertDatabaseCount('kitchen_items', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_flagged_pay_later_order_is_not_served_when_the_intake_skipped_its_lines(): void
    {
        $order = $this->accept($this->placeOrder([[$this->nasi, 1]]));
        $transaction = $order->transaction;
        // A sale with no warehouse has no outlet to cook for: the intake skips it and leaves no kitchen rows.
        KitchenItem::query()->delete();
        $transaction->update(['warehouse_id' => null]);

        app(KitchenIntakeService::class)->intakeUnpaid($transaction->fresh());
        app(KitchenItemService::class)->serveIfNoKitchenItems($transaction->fresh());

        $this->assertDatabaseCount('kitchen_items', 0);
        $this->assertSame(DineOrder::STATUS_ACCEPTED, $order->fresh()->status);
        $this->assertNull($order->fresh()->served_at);
    }

    public function test_pay_at_counter_order_still_waits_for_payment(): void
    {
        $order = $this->accept($this->placeOrder([[$this->nasi, 2]], DineOrder::PAY_AT_COUNTER));

        $this->assertFalse($order->transaction->is_pay_later);
        $this->assertSame(DineOrder::STATUS_ACCEPTED, $order->status);
        $this->assertDatabaseCount('kitchen_items', 0);

        $this->pay($order);

        $this->assertSame(1, KitchenItem::count());
        $this->assertEquals($order->transaction_id, KitchenItem::sole()->transaction_id);
    }

    public function test_all_unflagged_pay_later_order_is_served_at_accept(): void
    {
        $order = $this->accept($this->placeOrder([[$this->mineral, 2]]));

        $this->assertDatabaseCount('kitchen_items', 0);
        $this->assertSame(DineOrder::STATUS_SERVED, $order->status);
        $this->assertNotNull($order->served_at);
        $this->assertNull($order->served_by); // served by the system, as in the pay-now half (#326)
        $this->assertNull($order->completed_at); // still unpaid
        $this->assertSame('unpaid', $order->transaction->payment_status);
    }

    public function test_all_unflagged_pay_later_order_completes_when_paid(): void
    {
        $order = $this->accept($this->placeOrder([[$this->mineral, 2]]));

        $this->pay($order);

        $order->refresh();
        $this->assertSame(DineOrder::STATUS_COMPLETED, $order->status);
        $this->assertNotNull($order->completed_at);
        $this->assertDatabaseCount('kitchen_items', 0);
        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page->has('openBills', 0));
    }

    public function test_flagged_pay_later_order_completes_after_served_and_paid(): void
    {
        $order = $this->accept($this->placeOrder([[$this->nasi, 2]]));

        $this->pay($order);
        $this->assertSame(DineOrder::STATUS_ACCEPTED, $order->fresh()->status);
        $this->fulfil($order);

        $order->refresh();
        $this->assertSame(DineOrder::STATUS_COMPLETED, $order->status);
        $this->assertNotNull($order->completed_at);
        $this->assertSame(1, KitchenItem::count());
    }

    public function test_an_unpaid_pay_later_item_can_be_started_ready_and_handed_over(): void
    {
        $order = $this->accept($this->placeOrder([[$this->nasi, 2]]));
        $item = KitchenItem::firstOrFail();

        // The kitchen items exist from accept, so the steps need no payment (BR-3): through the item service, as the kitchen display does.
        app(KitchenItemService::class)->start($item, $this->cashier);
        $this->assertSame(DineOrder::STATUS_PREPARING, $order->fresh()->status);
        app(KitchenItemService::class)->ready($item->fresh(), $this->cashier);
        $this->assertSame(DineOrder::STATUS_READY, $order->fresh()->status);
        $this->actingAs($this->cashier)->post(route('dine-orders.serve', $order))->assertSessionHasNoErrors();

        $this->assertSame(DineOrder::STATUS_SERVED, $order->fresh()->status);
        $this->assertSame('unpaid', $order->fresh()->transaction->payment_status);

        $this->pay($order);

        $this->assertSame(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame(1, KitchenItem::count());
    }

    public function test_the_queue_steps_start_an_unpaid_pay_later_order_through_its_items(): void
    {
        $order = $this->accept($this->placeOrder([[$this->nasi, 2]]));

        $this->actingAs($this->cashier)->post(route('dine-orders.prepare', $order))->assertSessionHasNoErrors();
        $this->actingAs($this->cashier)->post(route('dine-orders.ready', $order))->assertSessionHasNoErrors();

        $this->assertSame(DineOrder::STATUS_READY, $order->fresh()->status);
        $this->assertSame('unpaid', $order->fresh()->transaction->payment_status);
    }

    public function test_flagged_pay_later_order_completes_when_paid_after_it_was_served(): void
    {
        $order = $this->accept($this->placeOrder([[$this->nasi, 2]]));
        // Served before paid: set the state directly (the real path is covered by the unpaid steps test above).
        $order->update(['status' => DineOrder::STATUS_SERVED, 'served_at' => now(), 'served_by' => $this->cashier->id]);
        $this->assertSame(DineOrder::STATUS_SERVED, $order->fresh()->status);

        $this->pay($order);

        $this->assertSame(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
    }

    public function test_settling_a_pay_later_order_keeps_the_same_kitchen_items(): void
    {
        $order = $this->accept($this->placeOrder([[$this->nasi, 2]]));
        $before = KitchenItem::sole()->only(['id', 'product_id', 'status', 'accepted_at']);

        $this->pay($order);

        $this->assertSame(1, KitchenItem::count());
        $this->assertEquals($before, KitchenItem::sole()->only(['id', 'product_id', 'status', 'accepted_at']));
    }

    public function test_mixed_pay_later_order_creates_items_only_for_flagged_lines(): void
    {
        $order = $this->accept($this->placeOrder([[$this->nasi, 1], [$this->mineral, 1]]));

        $this->assertSame(2, $order->transaction->details()->count());
        $this->assertSame([$this->nasi->id], KitchenItem::pluck('product_id')->all());
        $this->assertSame(DineOrder::STATUS_ACCEPTED, $order->status); // there is something to make
    }

    public function test_staff_can_accept_a_pay_later_order_after_the_setting_was_turned_off(): void
    {
        $order = $this->placeOrder([[$this->nasi, 2]]);
        Setting::setForOutlet('pay_later_enabled', '0', $this->outlet); // the guest ordered under the rule shown

        $order = $this->accept($order);

        $this->assertSame(DineOrder::STATUS_ACCEPTED, $order->status);
        $this->assertTrue($order->transaction->is_pay_later);
        $this->assertSame(1, KitchenItem::count());
    }

    public function test_a_kitchen_failure_neither_undoes_the_accept_nor_serves_the_order(): void
    {
        Exceptions::fake();
        $kitchen = $this->mock(KitchenIntakeService::class);
        $kitchen->shouldReceive('intakeUnpaid')->once()->andThrow(new RuntimeException('kitchen down'));
        // no kitchen items because the intake failed is not "nothing to make"
        $this->mock(KitchenItemService::class)->shouldNotReceive('serveIfNoKitchenItems');

        $order = $this->accept($this->placeOrder([[$this->mineral, 1]]));

        $this->assertSame(DineOrder::STATUS_ACCEPTED, $order->status);
        $this->assertNotNull($order->transaction_id);
        $this->assertSame('unpaid', $order->transaction->payment_status);
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'kitchen down');
    }
}
