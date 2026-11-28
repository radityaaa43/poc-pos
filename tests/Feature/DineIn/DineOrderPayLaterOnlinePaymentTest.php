<?php

namespace Tests\Feature\DineIn;

use App\Models\AuditLog;
use App\Models\DineOrder;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Feature\DineIn\Concerns\MakesDineBills;
use Tests\TestCase;

/**
 * REQ-005 AC-05 (#328): a table-QR pay-later bill, already in the kitchen while unpaid, is paid online from the status page
 * through the same guest charge and webhook as any dine bill, and nothing reaches the kitchen a second time.
 * The lock order of that webhook is proven in PaymentWebhookLockTest.
 */
class DineOrderPayLaterOnlinePaymentTest extends TestCase
{
    use MakesDineBills;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDineBill();
    }

    private function payLaterOrder(): DineOrder
    {
        return $this->acceptedOrder(DineOrder::PAY_LATER);
    }

    private function check(DineOrder $order): array
    {
        return $this->getJson(route('dine-order.status-check', $order->access_token))->assertOk()->json();
    }

    /** What paying must never change: the bill's kitchen rows, ids and times included. */
    private function kitchenRows(Transaction $bill): array
    {
        return $bill->kitchenItems()->orderBy('id')->get(['id', 'transaction_detail_id', 'qty', 'status', 'accepted_at'])->toArray();
    }

    /** A completed sales return of $amount on the bill. */
    private function returned(Transaction $bill, int $amount): void
    {
        SalesReturn::create([
            'code' => 'SR-'.Str::upper(Str::random(6)), 'transaction_id' => $bill->id, 'cashier_id' => $this->cashier->id, 'status' => 'completed',
            'return_type' => 'refund_cash', 'refund_amount' => $amount, 'credited_amount' => 0, 'total_return_amount' => $amount,
            'completed_at' => now(),
        ]);
    }

    private function inOpenBills(Transaction $bill): bool
    {
        return Transaction::openBills()->whereKey($bill->id)->exists();
    }

    /** Staff screens are scoped to an outlet: puts the warehouse in one and returns a user of it holding $permission. */
    private function staffWith(string $permission): User
    {
        $outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true]);
        $this->warehouse->update(['outlet_id' => $outlet->id]);
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);

        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->givePermissionTo($permission);
        $user->outlets()->attach($outlet->id, ['is_default' => true]);

        return $user;
    }

    // ---- what the status page is told ----------------------------------------------------------------

    public function test_pay_later_bill_can_be_paid_in_every_in_progress_status(): void
    {
        $this->useGateway('midtrans');

        foreach (DineOrder::IN_PROGRESS as $status) {
            $order = $this->payLaterOrder();
            $order->update(['status' => $status]);

            $payment = $this->check($order)['payment'];
            $this->assertSame(['can_pay', true], [$payment['state'], $payment['pay_later']], $status);
            $this->guestPays($order)->assertOk()->assertJsonPath('payment.state', 'pending')->assertJsonPath('payment.pay_later', true);
        }
    }

    public function test_status_page_carries_pay_later_flag(): void
    {
        $this->useGateway('midtrans');
        $later = $this->payLaterOrder();
        $counter = $this->acceptedOrder();

        $this->get(route('dine-order.status', $later->access_token))
            ->assertInertia(fn (Assert $page) => $page->where('payment.state', 'can_pay')->where('payment.pay_later', true));
        $this->get(route('dine-order.status', $counter->access_token))
            ->assertInertia(fn (Assert $page) => $page->where('payment.state', 'can_pay')->where('payment.pay_later', false));
    }

    public function test_no_gateway_pay_later_bill_shows_counter(): void
    {
        Http::fake();
        $order = $this->payLaterOrder();

        $payment = $this->check($order)['payment'];
        $this->assertSame(['counter', true], [$payment['state'], $payment['pay_later']]);
        $this->guestPays($order)->assertStatus(422)->assertJsonPath('errors.payment.0', 'no_gateway');
        Http::assertNothingSent();
        $this->assertSame('unpaid', $this->bill($order)->payment_status);
    }

    public function test_guest_pay_route_never_charges_a_pos_pay_later_sale(): void
    {
        $this->useGateway('midtrans');
        $sale = Transaction::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => 'TRX-POS-LATER', 'cash' => 0, 'change' => 0,
            'discount' => 0, 'shipping_cost' => 0, 'grand_total' => self::TOTAL, 'payment_method' => 'pay_later', 'payment_status' => 'unpaid',
            'is_pay_later' => true,
        ]);

        // The route starts from a dine order's token; a POS sale has no dine order.
        $this->postJson(route('dine-order.pay', $sale->access_token))->assertNotFound();

        Http::assertNothingSent();
        $this->assertSame(['unpaid', 0], [$sale->fresh()->payment_status, (int) $sale->fresh()->payment_attempt]);
    }

    // ---- the charge: same amount and retry rules ------------------------------------------------------

    #[DataProvider('gateways')]
    public function test_guest_charge_on_a_pay_later_bill_uses_the_remaining_total_and_keeps_the_shift(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->payLaterOrder();
        $bill = $this->bill($order);

        $this->postJson(route('dine-order.pay', $order->access_token), ['amount' => 1, 'grand_total' => 1])
            ->assertOk()
            ->assertJsonPath('payment.amount', self::TOTAL)
            ->assertJsonPath('payment.attempt', 1);

        Http::assertSent(fn (Request $request) => $gateway === 'midtrans'
            ? $request['transaction_details']['gross_amount'] === self::TOTAL && $request['transaction_details']['order_id'] === "{$bill->invoice}-r1"
            : $request['amount'] === self::TOTAL && $request['external_id'] === "{$bill->invoice}-r1");
        $charged = $bill->fresh();
        $this->assertSame([$bill->cashier_id, $bill->cashier_shift_id], [$charged->cashier_id, $charged->cashier_shift_id]);
        $this->assertTrue($charged->is_pay_later);
        $this->assertTrue(AuditLog::where('event', 'dine_order.online_payment_started')->sole()->meta['pay_later']);
    }

    public function test_partial_return_charges_the_remaining_total(): void
    {
        $this->useGateway('midtrans');
        $order = $this->payLaterOrder();
        $this->returned($this->bill($order), 5000);

        $this->guestPays($order)->assertOk()->assertJsonPath('payment.amount', 20000);

        Http::assertSent(fn (Request $request) => $request['transaction_details']['gross_amount'] === 20000);
    }

    public function test_late_payment_of_the_remaining_total_is_booked(): void
    {
        $this->useGateway('midtrans');
        $order = $this->payLaterOrder();
        $bill = $this->bill($order);
        $this->returned($bill, 5000);
        $this->guestPays($order)->assertOk();
        $this->guestPays($order)->assertOk();

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid', amount: 20000)->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNull($bill->late_superseded_payment_at);
    }

    public function test_fully_returned_pay_later_bill_cannot_be_paid_online(): void
    {
        $this->useGateway('midtrans');
        $order = $this->payLaterOrder();
        $this->returned($this->bill($order), self::TOTAL);

        $this->assertNull($this->check($order)['payment']);
        $this->guestPays($order)->assertStatus(422)->assertJsonPath('errors.payment.0', 'not_payable');
        Http::assertNothingSent();
        $this->assertSame(['unpaid', 0], [$this->bill($order)->payment_status, $this->bill($order)->payment_attempt]);
    }

    /** Known limit (REQ-005 decision 8): a return made while the charge is open does not change it; the difference is refunded by hand. */
    public function test_charge_amount_is_fixed_at_claim(): void
    {
        $this->useGateway('midtrans');
        $order = $this->payLaterOrder();
        $bill = $this->bill($order);
        $this->guestPays($order)->assertOk();
        $this->returned($bill, 5000);

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $this->assertSame('paid', $bill->fresh()->payment_status);
        Http::assertSentCount(1);
    }

    /** @return array<string, array<int, string>> */
    public static function unsuccessful(): array
    {
        return ['failed' => ['failed'], 'expired' => ['expired']];
    }

    #[DataProvider('unsuccessful')]
    public function test_failed_attempt_on_a_pay_later_bill_keeps_kitchen_and_open_bill(string $result): void
    {
        $this->useGateway('midtrans');
        $order = $this->payLaterOrder();
        $bill = $this->bill($order);
        $rows = $this->kitchenRows($bill);
        $this->guestPays($order)->assertOk();

        $this->notify('midtrans', "{$bill->invoice}-r1", $result)->assertOk();

        $payment = $this->check($order)['payment'];
        $this->assertSame([$result, true, self::TOTAL], [$payment['state'], $payment['pay_later'], $payment['amount']]);
        $this->assertTrue($this->inOpenBills($bill));
        $this->assertSame($rows, $this->kitchenRows($bill));

        // Coba lagi: a new attempt for the same amount. The expiry command never touches it, however old: the food is made.
        $this->guestPays($order)->assertOk()->assertJsonPath('payment.attempt', 2)->assertJsonPath('payment.amount', self::TOTAL);
        $this->travel(2)->days();
        $this->artisan('transactions:expire')->assertSuccessful();
        $this->assertSame('pending', $bill->fresh()->payment_status);

        $this->notify('midtrans', "{$bill->invoice}-r2", 'paid')->assertOk();
        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->assertSame($rows, $this->kitchenRows($bill));
    }

    // ---- no second kitchen intake ---------------------------------------------------------------------

    #[DataProvider('gateways')]
    public function test_paying_a_pay_later_bill_online_adds_no_kitchen_item(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->payLaterOrder();
        $bill = $this->bill($order);
        $rows = $this->kitchenRows($bill);
        $this->assertCount(1, $rows); // in the kitchen since accept, unpaid: the check below is not vacuous
        $this->travel(5)->minutes(); // a second intake would carry another accepted_at

        $this->guestPays($order)->assertOk();
        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();

        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->assertSame($rows, $this->kitchenRows($bill));
        $this->assertSame(1, KitchenItem::count());
        $this->assertSame(0, $bill->receivable()->count());
    }

    #[DataProvider('gateways')]
    public function test_redelivered_settlement_of_a_pay_later_bill_is_ignored(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->payLaterOrder();
        $bill = $this->bill($order);
        $rows = $this->kitchenRows($bill);
        $this->guestPays($order)->assertOk();
        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();

        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->assertNull($bill->fresh()->late_superseded_payment_at);
        $this->assertSame(0, AuditLog::whereIn('event', ['payment.late_superseded', 'payment.late_after_failed'])->count());
        $this->assertSame($rows, $this->kitchenRows($bill));
    }

    #[DataProvider('gateways')]
    public function test_late_older_attempts_of_a_pay_later_bill_add_no_kitchen_item(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->payLaterOrder();
        $bill = $this->bill($order);
        $rows = $this->kitchenRows($bill);
        $this->travel(5)->minutes();
        $this->guestPays($order)->assertOk();
        $this->guestPays($order)->assertOk();

        // The older attempt is booked (nothing else holds the bill), then the newer one is a second payment: flagged.
        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();
        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->notify($gateway, "{$bill->invoice}-r2", 'paid')->assertOk();

        $this->assertNotNull($bill->fresh()->late_superseded_payment_at);
        $this->assertSame($rows, $this->kitchenRows($bill));
    }

    #[DataProvider('gateways')]
    public function test_takeover_of_a_pay_later_bill_keeps_the_kitchen_items(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->payLaterOrder();
        $bill = $this->bill($order);
        $rows = $this->kitchenRows($bill);
        $this->travel(5)->minutes();
        $this->guestPays($order)->assertOk();

        $this->cashierPays($order);
        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame(['paid', 'cash'], [$bill->payment_status, $bill->payment_method]);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertSame($rows, $this->kitchenRows($bill));
    }

    // ---- the bill is settled everywhere ---------------------------------------------------------------

    public function test_paid_pay_later_bill_leaves_open_bills(): void
    {
        $this->useGateway('midtrans');
        $order = $this->payLaterOrder();
        $bill = $this->bill($order);
        $this->guestPays($order)->assertOk();
        $this->assertTrue($this->inOpenBills($bill));

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->assertFalse($this->inOpenBills($bill));
    }

    public function test_pay_later_bill_with_a_guest_charge_is_marked_awaiting_online(): void
    {
        $cashier = $this->staffWith('transactions-access');
        $this->useGateway('midtrans');
        $order = $this->payLaterOrder();
        $this->guestPays($order)->assertOk();

        $rows = $this->actingAs($cashier)->getJson(route('transactions.open-bills'))->assertOk()->json('openBills');

        $this->assertTrue(collect($rows)->firstWhere('id', $order->transaction_id)['awaiting_online']);
    }

    public function test_kitchen_unpaid_mark_clears_after_online_payment(): void
    {
        $cook = $this->staffWith('dine-orders-prepare');
        $this->useGateway('midtrans');
        $order = $this->payLaterOrder();
        $bill = $this->bill($order);
        $display = fn (bool $unpaid) => $this->actingAs($cook)->get(route('kitchen.index'))
            ->assertInertia(fn (Assert $page) => $page->has('orders', 1)->has('orders.0.items', 1)->where('orders.0.unpaid', $unpaid));
        $display(true);

        $this->guestPays($order)->assertOk();
        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $display(false);
    }

    public function test_served_pay_later_order_completes_when_paid_online(): void
    {
        $this->useGateway('midtrans');
        $order = $this->payLaterOrder();
        $order->update(['status' => DineOrder::STATUS_SERVED, 'served_at' => now()]);
        $bill = $this->bill($order);
        $this->guestPays($order)->assertOk();

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $this->assertSame(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
        // One poll tells the guest both.
        $answer = $this->check($order);
        $this->assertSame([DineOrder::STATUS_COMPLETED, 'paid'], [$answer['order']['status'], $answer['payment']['state']]);
    }

    public function test_all_unflagged_pay_later_order_completes_when_paid_online(): void
    {
        $this->useGateway('midtrans');
        $this->flagged->update(['needs_preparation' => false]);
        $order = $this->payLaterOrder();
        $this->assertSame(DineOrder::STATUS_SERVED, $order->status); // nothing to make: served at accept
        $bill = $this->bill($order);
        $this->guestPays($order)->assertOk();

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $this->assertSame(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
        $this->assertSame(0, KitchenItem::count());
    }

    // ---- the owning shift -----------------------------------------------------------------------------

    #[DataProvider('gateways')]
    public function test_a_pay_later_bill_paid_after_its_shift_closed_is_flagged_and_the_recap_stays(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->payLaterOrder();
        $bill = $this->bill($order);
        // Closed while the bill was never charged: its method is open_bill, which the recap leaves out.
        $closed = $this->closeShiftOf($bill);
        $this->guestPays($order)->assertOk();

        $this->notify($gateway, "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame(['paid', $closed->id], [$bill->payment_status, $bill->cashier_shift_id]);
        $this->assertNotNull($bill->paid_after_close_at);
        $this->assertSame(0, (int) $closed->fresh()->non_cash_sales_total);
    }

    public function test_a_pay_later_bill_paid_while_its_shift_is_open_counts_in_the_recap(): void
    {
        $this->useGateway('midtrans');
        $order = $this->payLaterOrder();
        $bill = $this->bill($order);
        $this->guestPays($order)->assertOk();

        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $shift = $this->closeShiftOf($bill);
        $this->assertSame(self::TOTAL, app(CashierShiftService::class)->calculateSummary($shift)['non_cash_sales_total']);
        $this->assertSame(self::TOTAL, (int) $shift->non_cash_sales_total);
        $this->assertNull($bill->fresh()->paid_after_close_at);
    }
}
