<?php

namespace Tests\Feature\PayLater;

use App\Exceptions\PaymentGatewayException;
use App\Models\BankAccount;
use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SettleGuestBillTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions-access', 'cashier-shifts-access'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true]);
        $this->warehouse = Warehouse::create([
            'code' => 'WH-1', 'name' => 'Gudang Outlet 1', 'status' => 'active', 'outlet_id' => $this->outlet->id,
        ]);
        $category = Category::create(['name' => 'Kategori', 'image' => '', 'description' => '']);
        $this->product = Product::create([
            'title' => 'Produk Kasir', 'barcode' => 'SG-001', 'sku' => 'SKU-SG-001', 'image' => '', 'description' => '',
            'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 50, 'category_id' => $category->id,
            'tax_type' => 'exclusive', 'tax_rate' => 0, 'min_stock' => 0, 'max_stock' => 100, 'is_composite' => false,
            'needs_preparation' => true,
        ]);
        $this->product->warehouses()->attach($this->warehouse->id, ['stock' => 50]);

        $this->cashier = $this->cashierAt($this->warehouse);
        Setting::setForOutlet('pay_later_enabled', '1', $this->outlet);
    }

    /** A cashier with transactions access and an open shift at the warehouse. */
    private function cashierAt(Warehouse $warehouse): User
    {
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->givePermissionTo(['transactions-access', 'cashier-shifts-access']);
        app(CashierShiftService::class)->openShift($user, $user, 100000, null, $warehouse->id);

        return $user;
    }

    /** A guest pay-later bill (no customer, a note, no receivable) of qty x 10000, made by the first cashier. */
    private function guestBill(int $qty = 1): Transaction
    {
        Cart::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id, 'qty' => $qty, 'price' => $this->product->sell_price,
        ]);
        Sanctum::actingAs($this->cashier, ['*']);
        $this->postJson('/api/v1/pos/checkout', ['payment_method' => 'pay_later', 'note' => 'Meja 4'])->assertSuccessful();

        return Transaction::latest('id')->firstOrFail();
    }

    private function settle(Transaction $bill, array $tenders, ?User $by = null)
    {
        return $this->actingAs($by ?? $this->cashier)->post(route('transactions.settle', $bill), ['tenders' => $tenders]);
    }

    private function cash(int $amount = 10000, ?int $received = null): array
    {
        return [['method' => 'cash', 'amount' => $amount, 'cash_received' => $received ?? $amount]];
    }

    private function enableMidtrans(): void
    {
        PaymentSetting::create([
            'default_gateway' => 'cash', 'midtrans_enabled' => true, 'midtrans_server_key' => 'sk-test', 'midtrans_client_key' => 'ck-test',
        ]);
    }

    private function mockCharge(): void
    {
        $this->mock(PaymentGatewayManager::class)
            ->shouldReceive('createTenderPayment')->once()
            ->andReturn(['reference' => 'ref-1', 'payment_url' => 'https://pay.test/1', 'qr_string' => '000201QR']);
    }

    /** A completed return of $qty units of the bill's first line. */
    private function completedReturn(Transaction $bill, int $qty, int $amount): void
    {
        $detail = $bill->details()->firstOrFail();
        $return = SalesReturn::create([
            'code' => 'SR-'.$bill->id.'-'.$qty, 'transaction_id' => $bill->id, 'cashier_id' => $this->cashier->id,
            'status' => 'completed', 'return_type' => 'refund_cash', 'refund_amount' => 0, 'credited_amount' => 0,
            'total_return_amount' => $amount, 'completed_at' => now(),
        ]);
        $return->items()->create([
            'transaction_detail_id' => $detail->id, 'product_id' => $this->product->id, 'qty_sold' => $detail->qty,
            'qty_returned_before' => 0, 'qty_return' => $qty, 'unit_price' => 10000, 'subtotal' => $amount,
            'return_reason' => 'Retur', 'restock_to_inventory' => true,
        ]);
    }

    public function test_settle_with_cash_pays_the_bill_and_leaves_no_receivable(): void
    {
        $bill = $this->guestBill();
        $this->assertSame('unpaid', $bill->payment_status);
        $stock = $this->product->fresh()->stock;

        $this->settle($bill, $this->cash(10000, 15000))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('transactions.print', $bill->invoice));

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame('cash', $bill->payment_method);
        $this->assertSame(15000, (int) $bill->cash);
        $this->assertSame(5000, (int) $bill->change);
        $this->assertTrue($bill->is_pay_later);
        $this->assertDatabaseHas('transaction_tenders', [
            'transaction_id' => $bill->id, 'method' => 'cash', 'amount' => 10000, 'cash_received' => 15000, 'change' => 5000,
        ]);
        $this->assertDatabaseCount('receivables', 0);
        $this->assertSame($stock, $this->product->fresh()->stock, 'stock was taken when the bill was made');
    }

    public function test_settling_a_bill_with_a_payment_history_moves_its_attempt_counter(): void
    {
        $bill = $this->guestBill();
        $bill->update(['payment_status' => 'failed', 'payment_attempt' => 1, 'payment_failure_reason' => 'expired']);

        $this->settle($bill, $this->cash())->assertSessionHasNoErrors();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame(2, $bill->payment_attempt); // a late payment of the old charge is now superseded, never booked twice
        $this->assertNull($bill->payment_failure_reason);
    }

    public function test_a_failed_bill_with_a_failed_gateway_charge_is_paid_from_history(): void
    {
        $bill = $this->guestBill();
        $bill->update(['payment_status' => 'failed', 'payment_method' => 'midtrans', 'payment_attempt' => 1, 'payment_failure_reason' => 'expired']);
        $bill->tenders()->create([
            'method' => 'midtrans', 'amount' => (int) $bill->grand_total,
            'payment_status' => 'failed', 'payment_reference' => 'ref-old',
        ]);

        // The Bayar action History offers for a failed gateway bill: the settle route takes the money and the old charge stays dead.
        $this->settle($bill, $this->cash())->assertSessionHasNoErrors();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame((int) $bill->grand_total, (int) $bill->tenders()->where('payment_status', 'paid')->sum('amount'));
        $this->assertSame(2, $bill->payment_attempt);
    }

    public function test_settle_with_qris_charges_the_bill_and_leaves_it_pending(): void
    {
        $this->enableMidtrans();
        $bill = $this->guestBill();
        $this->mockCharge();

        $this->settle($bill, [['method' => 'qris', 'amount' => 10000]])->assertSessionHasNoErrors();

        $bill->refresh();
        $this->assertSame('pending', $bill->payment_status);
        $this->assertSame('qris', $bill->payment_method);
        $this->assertSame('000201QR', $bill->qr_string);
        $this->assertSame(1, $bill->tenders()->count());
        $this->assertDatabaseCount('receivables', 0);
    }

    public function test_settle_with_a_gateway_creates_the_charge_and_leaves_it_pending(): void
    {
        $this->enableMidtrans();
        $bill = $this->guestBill();
        $this->mockCharge();

        $this->settle($bill, [['method' => 'midtrans', 'amount' => 10000]])->assertSessionHasNoErrors();

        $bill->refresh();
        $this->assertSame('pending', $bill->payment_status);
        $this->assertSame('midtrans', $bill->payment_method);
        $this->assertSame('ref-1', $bill->payment_reference);
        $this->assertSame('https://pay.test/1', $bill->payment_url);
        $this->assertSame('ref-1', $bill->tenders()->sole()->payment_reference);
    }

    public function test_settle_calls_the_gateway_with_no_transaction_open(): void
    {
        $this->enableMidtrans();
        $bill = $this->guestBill();
        $baseline = DB::transactionLevel(); // RefreshDatabase wraps every test in one transaction
        $levelInCall = null;
        $this->mock(PaymentGatewayManager::class)->shouldReceive('createTenderPayment')->once()
            ->andReturnUsing(function () use (&$levelInCall) {
                $levelInCall = DB::transactionLevel();

                return ['reference' => 'ref-1', 'payment_url' => null, 'qr_string' => '000201QR'];
            });

        $this->settle($bill, [['method' => 'qris', 'amount' => 10000]])->assertSessionHasNoErrors();

        $this->assertSame($baseline, $levelInCall);
        $this->assertSame('ref-1', $bill->fresh()->payment_reference);
    }

    public function test_settle_with_a_transfer_waits_for_the_cashier_to_confirm(): void
    {
        $bill = $this->guestBill();
        $account = BankAccount::create([
            'bank_name' => 'BCA', 'account_number' => '123', 'account_name' => 'Toko', 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->settle($bill, [['method' => 'bank_transfer', 'amount' => 10000, 'bank_account_id' => $account->id]])
            ->assertSessionHasNoErrors();

        $bill->refresh();
        $this->assertSame('pending', $bill->payment_status);
        $this->assertSame('bank_transfer', $bill->payment_method);
        $this->assertSame($account->id, $bill->bank_account_id);
        $this->assertDatabaseCount('receivables', 0);
    }

    public function test_settle_requires_transactions_access(): void
    {
        $bill = $this->guestBill();
        $this->cashier->revokePermissionTo('transactions-access');

        $this->settle($bill, $this->cash())->assertForbidden();

        $this->assertSame('unpaid', $bill->fresh()->payment_status);
    }

    public function test_settle_requires_an_open_shift(): void
    {
        $bill = $this->guestBill();
        CashierShift::query()->update(['status' => CashierShift::STATUS_CLOSED, 'closed_at' => now()]);

        $this->settle($bill, $this->cash())
            ->assertRedirect(route('transactions.index'))
            ->assertSessionHas('error', 'Shift kasir belum dibuka.');

        $this->assertSame('unpaid', $bill->fresh()->payment_status);
        $this->assertSame(0, $bill->tenders()->count());
    }

    public function test_settle_refused_from_a_shift_of_another_outlet(): void
    {
        $bill = $this->guestBill();
        $other = Outlet::create(['code' => 'OUT-2', 'name' => 'Outlet 2', 'is_active' => true]);
        $otherWarehouse = Warehouse::create([
            'code' => 'WH-2', 'name' => 'Gudang Outlet 2', 'status' => 'active', 'outlet_id' => $other->id,
        ]);
        $visitor = $this->cashierAt($otherWarehouse);
        $visitor->outlets()->attach([$this->outlet->id, $other->id]); // may see both, but works in outlet 2

        $this->settle($bill, $this->cash(), $visitor)->assertSessionHasErrors('shift');

        $this->assertSame('unpaid', $bill->fresh()->payment_status);
        $this->assertSame(0, $bill->tenders()->count());
    }

    public function test_settle_is_not_found_for_a_cashier_without_access_to_the_bills_outlet(): void
    {
        $bill = $this->guestBill();
        $other = Outlet::create(['code' => 'OUT-3', 'name' => 'Outlet 3', 'is_active' => true]);
        $otherWarehouse = Warehouse::create([
            'code' => 'WH-3', 'name' => 'Gudang Outlet 3', 'status' => 'active', 'outlet_id' => $other->id,
        ]);
        $outsider = $this->cashierAt($otherWarehouse); // transactions-access and an open shift, but only in outlet 3
        $outsider->outlets()->attach($other->id);

        // The controller's outlet scope answers before the service looks at the shift.
        $this->settle($bill, $this->cash(), $outsider)->assertNotFound();

        $this->assertSame('unpaid', $bill->fresh()->payment_status);
        $this->assertSame(0, $bill->tenders()->count());
    }

    public function test_partial_tender_is_refused(): void
    {
        $bill = $this->guestBill();

        $this->settle($bill, $this->cash(4000))->assertSessionHasErrors();

        $this->assertSame('unpaid', $bill->fresh()->payment_status);
        $this->assertSame(0, $bill->tenders()->count());
    }

    public function test_payment_counts_in_the_shift_it_is_taken_in(): void
    {
        $bill = $this->guestBill();
        $firstShift = CashierShift::where('user_id', $this->cashier->id)->sole();
        $this->assertSame($firstShift->id, $bill->cashier_shift_id);
        $second = $this->cashierAt($this->warehouse);
        $secondShift = CashierShift::where('user_id', $second->id)->sole();

        $this->settle($bill, $this->cash(), $second)->assertSessionHasNoErrors();

        $bill->refresh();
        $this->assertSame($secondShift->id, $bill->cashier_shift_id);
        $this->assertSame($second->id, $bill->cashier_id);
        $service = app(CashierShiftService::class);
        $this->assertSame(10000, $service->calculateSummary($secondShift)['cash_sales_total']);
        $this->assertSame(0, $service->calculateSummary($firstShift)['cash_sales_total']);
    }

    public function test_settling_does_not_create_a_second_kitchen_item(): void
    {
        $bill = $this->guestBill();
        $before = KitchenItem::where('transaction_id', $bill->id)->get(['id', 'status', 'accepted_at'])->toArray();
        $this->assertCount(1, $before);

        $this->settle($bill, $this->cash())->assertSessionHasNoErrors();

        $this->assertSame($before, KitchenItem::where('transaction_id', $bill->id)->get(['id', 'status', 'accepted_at'])->toArray());
    }

    public function test_second_settle_is_told_the_bill_is_paid(): void
    {
        $bill = $this->guestBill();
        $second = $this->cashierAt($this->warehouse);

        $this->settle($bill, $this->cash())->assertSessionHasNoErrors();
        $this->settle($bill, $this->cash(), $second)
            ->assertSessionHasErrors(['bill' => 'Tagihan ini sudah dilunasi.']);

        $this->assertSame(1, $bill->tenders()->count());
        $this->assertSame($this->cashier->id, $bill->fresh()->cashier_id);
    }

    public function test_failed_settle_stays_payable_and_can_be_paid_again(): void
    {
        $this->enableMidtrans();
        $bill = $this->guestBill();
        $this->mock(PaymentGatewayManager::class)
            ->shouldReceive('createTenderPayment')->once()->andThrow(new PaymentGatewayException('Gateway down'));

        $before = $bill->only(['cashier_id', 'cashier_shift_id', 'payment_method']);

        $this->settle($bill, [['method' => 'qris', 'amount' => 10000]])->assertSessionHasErrors('tenders');

        $bill->refresh();
        $this->assertSame('unpaid', $bill->payment_status);
        $this->assertSame(0, $bill->tenders()->count());
        $this->assertSame($before, $bill->only(array_keys($before)));
        $this->assertSame(1, $bill->payment_attempt);

        $this->settle($bill, $this->cash())->assertSessionHasNoErrors();
        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->assertSame(1, $bill->tenders()->count());
    }

    public function test_settle_refused_while_a_gateway_attempt_is_pending(): void
    {
        $this->enableMidtrans();
        $bill = $this->guestBill();
        $this->mockCharge();
        $this->settle($bill, [['method' => 'qris', 'amount' => 10000]])->assertSessionHasNoErrors();

        $this->settle($bill, $this->cash())->assertSessionHasErrors('bill');

        $this->assertSame('pending', $bill->fresh()->payment_status);
        $this->assertSame(1, $bill->tenders()->count());
    }

    public function test_an_abandoned_settle_claim_is_replaced_after_two_minutes(): void
    {
        $this->freezeTime();
        $this->enableMidtrans();
        $bill = $this->guestBill();
        // What is committed when the request dies before the gateway's answer: pending, a gateway tender, no reference.
        $bill->update(['payment_method' => 'qris', 'payment_status' => 'pending', 'payment_attempt' => 1]);
        $bill->tenders()->create(['method' => 'qris', 'amount' => 10000, 'payment_status' => 'pending']);

        $this->travel(119)->seconds();
        $this->settle($bill, $this->cash())->assertSessionHasErrors('bill');

        $this->travel(2)->seconds();
        $this->settle($bill, $this->cash())->assertSessionHasNoErrors();

        $bill->refresh();
        $this->assertSame(['paid', 'cash', 2], [$bill->payment_status, $bill->payment_method, $bill->payment_attempt]);
        $this->assertSame(1, $bill->tenders()->count());
    }

    public function test_settle_refused_for_receivable_bill(): void
    {
        $customer = Customer::create(['name' => 'Budi', 'no_telp' => '62811', 'address' => 'Jl. Satu']);
        $customer->forceFill(['may_buy_on_credit' => true])->save();
        Cart::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id, 'qty' => 1, 'price' => $this->product->sell_price,
        ]);
        Sanctum::actingAs($this->cashier, ['*']);
        $this->postJson('/api/v1/pos/checkout', [
            'payment_method' => 'pay_later', 'customer_id' => $customer->id, 'due_date' => now()->addDays(7)->toDateString(),
        ])->assertSuccessful();
        $bill = Transaction::sole();
        $this->assertNotNull($bill->receivable);

        $this->settle($bill, $this->cash())->assertSessionHasErrors('bill');

        $this->assertSame(0, $bill->tenders()->count());
    }

    public function test_settle_refused_for_dine_bill(): void
    {
        $bill = $this->guestBill();
        $table = DiningTable::create(['name' => 'Meja 7', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);
        DineOrder::create([
            'dine_table_id' => $table->id, 'status' => DineOrder::STATUS_ACCEPTED, 'subtotal' => 10000, 'item_count' => 1,
            'transaction_id' => $bill->id,
        ]);

        $this->settle($bill, $this->cash())->assertSessionHasErrors('bill');

        $this->assertSame(0, $bill->tenders()->count());
    }

    public function test_settle_refused_for_a_fully_returned_bill(): void
    {
        $bill = $this->guestBill();
        $this->completedReturn($bill, 1, 10000);

        $this->settle($bill, $this->cash())->assertSessionHasErrors('bill');

        $this->assertSame('unpaid', $bill->fresh()->payment_status);
        $this->assertSame(0, $bill->tenders()->count());
    }

    public function test_bill_with_nothing_left_to_pay_after_a_discount_is_not_listed_and_refused(): void
    {
        $bill = $this->guestBill(qty: 2); // two lines of 10000, a bill-level discount makes the total 12000
        $bill->forceFill(['grand_total' => 12000])->save();
        $this->completedReturn($bill, 1, 10000);
        $this->completedReturn($bill, 0, 5000); // returns priced at line value can exceed what was actually owed

        $this->assertSame(0, $bill->remainingTotal()); // never negative
        $this->assertSame(0, Transaction::openBills()->whereKey($bill->id)->count());
        $this->settle($bill, $this->cash(12000))->assertSessionHasErrors('bill');
        $this->assertSame(0, $bill->tenders()->count());
    }

    public function test_settle_refused_for_a_bill_in_an_unlisted_status(): void
    {
        $bill = $this->guestBill();
        $bill->forceFill(['payment_status' => 'pending_approval'])->save();

        $this->settle($bill, $this->cash())->assertSessionHasErrors('bill');

        $this->assertSame(0, $bill->tenders()->count());
    }

    public function test_settle_after_partial_return_charges_the_remaining_total(): void
    {
        $bill = $this->guestBill(qty: 2);
        $this->assertSame(20000, (int) $bill->grand_total);
        $this->completedReturn($bill, 1, 10000);

        // the old total is no longer what the guest owes
        $this->settle($bill, $this->cash(20000))->assertSessionHasErrors();
        $this->assertSame(0, $bill->tenders()->count());

        $this->settle($bill, $this->cash(10000))->assertSessionHasNoErrors();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame(10000, (int) $bill->tenders()->sole()->amount);
    }
}
