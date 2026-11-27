<?php

namespace Tests\Feature\DineIn;

use App\Models\AuditLog;
use App\Models\CashierShift;
use App\Models\KitchenItem;
use App\Services\CashierShiftService;
use App\Services\DineOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\DineIn\Concerns\MakesDineBills;
use Tests\TestCase;

/**
 * REQ-005 (#327 server half): the cashier can always take the money. A payment on a bill the guest is paying online
 * supersedes the guest's charge by moving the attempt counter, in the same transaction as the payment.
 */
class DineOrderTakeoverTest extends TestCase
{
    use MakesDineBills;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDineBill();
    }

    public function test_cashier_takes_over_a_pending_guest_charge(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk()->assertJsonPath('payment.attempt', 1);
        $bill = $this->bill($order);
        $this->assertTrue($bill->isAwaitingGuestPayment());

        $this->cashierPays($order);

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame('cash', $bill->payment_method);
        $this->assertSame(2, $bill->payment_attempt);
        $this->assertNull($bill->payment_reference);
        $this->assertNull($bill->payment_failure_reason);
        $this->assertSame(1, $bill->tenders()->count());
        $this->assertSame($this->cashier->id, $bill->cashier_id);
        $this->assertFalse($bill->isAwaitingGuestPayment());
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count()); // the flagged line, once
        Http::assertSentCount(1); // the guest's charge only: nothing is cancelled at the gateway (refund stays manual)

        $log = AuditLog::where('event', 'dine_order.online_payment_taken_over')->sole();
        $this->assertSame(1, $log->meta['guest_attempt']);
        $this->assertSame(2, $log->meta['attempt']);
        $this->assertSame($this->cashier->id, $log->meta['cashier_id']);
    }

    #[DataProvider('gateways')]
    public function test_cashier_gateway_charge_after_a_guest_attempt_is_booked(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        $this->guestPays($order)->assertOk();

        $this->cashierPays($order, 'qris');

        // The charge carries the cashier's attempt. Under the bare invoice its callback would read as attempt 0,
        // be treated as superseded and never be booked.
        $orderId = "{$bill->invoice}-r2";
        Http::assertSent(fn (Request $request) => ($request['transaction_details']['order_id'] ?? $request['external_id'] ?? null) === $orderId);
        $tender = $bill->tenders()->sole();
        $this->assertSame($orderId, $tender->order_reference);
        $this->assertSame('pending', $bill->fresh()->payment_status);

        $this->notify($gateway, $orderId, 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame('paid', $tender->fresh()->payment_status);
        $this->assertSame(2, $bill->payment_attempt);
        $this->assertNull($bill->late_superseded_payment_at);
    }

    public function test_cash_after_the_cashiers_own_failed_charge_moves_the_counter_and_is_no_takeover(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);

        $this->cashierPays($order, 'qris'); // attempt 1
        $this->notify('midtrans', "{$bill->invoice}-r1", 'failed')->assertOk();
        $this->cashierPays($order);

        $this->assertSame(2, $bill->fresh()->payment_attempt);
        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->assertSame(0, AuditLog::where('event', 'dine_order.online_payment_taken_over')->count());
    }

    public function test_an_invalid_takeover_leaves_the_guest_charge_untouched(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();
        $bill = $this->bill($order);
        $before = $bill->getAttributes();

        try {
            // short cash: refused before anything is written
            app(DineOrderService::class)->pay($order, $this->cashier, [['method' => 'cash', 'amount' => self::TOTAL, 'cash_received' => 1000]]);
            $this->fail('The takeover should have been refused.');
        } catch (ValidationException) {
            // expected
        }

        $bill->refresh();
        $this->assertSame($before, $bill->getAttributes());
        $this->assertTrue($bill->isAwaitingGuestPayment());
        $this->assertSame(0, AuditLog::where('event', 'dine_order.online_payment_taken_over')->count());
    }

    public function test_a_failed_takeover_charge_leaves_the_bill_payable_and_a_late_guest_payment_is_booked(): void
    {
        $this->useGateway('midtrans', fakeHttp: false);
        $calls = 0;
        // The guest's charge succeeds; the cashier's own charge after it is refused by the gateway.
        Http::fake(function () use (&$calls) {
            return ++$calls === 1
                ? Http::response(['token' => 'tok', 'redirect_url' => 'https://pay.test/midtrans', 'qr_string' => '000201MIDTRANS'], 201)
                : Http::response(['status_message' => 'down'], 500);
        });
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();

        try {
            $this->cashierPays($order, 'qris');
            $this->fail('The gateway refused the charge.');
        } catch (ValidationException $exception) {
            $this->assertSame(['tenders' => ['Midtrans error: down']], $exception->errors());
        }

        // The takeover had committed: the guest's attempt 1 stays superseded and the cashier can take the money.
        $bill = $this->bill($order);
        $this->assertSame(['failed', 'gateway_error', 2], [$bill->payment_status, $bill->payment_failure_reason, $bill->payment_attempt]);
        $this->assertSame(0, $bill->tenders()->count());
        $this->assertNull($bill->payment_reference);
        $this->assertFalse($bill->isAwaitingGuestPayment());
        $this->assertSame(1, AuditLog::where('event', 'dine_order.online_payment_taken_over')->count());

        // The guest pays her QR anyway: nothing holds the bill, so the money taken once is booked once.
        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNull($bill->late_superseded_payment_at);
        $this->assertTrue(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count());
    }

    public function test_the_cashiers_own_pending_charge_still_holds_the_bill(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();
        $this->cashierPays($order, 'qris'); // her own QRIS: attempt 2 and tender rows, so no longer the guest's charge

        $bill = $this->bill($order);
        $this->assertSame(2, $bill->payment_attempt);
        $this->assertFalse($bill->isAwaitingGuestPayment());

        try {
            $this->cashierPays($order);
            $this->fail('A pending charge of the cashier must hold the bill.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('menunggu pembayaran', $exception->errors()['order'][0]);
        }

        $bill->refresh();
        $this->assertSame(2, $bill->payment_attempt);
        $this->assertSame(1, $bill->tenders()->count());
    }

    public function test_cashier_payment_after_a_failed_guest_attempt_supersedes_it(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        $this->guestPays($order)->assertOk();
        $this->notify('midtrans', "{$bill->invoice}-r1", 'expired')->assertOk();
        $bill->refresh();
        $this->assertSame('expired', $bill->payment_failure_reason);
        $this->assertFalse($bill->isAwaitingGuestPayment());

        $this->cashierPays($order);

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame(2, $bill->payment_attempt);
        $this->assertNull($bill->payment_failure_reason);
        $this->assertSame(0, AuditLog::where('event', 'dine_order.online_payment_taken_over')->count()); // nothing was in flight

        // The guest's QR of attempt 1 is paid after all: the counter moved, so it is flagged, not dropped silently.
        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('cash', $bill->payment_method);
        $this->assertNull($bill->payment_reference);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertSame(1, AuditLog::where('event', 'payment.late_superseded')->count());
    }

    public function test_a_takeover_after_the_owning_shift_closed_is_not_paid_after_close(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        $this->guestPays($order)->assertOk();
        $closedShiftId = $bill->cashier_shift_id;
        $this->closeShiftOf($bill);
        $newShift = app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 50000, null, $this->warehouse->id);

        $this->cashierPays($order);

        // The money is in the new shift's drawer, so the payment moves there and nothing is flagged.
        $bill->refresh();
        $this->assertNotSame($closedShiftId, $bill->cashier_shift_id);
        $this->assertSame($newShift->id, $bill->cashier_shift_id);
        $this->assertSame(CashierShift::STATUS_CLOSED, CashierShift::findOrFail($closedShiftId)->status);
        $this->assertNull($bill->paid_after_close_at);

        // The guest's late payment of attempt 1 is flagged for a refund, still not "paid after close".
        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();
        $bill->refresh();
        $this->assertNull($bill->paid_after_close_at);
        $this->assertNotNull($bill->late_superseded_payment_at);
    }
}
