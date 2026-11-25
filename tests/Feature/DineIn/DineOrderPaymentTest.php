<?php

namespace Tests\Feature\DineIn;

use App\Exceptions\PaymentGatewayException;
use App\Models\BankAccount;
use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\Payments\PaymentGatewayManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
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

    /** Kitchen starts it and marks it ready, then someone serves it. */
    private function fulfil(DineOrder $order): void // the bill must already be paid
    {
        $this->actingAs($this->cashier)->post(route('dine-orders.prepare', $order))->assertSessionHasNoErrors();
        $this->actingAs($this->cashier)->post(route('dine-orders.ready', $order))->assertSessionHasNoErrors();
        $this->actingAs($this->cashier)->post(route('dine-orders.serve', $order))->assertSessionHasNoErrors();
    }

    private function enableMidtrans(): void
    {
        PaymentSetting::create([
            'default_gateway' => 'cash', 'midtrans_enabled' => true, 'midtrans_server_key' => 'sk-test', 'midtrans_client_key' => 'ck-test',
        ]);
    }

    private function qrisTender(): array
    {
        return ['tenders' => [['method' => 'qris', 'amount' => 20000]]];
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
        $this->fulfil($order);

        $this->assertEquals(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
        $this->actingAs($this->admin)->get(route('dine-orders.index'))
            ->assertInertia(fn (Assert $page) => $page->has('orders', 0));
        $this->get(route('dine-order.status-check', $order->access_token))
            ->assertJsonPath('order.status', 'completed');
    }

    public function test_mulai_buat_is_refused_while_the_bill_is_unpaid(): void
    {
        $order = $this->acceptedOrder();

        $this->actingAs($this->cashier)->post(route('dine-orders.prepare', $order))->assertSessionHasErrors('order');

        $order->refresh();
        $this->assertEquals(DineOrder::STATUS_ACCEPTED, $order->status);
        $this->assertNull($order->preparing_at);
        $this->assertNull($order->preparing_by);

        // the unpaid order is listed (the UI shows it as Menunggu Pembayaran), and is in the POS "Bayar Belakangan · Tamu" section
        $this->actingAs($this->admin)->get(route('dine-orders.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('orders', 1)
                ->where('orders.0.transaction.payment_status', 'unpaid'));
        $this->get(route('dine-order.status-check', $order->access_token))
            ->assertJsonPath('order.is_paid', false)
            ->assertJsonMissingPath('order.transaction');
    }

    public function test_mulai_buat_is_allowed_once_the_bill_is_paid_and_serving_then_completes_the_order(): void
    {
        $order = $this->acceptedOrder();
        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->cashTender())->assertSessionHasNoErrors();

        $this->get(route('dine-order.status-check', $order->access_token))->assertJsonPath('order.is_paid', true);

        $this->fulfil($order);

        // paid before it was made, so serving is the last step
        $order->refresh();
        $this->assertEquals(DineOrder::STATUS_COMPLETED, $order->status);
        $this->assertNotNull($order->completed_at);
        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page->has('openBills', 0));
    }

    public function test_mulai_buat_is_refused_while_a_bank_transfer_awaits_confirmation_then_allowed_after_it(): void
    {
        $order = $this->acceptedOrder();
        $account = BankAccount::create([
            'bank_name' => 'BCA', 'account_number' => '123', 'account_name' => 'Toko', 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), [
            'tenders' => [['method' => 'bank_transfer', 'amount' => 20000, 'bank_account_id' => $account->id]],
        ])->assertSessionHasNoErrors();
        $this->assertEquals('pending', $order->transaction->fresh()->payment_status);

        $this->actingAs($this->cashier)->post(route('dine-orders.prepare', $order))->assertSessionHasErrors('order');
        $this->assertEquals(DineOrder::STATUS_ACCEPTED, $order->fresh()->status);
        $this->actingAs($this->admin)->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('transactions.confirm-payment', $order->transaction))->assertSessionHasNoErrors();
        $this->actingAs($this->cashier)->patch(route('transactions.confirm-payment', $order->transaction))->assertSessionHasNoErrors();
        $this->assertEquals('paid', $order->transaction->fresh()->payment_status);

        $this->actingAs($this->cashier)->post(route('dine-orders.prepare', $order))->assertSessionHasNoErrors();
        $this->assertEquals(DineOrder::STATUS_PREPARING, $order->fresh()->status);
    }

    // Safety net only: serving needs a paid bill, so a served-but-unpaid order cannot normally occur.
    public function test_any_path_that_marks_a_served_orders_bill_paid_completes_it(): void
    {
        $order = $this->acceptedOrder();
        $order->update(['status' => DineOrder::STATUS_SERVED, 'served_at' => now()]);

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

    public function test_each_step_is_valid_only_from_the_previous_status_and_repeating_it_is_a_no_op(): void
    {
        $order = $this->acceptedOrder();
        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->cashTender())->assertSessionHasNoErrors();
        $post = fn (string $step) => $this->actingAs($this->cashier)->post(route("dine-orders.$step", $order));

        $post('ready')->assertSessionHasErrors('order');
        $post('serve')->assertSessionHasErrors('order');
        $this->assertEquals(DineOrder::STATUS_ACCEPTED, $order->fresh()->status);

        $post('prepare')->assertSessionHasNoErrors();
        $preparingAt = $order->fresh()->preparing_at;
        $this->assertEquals(DineOrder::STATUS_PREPARING, $order->fresh()->status);
        $post('prepare')->assertSessionHasNoErrors();
        $this->assertEquals($preparingAt, $order->fresh()->preparing_at);
        $post('serve')->assertSessionHasErrors('order');

        $post('ready')->assertSessionHasNoErrors();
        $post('ready')->assertSessionHasNoErrors();
        $this->assertEquals(DineOrder::STATUS_READY, $order->fresh()->status);

        // already paid, so serving completes it; repeating any step afterwards is a no-op
        $post('serve')->assertSessionHasNoErrors();
        $this->assertEquals(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertNotNull($order->fresh()->served_at);
        $post('serve')->assertSessionHasNoErrors();
        $post('prepare')->assertSessionHasNoErrors();
        $this->assertEquals(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
    }

    public function test_a_full_run_records_when_and_who_for_every_step_and_a_repeat_does_not_overwrite_it(): void
    {
        $order = $this->acceptedOrder(); // accepted by the cashier
        $kitchen = User::factory()->create();
        $kitchen->assignRole(Role::create(['name' => 'kitchen'])->givePermissionTo('dine-orders-access', 'dine-orders-prepare'));

        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->cashTender())->assertSessionHasNoErrors();
        $this->actingAs($kitchen)->post(route('dine-orders.prepare', $order))->assertSessionHasNoErrors();
        $this->actingAs($kitchen)->post(route('dine-orders.ready', $order))->assertSessionHasNoErrors();
        $this->assertNull($order->fresh()->completed_at);
        $this->actingAs($this->admin)->post(route('dine-orders.serve', $order))->assertSessionHasNoErrors();

        // another user repeating a step changes nothing
        $this->actingAs($this->admin)->post(route('dine-orders.prepare', $order))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('dine-orders.ready', $order))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post(route('dine-orders.serve', $order))->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertEquals(DineOrder::STATUS_COMPLETED, $order->status);
        foreach (['accepted_at', 'preparing_at', 'ready_at', 'served_at', 'completed_at'] as $column) {
            $this->assertNotNull($order->{$column}, $column);
        }
        $this->assertSame(
            [$this->cashier->id, $kitchen->id, $kitchen->id, $this->admin->id],
            [$order->accepted_by, $order->preparing_by, $order->ready_by, $order->served_by],
        );
        $this->assertTrue($order->accepted_at <= $order->preparing_at && $order->preparing_at <= $order->ready_at
            && $order->ready_at <= $order->served_at && $order->served_at <= $order->completed_at);
    }

    public function test_a_rejected_order_cannot_be_prepared(): void
    {
        $this->acceptedOrder();
        $rejected = DineOrder::create([
            'dine_table_id' => $this->table->id, 'status' => DineOrder::STATUS_REJECTED, 'subtotal' => 0, 'item_count' => 0,
        ]);

        $this->actingAs($this->cashier)->post(route('dine-orders.prepare', $rejected))->assertSessionHasErrors('order');
    }

    public function test_each_fulfillment_step_has_its_own_permission(): void
    {
        $order = $this->acceptedOrder();
        $kitchen = User::factory()->create();
        $kitchen->assignRole(Role::create(['name' => 'kitchen'])->givePermissionTo('dine-orders-access', 'dine-orders-prepare'));

        $this->actingAs($kitchen)->post(route('dine-orders.serve', $order))->assertForbidden();
        $this->actingAs($kitchen)->post(route('dine-orders.pay', $order), $this->cashTender())->assertForbidden();
        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->cashTender())->assertSessionHasNoErrors();
        $this->actingAs($kitchen)->post(route('dine-orders.prepare', $order))->assertSessionHasNoErrors();
        $this->assertEquals(DineOrder::STATUS_PREPARING, $order->fresh()->status);
    }

    public function test_seeders_grant_the_fulfillment_permissions_to_cashier_and_manager_and_rerun_cleanly(): void
    {
        $this->seed([PermissionSeeder::class, RoleSeeder::class]);

        foreach (['cashier', 'manager'] as $role) {
            $this->assertTrue(Role::findByName($role)->hasPermissionTo('dine-orders-prepare'));
            $this->assertTrue(Role::findByName($role)->hasPermissionTo('dine-orders-handover'));
        }
        $this->assertEquals(1, Permission::where('name', 'dine-orders-prepare')->count());
    }

    public function test_a_qris_payment_charges_the_same_transaction_and_waits_for_the_webhook(): void
    {
        $this->enableMidtrans();
        $order = $this->acceptedOrder();
        $transaction = $order->transaction;
        $this->mock(PaymentGatewayManager::class)
            ->shouldReceive('createTenderPayment')
            ->once()
            ->withArgs(fn ($charged) => $charged->is($transaction))
            ->andReturn(['reference' => 'ref-1', 'payment_url' => null, 'qr_string' => '000201QR']);

        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->qrisTender())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.print', $transaction->invoice));

        $transaction->refresh();
        $this->assertEquals('pending', $transaction->payment_status);
        $this->assertEquals('qris', $transaction->payment_method);
        $this->assertEquals('000201QR', $transaction->qr_string);
        $this->assertEquals(1, Transaction::count());
        $this->assertEquals(18, $this->product->fresh()->stock);

        // still an unpaid guest bill (Belum Lunas), marked pending, until the webhook confirms it
        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page->has('openBills', 1)->where('openBills.0.pending', true));

        // a second attempt while the first is pending: no second charge, no second tender
        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->qrisTender())->assertSessionHasErrors('order');
        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->cashTender())->assertSessionHasErrors('order');
        $this->assertEquals(1, $transaction->tenders()->count());

        // a QRIS payment still pending is not paid: Mulai Buat is refused
        $this->actingAs($this->cashier)->post(route('dine-orders.prepare', $order))->assertSessionHasErrors('order');
        $this->assertEquals(DineOrder::STATUS_ACCEPTED, $order->fresh()->status);

        // Midtrans settles it: now the kitchen can start, and serving completes the order
        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($transaction->invoice, 'settlement'))->assertOk();
        $this->assertEquals('paid', $transaction->fresh()->payment_status);
        $this->assertEquals(DineOrder::STATUS_ACCEPTED, $order->fresh()->status);

        $this->fulfil($order);
        $this->assertEquals(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
    }

    public function test_a_failed_charge_leaves_the_bill_unpaid(): void
    {
        $this->enableMidtrans();
        $order = $this->acceptedOrder();
        $this->mock(PaymentGatewayManager::class)
            ->shouldReceive('createTenderPayment')->once()->andThrow(new PaymentGatewayException('Gateway down'));

        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->qrisTender())
            ->assertSessionHasErrors('tenders');

        $transaction = $order->transaction->fresh();
        $this->assertEquals('unpaid', $transaction->payment_status);
        $this->assertEquals(Transaction::METHOD_OPEN_BILL, $transaction->payment_method);
        $this->assertEquals(0, $transaction->tenders()->count());
    }

    public function test_an_expired_or_failed_gateway_payment_can_be_replaced_by_cash(): void
    {
        $this->enableMidtrans();
        $order = $this->acceptedOrder();
        $transaction = $order->transaction;
        $this->mock(PaymentGatewayManager::class)
            ->shouldReceive('createTenderPayment')->once()
            ->andReturn(['reference' => 'ref-1', 'payment_url' => null, 'qr_string' => 'QR']);
        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->qrisTender())->assertSessionHasNoErrors();

        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($transaction->invoice, 'expire'))->assertOk();
        $this->assertEquals('failed', $transaction->fresh()->payment_status);

        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), $this->cashTender())->assertSessionHasNoErrors();

        $transaction->refresh();
        $this->assertEquals('paid', $transaction->payment_status);
        $this->assertEquals('cash', $transaction->payment_method);
        $this->assertEquals(1, $transaction->tenders()->count());
        $this->assertNull($transaction->qr_string);
    }

    public function test_the_expire_command_leaves_a_pending_dine_bill_alone(): void
    {
        $order = $this->acceptedOrder();
        $transaction = $order->transaction;
        $transaction->update(['payment_method' => 'qris', 'payment_status' => 'pending', 'created_at' => now()->subDays(3)]);

        $this->artisan('transactions:expire')->assertSuccessful();

        // the food was served long after accept; the bill's age says nothing about abandonment
        $this->assertEquals('pending', $transaction->fresh()->payment_status);
        $this->assertEquals(18, $this->product->fresh()->stock);
    }

    public function test_a_gateway_tender_cannot_be_split_with_another_tender(): void
    {
        $this->enableMidtrans();
        $order = $this->acceptedOrder();

        $this->actingAs($this->cashier)->post(route('dine-orders.pay', $order), ['tenders' => [
            ['method' => 'cash', 'amount' => 5000, 'cash_received' => 5000],
            ['method' => 'qris', 'amount' => 15000],
        ]])->assertSessionHasErrors('tenders');
        $this->assertEquals('unpaid', $order->transaction->fresh()->payment_status);
    }

    private function midtransPayload(string $invoice, string $status): array
    {
        return [
            'order_id' => $invoice,
            'status_code' => '200',
            'gross_amount' => '20000.00',
            'transaction_status' => $status,
            'transaction_id' => 'mt-1',
            'signature_key' => hash('sha512', $invoice.'200'.'20000.00'.'sk-test'),
        ];
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
