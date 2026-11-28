<?php

namespace Tests\Feature\Transactions;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\Transaction;
use App\Services\CashierShiftService;
use App\Services\DineOrderService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\DineIn\Concerns\MakesDineBills;
use Tests\TestCase;

/**
 * #444: a return completed while a payment waits lowers what the bill owes. A payment of the old amount pays the bill,
 * and the excess is flagged for a manual refund (bill 25000, 5000 returned while waiting).
 */
class OverpaidAfterReturnTest extends TestCase
{
    use MakesDineBills;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDineBill();
        $this->seed(PermissionSeeder::class);
        $this->cashier->givePermissionTo(['sales-returns-create', 'sales-returns-complete', 'transactions-confirm-payment']);
    }

    #[DataProvider('gateways')]
    public function test_a_guest_charge_paid_at_the_old_total_after_a_return_is_flagged(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk()->assertJsonPath('payment.amount', self::TOTAL);
        $this->returnFromBill($this->bill($order), $this->plain);
        $orderId = $this->bill($order)->invoice.'-r1';

        $this->notify($gateway, $orderId, 'paid', self::TOTAL)->assertOk();

        $bill = $this->bill($order);
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertSame(5000, (int) $bill->overpaid_amount);
        $meta = AuditLog::where('event', 'payment.overpaid_after_return')->sole()->meta;
        $this->assertSame(
            [self::TOTAL, 20000, 5000, 'gateway', $orderId],
            [$meta['settled'], $meta['owed'], $meta['excess'], $meta['source'], $meta['order_id']],
        );
    }

    public function test_the_recap_counts_only_what_a_guest_paid_bill_owed(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();
        $this->returnFromBill($this->bill($order), $this->plain);

        $this->notify('midtrans', $this->bill($order)->invoice.'-r1', 'paid')->assertOk();

        $this->assertFlagged($this->bill($order), 5000);
        $this->assertNonCash($order, 20000);
    }

    #[DataProvider('gateways')]
    public function test_a_counter_gateway_tender_paid_after_a_return_is_flagged(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->acceptedOrder();
        $this->payAtCounter($order, [['method' => 'qris', 'amount' => self::TOTAL]]);
        $this->returnFromBill($this->bill($order), $this->plain);

        $this->notify($gateway, $this->bill($order)->invoice.'-r1', 'paid')->assertOk();

        $this->assertFlagged($this->bill($order), 5000);
        $this->assertNonCash($order, 20000);
    }

    public function test_a_counter_transfer_confirmed_after_a_return_is_flagged(): void
    {
        $order = $this->acceptedOrder();
        $this->payAtCounter($order, [['method' => 'bank_transfer', 'amount' => self::TOTAL, 'bank_account_id' => $this->bankAccount()->id]]);
        $this->returnFromBill($this->bill($order), $this->plain);

        $this->confirmOnTheWeb($this->bill($order));

        $this->assertFlagged($this->bill($order), 5000);
        $this->assertSame('transfer', AuditLog::where('event', 'payment.overpaid_after_return')->sole()->meta['source']);
        $this->assertNonCash($order, 20000);
    }

    public function test_a_transfer_confirmed_over_the_api_after_a_return_is_flagged(): void
    {
        $order = $this->acceptedOrder();
        $this->payAtCounter($order, [['method' => 'bank_transfer', 'amount' => self::TOTAL, 'bank_account_id' => $this->bankAccount()->id]]);
        $this->returnFromBill($this->bill($order), $this->plain);
        Sanctum::actingAs($this->cashier, ['*']);
        cache()->put('api_step_up:user:'.$this->cashier->id, time());

        $this->postJson('/api/v1/transactions/'.$order->transaction_id.'/confirm-payment')
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.overpaid_amount', 5000);

        $this->assertFlagged($this->bill($order), 5000);
    }

    /** The cash part is already in the drawer, so the excess comes off the non-cash part. */
    public function test_a_split_confirmed_after_a_return_counts_what_the_bill_owed(): void
    {
        $order = $this->acceptedOrder();
        $this->payAtCounter($order, [
            ['method' => 'cash', 'amount' => 5000, 'cash_received' => 5000],
            ['method' => 'bank_transfer', 'amount' => 20000, 'bank_account_id' => $this->bankAccount()->id],
        ]);
        $this->returnFromBill($this->bill($order), $this->plain);

        $this->confirmOnTheWeb($this->bill($order));

        $this->assertFlagged($this->bill($order), 5000);
        $recap = $this->recap($order);
        $this->assertSame([5000, 15000], [$recap['cash_sales_total'], $recap['non_cash_sales_total']]);
    }

    /** 10000 too much, only 5000 of it by transfer: the other 5000 is cash in the drawer. */
    public function test_a_split_excess_above_its_transfer_comes_off_the_cash(): void
    {
        $order = $this->acceptedOrder();
        $this->payAtCounter($order, [
            ['method' => 'cash', 'amount' => 20000, 'cash_received' => 20000],
            ['method' => 'bank_transfer', 'amount' => 5000, 'bank_account_id' => $this->bankAccount()->id],
        ]);
        $this->returnFromBill($this->bill($order), $this->flagged);

        $this->confirmOnTheWeb($this->bill($order));

        $this->assertFlagged($this->bill($order), 10000);
        $recap = $this->recap($order);
        $this->assertSame([15000, 0, 115000], [$recap['cash_sales_total'], $recap['non_cash_sales_total'], $recap['expected_cash']]);
    }

    /** A checkout transfer is no open bill: the recap counts its total, so the excess has to come off it. */
    public function test_a_checkout_transfer_confirmed_after_a_return_is_flagged_and_not_counted_in_full(): void
    {
        $sale = $this->posSale('bank_transfer', 'pending');
        $this->returnFromBill($sale, $this->plain);

        $this->confirmOnTheWeb($sale);

        $this->assertFlagged($sale->fresh(), 5000);
        $this->assertSaleShiftNonCash($sale, 20000);
    }

    /** A retry writes no tenders and asks what is owed then: only what a later return takes off is too much. */
    public function test_a_retried_transfer_is_flagged_only_for_a_return_made_after_the_retry(): void
    {
        $sale = $this->posSale('qris', 'failed');
        $this->returnFromBill($sale, $this->plain); // before the retry: it asks 20000
        $this->cashier->givePermissionTo('transactions-access');
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'bank_transfer', 'bank_account_id' => $this->bankAccount()->id])
            ->assertSessionHasNoErrors();
        $this->returnFromBill($sale, $this->flagged); // while it waits: 10000 less

        $this->confirmOnTheWeb($sale);

        $this->assertFlagged($sale->fresh(), 10000);
        $meta = AuditLog::where('event', 'payment.overpaid_after_return')->sole()->meta;
        $this->assertSame([20000, 10000], [$meta['settled'], $meta['owed']]);
        $this->assertSaleShiftNonCash($sale, 10000);
    }

    public function test_a_retried_transfer_confirmed_for_what_it_asked_is_not_flagged(): void
    {
        $sale = $this->posSale('qris', 'failed');
        $this->returnFromBill($sale, $this->plain);
        $this->cashier->givePermissionTo('transactions-access');
        $this->actingAs($this->cashier)
            ->post(route('transactions.retry-payment', $sale), ['payment_method' => 'bank_transfer', 'bank_account_id' => $this->bankAccount()->id])
            ->assertSessionHasNoErrors();

        $this->confirmOnTheWeb($sale);

        $this->assertNotFlagged($sale->fresh());
        $this->assertSaleShiftNonCash($sale, 20000);
    }

    public function test_paying_what_is_owed_after_a_return_is_not_flagged(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->returnFromBill($this->bill($order), $this->plain);
        $this->guestPays($order)->assertOk()->assertJsonPath('payment.amount', 20000);

        $this->notify('midtrans', $this->bill($order)->invoice.'-r1', 'paid', 20000)->assertOk();

        $this->assertNotFlagged($this->bill($order));
        $this->assertNonCash($order, 20000);
    }

    public function test_a_notification_without_an_amount_is_not_flagged(): void
    {
        $this->useGateway('xendit');
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();
        $this->returnFromBill($this->bill($order), $this->plain);

        $this->withHeader('X-CALLBACK-TOKEN', 'callback-token')->postJson(route('webhooks.xendit'), [
            'external_id' => $this->bill($order)->invoice.'-r1', 'status' => 'PAID', 'id' => 'xnd-no-amount',
        ])->assertOk();

        $this->assertNotFlagged($this->bill($order));
    }

    #[DataProvider('gateways')]
    public function test_a_redelivered_notification_flags_once(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();
        $this->returnFromBill($this->bill($order), $this->plain);
        $orderId = $this->bill($order)->invoice.'-r1';

        $this->notify($gateway, $orderId, 'paid')->assertOk();
        $flaggedAt = $this->bill($order)->late_superseded_payment_at;
        $this->travel(1)->minutes();
        $this->notify($gateway, $orderId, 'paid')->assertOk();

        $this->assertFlagged($this->bill($order), 5000);
        $this->assertEquals($flaggedAt, $this->bill($order)->late_superseded_payment_at);
        $this->assertSame(0, AuditLog::where('event', 'payment.late_superseded')->count());
    }

    /** A return that handed money back no longer counts against what the bill owes: nothing is refunded twice. */
    public function test_a_return_that_already_refunded_is_not_flagged_again(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();
        $this->returnFromBill($this->bill($order), $this->plain)->update(['refund_amount' => 5000]);

        $this->notify('midtrans', $this->bill($order)->invoice.'-r1', 'paid')->assertOk();

        $this->assertNotFlagged($this->bill($order));
    }

    public function test_a_payment_after_the_shift_closed_is_flagged_and_the_closed_recap_stays(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();
        $this->returnFromBill($this->bill($order), $this->plain);
        $closed = $this->closeShiftOf($this->bill($order));
        $recap = ['cash_sales_total', 'non_cash_sales_total', 'expected_cash', 'cash_difference', 'unpaid_bills_total'];
        $snapshot = $closed->only($recap);

        $this->notify('midtrans', $this->bill($order)->invoice.'-r1', 'paid')->assertOk();

        $this->assertFlagged($this->bill($order), 5000);
        $this->assertNotNull($this->bill($order)->paid_after_close_at);
        $this->assertSame($snapshot, CashierShift::findOrFail($closed->id)->only($recap));
    }

    /** @param  array<int, array<string, mixed>>  $tenders */
    private function payAtCounter(DineOrder $order, array $tenders): void
    {
        app(DineOrderService::class)->pay($order, $this->cashier, $tenders);
    }

    private function bankAccount(): BankAccount
    {
        return BankAccount::create(['bank_name' => 'BCA', 'account_number' => '123', 'account_name' => 'Toko', 'is_active' => true, 'sort_order' => 0]);
    }

    /** A POS sale of 25000 (1 x Air + 2 x Rujak) in the cashier's open shift: no dine order, no tenders. */
    private function posSale(string $method, string $status): Transaction
    {
        $shift = app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);
        $sale = Transaction::create([
            'cashier_id' => $this->cashier->id, 'cashier_shift_id' => $shift->id, 'warehouse_id' => $this->warehouse->id,
            'invoice' => 'TRX-POS', 'cash' => 0, 'change' => 0, 'discount' => 0, 'grand_total' => self::TOTAL,
            'payment_method' => $method, 'payment_status' => $status,
            'bank_account_id' => $method === 'bank_transfer' ? $this->bankAccount()->id : null,
        ]);
        $sale->details()->create(['product_id' => $this->plain->id, 'qty' => 1, 'price' => 5000]);
        $sale->details()->create(['product_id' => $this->flagged->id, 'qty' => 2, 'price' => 20000]);

        return $sale;
    }

    private function assertSaleShiftNonCash(Transaction $sale, int $expected): void
    {
        $shift = CashierShift::findOrFail($sale->cashier_shift_id);

        $this->assertSame($expected, app(CashierShiftService::class)->calculateSummary($shift)['non_cash_sales_total']);
    }

    private function confirmOnTheWeb(Transaction $sale): void
    {
        $this->actingAs($this->cashier)->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('transactions.confirm-payment', $sale))
            ->assertSessionHas('success');
        $this->assertSame('paid', $sale->fresh()->payment_status);
    }

    private function assertFlagged(Transaction $bill, int $excess): void
    {
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame($excess, $bill->overpaid_amount);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertSame(1, AuditLog::where('event', 'payment.overpaid_after_return')->count());
    }

    private function assertNotFlagged(Transaction $bill): void
    {
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame(0, $bill->overpaid_amount);
        $this->assertNull($bill->late_superseded_payment_at);
        $this->assertSame(0, AuditLog::where('event', 'payment.overpaid_after_return')->count());
    }

    /** @return array<string, int> the live recap of the shift the bill is on */
    private function recap(DineOrder $order): array
    {
        return app(CashierShiftService::class)->calculateSummary(CashierShift::findOrFail($this->bill($order)->cashier_shift_id));
    }

    private function assertNonCash(DineOrder $order, int $expected): void
    {
        $this->assertSame($expected, $this->recap($order)['non_cash_sales_total']);
    }
}
