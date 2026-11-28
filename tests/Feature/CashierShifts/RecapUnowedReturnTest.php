<?php

namespace Tests\Feature\CashierShifts;

use App\Models\BankAccount;
use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Services\CashierShiftService;
use App\Services\DineOrderService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\DineIn\Concerns\MakesDineBills;
use Tests\TestCase;

/**
 * #405: the shift recap counts the money a bill received. A return recorded before the bill was paid refunded nothing,
 * so that part was never received (bill 25000, 5000 returned while unpaid, opening cash 100000).
 */
class RecapUnowedReturnTest extends TestCase
{
    use MakesDineBills;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDineBill();
        $this->seed(PermissionSeeder::class);
        $this->cashier->givePermissionTo(['sales-returns-create', 'sales-returns-complete', 'cashier-shifts-access']);
    }

    /** @param  array<int, array<string, mixed>>  $tenders */
    private function payAtCounter(DineOrder $order, array $tenders): void
    {
        app(DineOrderService::class)->pay($order, $this->cashier, $tenders);
    }

    private function assertRecap(int $cash, int $nonCash, int $expectedCash): void
    {
        $recap = app(CashierShiftService::class)->calculateSummary(CashierShift::open()->where('user_id', $this->cashier->id)->sole());

        $this->assertSame(
            ['cash' => $cash, 'non_cash' => $nonCash, 'expected_cash' => $expectedCash],
            ['cash' => $recap['cash_sales_total'], 'non_cash' => $recap['non_cash_sales_total'], 'expected_cash' => $recap['expected_cash']],
        );
    }

    public function test_recap_counts_what_a_returned_dine_bill_received_non_cash(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->returnFromBill($this->bill($order), $this->plain);

        $this->guestPays($order)->assertOk()->assertJsonPath('payment.amount', 20000);
        $this->notify('midtrans', $this->bill($order)->invoice.'-r1', 'paid', 20000)->assertOk();
        $this->assertSame('paid', $this->bill($order)->payment_status);

        $this->assertRecap(cash: 0, nonCash: 20000, expectedCash: 100000);
    }

    public function test_recap_counts_what_a_returned_dine_bill_received_in_cash(): void
    {
        $order = $this->acceptedOrder();
        $this->returnFromBill($this->bill($order), $this->plain);

        $this->payAtCounter($order, [['method' => 'cash', 'amount' => 20000, 'cash_received' => 20000]]);

        $this->assertRecap(cash: 20000, nonCash: 0, expectedCash: 120000);
    }

    public function test_recap_takes_the_returned_amount_off_the_non_cash_part_of_a_split(): void
    {
        $account = BankAccount::create(['bank_name' => 'BCA', 'account_number' => '123', 'account_name' => 'Toko', 'is_active' => true, 'sort_order' => 0]);
        $order = $this->acceptedOrder();
        $this->returnFromBill($this->bill($order), $this->plain);

        $this->payAtCounter($order, [
            ['method' => 'cash', 'amount' => 5000, 'cash_received' => 5000],
            ['method' => 'bank_transfer', 'amount' => 15000, 'bank_account_id' => $account->id],
        ]);

        $this->assertRecap(cash: 5000, nonCash: 15000, expectedCash: 105000);
    }

    public function test_return_after_payment_is_not_subtracted_twice(): void
    {
        $order = $this->acceptedOrder();
        $this->payAtCounter($order, [['method' => 'cash', 'amount' => self::TOTAL, 'cash_received' => self::TOTAL]]);

        $return = $this->returnFromBill($this->bill($order), $this->plain);
        $this->assertSame(5000, (int) $return->refund_amount);

        // the sale stays at 25000 and the refund of 5000 takes it out of the drawer once
        $this->assertRecap(cash: self::TOTAL, nonCash: 0, expectedCash: 120000);
    }

    public function test_unpaid_bill_with_return_adds_nothing(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->returnFromBill($this->bill($order), $this->plain);

        $this->assertRecap(cash: 0, nonCash: 0, expectedCash: 100000);

        // a gateway charge still waiting is not counted, so nothing is taken off it either
        $this->payAtCounter($order, [['method' => 'qris', 'amount' => 20000]]);

        $this->assertRecap(cash: 0, nonCash: 0, expectedCash: 100000);
    }

    public function test_a_return_after_a_counter_transfer_was_asked_is_not_taken_off(): void
    {
        $account = BankAccount::create(['bank_name' => 'BCA', 'account_number' => '123', 'account_name' => 'Toko', 'is_active' => true, 'sort_order' => 0]);
        $order = $this->acceptedOrder();
        $this->payAtCounter($order, [['method' => 'bank_transfer', 'amount' => self::TOTAL, 'bank_account_id' => $account->id]]);

        $this->returnFromBill($this->bill($order), $this->plain);

        // the guest was asked for 25000 and the return refunded nothing: all of it is still to be received
        $this->assertRecap(cash: 0, nonCash: self::TOTAL, expectedCash: 100000);
    }

    public function test_a_split_takes_off_only_what_was_returned_before_it_was_asked(): void
    {
        $account = BankAccount::create(['bank_name' => 'BCA', 'account_number' => '123', 'account_name' => 'Toko', 'is_active' => true, 'sort_order' => 0]);
        $order = $this->acceptedOrder();
        $this->returnFromBill($this->bill($order), $this->plain);
        $this->payAtCounter($order, [
            ['method' => 'cash', 'amount' => 5000, 'cash_received' => 5000],
            ['method' => 'bank_transfer', 'amount' => 15000, 'bank_account_id' => $account->id],
        ]);

        $this->returnFromBill($this->bill($order), $this->flagged);

        $this->assertRecap(cash: 5000, nonCash: 15000, expectedCash: 105000);
    }

    public function test_a_return_while_a_counter_gateway_charge_waits_is_not_taken_off_once_paid(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->payAtCounter($order, [['method' => 'qris', 'amount' => self::TOTAL]]);
        $this->returnFromBill($this->bill($order), $this->plain);

        $this->notify('midtrans', $this->bill($order)->invoice.'-r1', 'paid')->assertOk();
        $this->assertSame('paid', $this->bill($order)->payment_status);

        $this->assertRecap(cash: 0, nonCash: self::TOTAL, expectedCash: 100000);
    }

    /** Only an open bill is asked for less after a return. A plain POS sale was asked for its grand total at checkout. */
    public function test_only_a_bill_asked_after_the_return_is_reduced(): void
    {
        $shift = app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);
        $this->saleWithUnrefundedReturn($shift, ['is_pay_later' => true, 'payment_method' => 'cash', 'payment_status' => 'paid']);
        $this->saleWithUnrefundedReturn($shift, ['is_pay_later' => false, 'payment_method' => 'bank_transfer', 'payment_status' => 'pending']);

        $this->assertRecap(cash: 20000, nonCash: 25000, expectedCash: 120000);
    }

    /** Return amounts are line prices, before the bill's discount: a sale never takes off more than it added. */
    public function test_returns_never_take_off_more_than_the_sale_added(): void
    {
        $shift = app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);
        // 2 x 12500 with a discount of 5000, both lines returned one by one while unpaid
        $bill = $this->saleWithUnrefundedReturn($shift, [
            'is_pay_later' => true, 'payment_method' => 'pay_later', 'payment_status' => 'unpaid', 'grand_total' => 20000,
        ], returned: 12500);
        SalesReturn::create([
            'code' => 'SR-SECOND', 'transaction_id' => $bill->id, 'cashier_id' => $this->cashier->id, 'status' => 'completed',
            'return_type' => 'refund_cash', 'refund_amount' => 0, 'credited_amount' => 0, 'total_return_amount' => 12500, 'completed_at' => now(),
        ]);
        Transaction::create([
            'cashier_id' => $this->cashier->id, 'cashier_shift_id' => $shift->id, 'warehouse_id' => $this->warehouse->id,
            'invoice' => 'TRX-OTHER', 'cash' => 0, 'change' => 0, 'discount' => 0, 'grand_total' => 30000,
            'payment_method' => 'bank_transfer', 'payment_status' => 'paid',
        ]);

        $this->assertRecap(cash: 0, nonCash: 30000, expectedCash: 100000);
    }

    /** A forced close books an unpaid bill as a shortfall and leaves it out of the recap: nothing of it comes off again. */
    public function test_a_forced_close_does_not_take_a_booked_bills_return_off_twice(): void
    {
        $account = BankAccount::create(['bank_name' => 'BCA', 'account_number' => '123', 'account_name' => 'Toko', 'is_active' => true, 'sort_order' => 0]);
        // a dine bill: 5000 returned, then the owed 20000 asked by a counter transfer that is still waiting
        $order = $this->acceptedOrder();
        $this->returnFromBill($this->bill($order), $this->plain);
        $this->payAtCounter($order, [['method' => 'bank_transfer', 'amount' => 20000, 'bank_account_id' => $account->id]]);
        $shift = CashierShift::findOrFail($this->bill($order)->cashier_shift_id);
        $sale = [
            'cashier_id' => $this->cashier->id, 'cashier_shift_id' => $shift->id, 'warehouse_id' => $this->warehouse->id,
            'cash' => 0, 'change' => 0, 'discount' => 0,
        ];
        // a guest pay-later bill without tenders, one of its two units returned
        $payLater = Transaction::create([
            ...$sale, 'invoice' => 'TRX-LATER', 'grand_total' => 10000,
            'is_pay_later' => true, 'payment_method' => 'pay_later', 'payment_status' => 'unpaid',
        ]);
        $detail = $payLater->details()->create(['product_id' => $this->plain->id, 'qty' => 2, 'price' => 10000]);
        $return = SalesReturn::create([
            'code' => 'SR-LATER', 'transaction_id' => $payLater->id, 'cashier_id' => $this->cashier->id, 'status' => 'completed',
            'return_type' => 'refund_cash', 'refund_amount' => 0, 'credited_amount' => 0, 'total_return_amount' => 5000, 'completed_at' => now(),
        ]);
        $return->items()->create([
            'transaction_detail_id' => $detail->id, 'product_id' => $this->plain->id, 'qty_sold' => 2, 'qty_returned_before' => 0,
            'qty_return' => 1, 'unit_price' => 5000, 'subtotal' => 5000, 'return_reason' => 'Batal', 'restock_to_inventory' => false,
        ]);
        // and a sale that did take its money
        Transaction::create([...$sale, 'invoice' => 'TRX-OTHER', 'grand_total' => 30000, 'payment_method' => 'bank_transfer', 'payment_status' => 'paid']);

        $closed = $this->closeShiftOf($this->bill($order));

        $this->assertSame(25000, (int) $closed->unpaid_bills_total); // 20000 + 5000 booked as the shortfall
        $this->assertSame(30000, (int) $closed->non_cash_sales_total);
    }

    /** The screens follow the recap: a counter transfer was asked in full, whatever is returned afterwards. */
    public function test_a_counter_transfer_is_still_asked_in_full_after_a_later_return(): void
    {
        $account = BankAccount::create(['bank_name' => 'BCA', 'account_number' => '123', 'account_name' => 'Toko', 'is_active' => true, 'sort_order' => 0]);
        $order = $this->acceptedOrder();
        $this->payAtCounter($order, [['method' => 'bank_transfer', 'amount' => self::TOTAL, 'bank_account_id' => $account->id]]);

        $this->returnFromBill($this->bill($order), $this->plain);

        $this->assertSame(self::TOTAL, $this->bill($order)->amountDue());
        $this->assertNull($this->bill($order)->reducedAmountDue());
    }

    /** @param  array<string, mixed>  $sale */
    private function saleWithUnrefundedReturn(CashierShift $shift, array $sale, int $returned = 5000): Transaction
    {
        $transaction = Transaction::create([
            'cashier_id' => $this->cashier->id, 'cashier_shift_id' => $shift->id, 'warehouse_id' => $this->warehouse->id,
            'invoice' => 'TRX-'.$sale['payment_method'], 'cash' => 0, 'change' => 0, 'discount' => 0, 'grand_total' => self::TOTAL, ...$sale,
        ]);
        SalesReturn::create([
            'code' => 'SR-'.$sale['payment_method'], 'transaction_id' => $transaction->id, 'cashier_id' => $this->cashier->id,
            'status' => 'completed', 'return_type' => 'refund_cash', 'refund_amount' => 0, 'credited_amount' => 0,
            'total_return_amount' => $returned, 'completed_at' => now(),
        ]);

        return $transaction;
    }

    public function test_closed_shift_recap_is_not_recomputed(): void
    {
        $order = $this->acceptedOrder();
        $this->returnFromBill($this->bill($order), $this->plain);
        $this->payAtCounter($order, [['method' => 'cash', 'amount' => 20000, 'cash_received' => 20000]]);
        $shift = $this->closeShiftOf($this->bill($order));

        // a shift closed before #405 stored the grand total, and keeps showing it
        $shift->forceFill(['cash_sales_total' => self::TOTAL, 'expected_cash' => 125000])->save();

        $this->actingAs($this->cashier)->get(route('cashier-shifts.show', $shift))
            ->assertInertia(fn (Assert $page) => $page
                ->where('cashierShift.cash_sales_total', self::TOTAL)
                ->where('cashierShift.expected_cash', 125000));
        $this->assertSame(self::TOTAL, (int) $shift->fresh()->cash_sales_total);
    }
}
