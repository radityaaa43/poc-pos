<?php

namespace Tests\Feature\DineIn;

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DineOrderPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $admin;

    private Warehouse $warehouse;

    private Product $product;

    private DiningTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();
        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0,
        ]);
        $category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        $this->table = DiningTable::create(['name' => 'Meja 7', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);

        $this->product = Product::create([
            'title' => 'Rujak', 'sku' => 'SKU-PAY-1', 'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 20,
            'image' => 'products/t.jpg', 'barcode' => 'BC-PAY-1', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $category->id,
        ]);
        $this->product->warehouses()->attach($this->warehouse->id, ['stock' => 20]);
    }

    /** A guest order for 2 x 10000, accepted by the cashier (who opens a shift first). */
    private function acceptedOrder(): DineOrder
    {
        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);

        $this->post(route('dine-order.store', $this->table->token), [
            'items' => [['product_id' => $this->product->id, 'qty' => 2]],
            'payment_option' => 'pay_at_counter',
        ])->assertSessionHasNoErrors();

        $order = DineOrder::firstOrFail();
        $this->actingAs($this->cashier)->post(route('dine-orders.accept', $order))->assertSessionHasNoErrors();

        return $order->fresh();
    }

    private function cashTender(int $cashReceived = 25000): array
    {
        return ['tenders' => [['method' => 'cash', 'amount' => 20000, 'cash_received' => $cashReceived]]];
    }

    public function test_accept_leaves_the_bill_unpaid_with_a_neutral_method_and_no_receivable(): void
    {
        $order = $this->acceptedOrder();
        $transaction = $order->transaction;

        $this->assertEquals('unpaid', $transaction->payment_status);
        $this->assertEquals(Transaction::METHOD_OPEN_BILL, $transaction->payment_method);
        $this->assertNull($transaction->receivable);
    }

    public function test_paying_updates_the_accept_transaction_and_does_not_take_stock_again(): void
    {
        $order = $this->acceptedOrder();
        $this->assertEquals(18, $this->product->fresh()->stock);
        $transaction = $order->transaction;

        $this->actingAs($this->cashier)
            ->post(route('dine-orders.pay', $order), $this->cashTender())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.print', $transaction->invoice));

        $transaction->refresh();
        $this->assertEquals('paid', $transaction->payment_status);
        $this->assertEquals('cash', $transaction->payment_method);
        $this->assertEquals(25000, $transaction->cash);
        $this->assertEquals(5000, $transaction->change);
        $this->assertDatabaseHas('transaction_tenders', [
            'transaction_id' => $transaction->id, 'method' => 'cash', 'amount' => 20000, 'cash_received' => 25000, 'change' => 5000,
        ]);

        // stock was taken at accept, payment must not take it again; no second transaction, no receivable
        $this->assertEquals(18, $this->product->fresh()->stock);
        $this->assertEquals(1, Transaction::count());
        $this->assertDatabaseCount('receivables', 0);

        // paid but not served yet: not completed, still in the fulfillment queue
        $this->assertEquals(DineOrder::STATUS_ACCEPTED, $order->fresh()->status);
        $this->actingAs($this->admin)->get(route('dine-orders.index'))
            ->assertInertia(fn (Assert $page) => $page->has('orders', 1));
    }

    public function test_paid_then_served_completes_and_leaves_the_queue(): void
    {
        $order = $this->acceptedOrder();

        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->cashTender())
            ->assertSessionHasNoErrors();
        $this->actingAs($this->cashier)->post(route('dine-orders.serve', $order))->assertSessionHasNoErrors();

        $this->assertEquals(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
        $this->actingAs($this->admin)->get(route('dine-orders.index'))
            ->assertInertia(fn (Assert $page) => $page->has('orders', 0));
        $this->get(route('dine-order.status-check', $order->access_token))
            ->assertJsonPath('order.status', 'completed');
    }

    public function test_served_then_paid_stays_an_open_bill_until_paid_then_completes(): void
    {
        $order = $this->acceptedOrder();

        $this->actingAs($this->cashier)->post(route('dine-orders.serve', $order))->assertSessionHasNoErrors();
        // serving twice is harmless
        $this->actingAs($this->cashier)->post(route('dine-orders.serve', $order))->assertSessionHasNoErrors();

        $this->assertEquals(DineOrder::STATUS_ACCEPTED, $order->fresh()->status);
        $this->actingAs($this->admin)->get(route('dine-orders.index'))
            ->assertInertia(fn (Assert $page) => $page->has('orders', 0));
        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('openBills', 1)
                ->where('openBills.0.table', 'Meja 7')
                ->where('openBills.0.served', true));
        $this->get(route('dine-order.status-check', $order->access_token))
            ->assertJsonPath('order.status', 'accepted')
            ->assertJsonPath('order.served_at', fn ($servedAt) => $servedAt !== null);

        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->cashTender())
            ->assertSessionHasNoErrors();

        $this->assertEquals(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page->has('openBills', 0));
    }

    public function test_any_path_that_marks_the_bill_paid_completes_a_served_order(): void
    {
        $order = $this->acceptedOrder();
        $this->actingAs($this->cashier)->post(route('dine-orders.serve', $order))->assertSessionHasNoErrors();

        // e.g. a confirmed bank transfer: payment_status flips without going through the pay endpoint
        $order->transaction->update(['payment_status' => 'paid']);

        $this->assertEquals(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
    }

    public function test_an_unpaid_open_bill_does_not_count_in_the_shift_and_cash_counts_when_paid(): void
    {
        $order = $this->acceptedOrder();
        $shifts = app(CashierShiftService::class);
        $shift = $this->cashier->cashierShifts()->open()->firstOrFail();

        $summary = $shifts->calculateSummary($shift);
        $this->assertEquals(0, $summary['cash_sales_total']);
        $this->assertEquals(0, $summary['non_cash_sales_total']);

        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->cashTender())
            ->assertSessionHasNoErrors();

        $summary = $shifts->calculateSummary($shift->fresh());
        $this->assertEquals(20000, $summary['cash_sales_total']);
        $this->assertEquals(120000, $summary['expected_cash']);
    }

    public function test_the_payment_belongs_to_the_paying_cashiers_shift(): void
    {
        $order = $this->acceptedOrder();
        $shifts = app(CashierShiftService::class);
        $adminShift = $shifts->openShift($this->admin, $this->admin, 0, null, $this->warehouse->id);

        $this->actingAs($this->admin)->post(route('dine-orders.pay', $order), $this->cashTender())
            ->assertSessionHasNoErrors();

        $transaction = $order->transaction->fresh();
        $this->assertEquals($this->admin->id, $transaction->cashier_id);
        $this->assertEquals($adminShift->id, $transaction->cashier_shift_id);
        $this->assertEquals(20000, $shifts->calculateSummary($adminShift->fresh())['cash_sales_total']);
    }

    public function test_bank_transfer_records_the_destination_account(): void
    {
        $order = $this->acceptedOrder();
        $account = BankAccount::create([
            'bank_name' => 'BCA', 'account_number' => '123', 'account_name' => 'Toko', 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), [
            'tenders' => [['method' => 'bank_transfer', 'amount' => 20000, 'bank_account_id' => $account->id]],
        ])->assertSessionHasNoErrors();

        $transaction = $order->transaction->fresh();
        // a transfer waits for the cashier's confirmation (#153); the order completes when that marks it paid
        $this->assertEquals('pending', $transaction->payment_status);
        $this->assertEquals(DineOrder::STATUS_ACCEPTED, $order->fresh()->status);
        $this->assertEquals('bank_transfer', $transaction->payment_method);
        $this->assertEquals($account->id, $transaction->bank_account_id);
    }

    public function test_rejects_short_cash_gateway_methods_and_a_second_payment(): void
    {
        $order = $this->acceptedOrder();

        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->cashTender(10000))
            ->assertSessionHasErrors('tenders.0.cash_received');

        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), [
            'tenders' => [['method' => 'qris', 'amount' => 20000]],
        ])->assertSessionHasErrors('tenders');

        $this->assertEquals('unpaid', $order->transaction->fresh()->payment_status);
        $this->assertEquals(DineOrder::STATUS_ACCEPTED, $order->fresh()->status);

        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->cashTender())
            ->assertSessionHasNoErrors();
        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->cashTender())
            ->assertSessionHasErrors('order');
    }

    public function test_a_submitted_order_cannot_be_paid(): void
    {
        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 0, null, $this->warehouse->id);
        $this->post(route('dine-order.store', $this->table->token), [
            'items' => [['product_id' => $this->product->id, 'qty' => 1]],
            'payment_option' => 'pay_at_counter',
        ]);

        $this->actingAs($this->cashier)->post(route('dine-orders.pay', DineOrder::firstOrFail()), $this->cashTender())
            ->assertSessionHasErrors('order');
    }

    public function test_history_lists_the_unpaid_dine_bill_with_its_table(): void
    {
        $this->acceptedOrder();

        $this->actingAs($this->cashier)->get(route('transactions.history'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('transactions.data.0.payment_status', 'unpaid')
                ->where('transactions.data.0.dine_order.table.name', 'Meja 7')
                ->has('bankAccounts'));
    }
}
