<?php

namespace Tests\Feature\DineIn;

use App\Http\Controllers\Api\PaymentWebhookController;
use App\Models\AuditLog;
use App\Models\KitchenItem;
use App\Models\SalesReturn;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Feature\DineIn\Concerns\MakesDineBills;
use Tests\TestCase;

/**
 * #358 BR-4: a guest payment flagged because a cashier charge was pending is judged again once that charge fails or
 * expires: the oldest that is still due is booked, any other keeps its refund flag.
 */
class LatePaymentRecheckTest extends TestCase
{
    use MakesDineBills;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDineBill();
        Queue::fake(); // the takeover's expiry of the guest charge is not under test
    }

    /** The guest's QR(s) are paid while the cashier's own QRIS (the next attempt) is pending, so they are flagged. */
    private function flaggedWhileTheCashierCharges(int $guestAttempts = 1, int $amount = self::TOTAL): Transaction
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        for ($attempt = 1; $attempt <= $guestAttempts; $attempt++) {
            $this->guestPays($order)->assertOk();
        }
        $this->cashierPays($order, 'qris');
        $bill = $this->bill($order);
        for ($attempt = 1; $attempt <= $guestAttempts; $attempt++) {
            $this->notify('midtrans', "{$bill->invoice}-r{$attempt}", 'paid', amount: $amount)->assertOk();
        }
        $this->assertNotNull($bill->fresh()->late_superseded_payment_at);

        return $bill;
    }

    /** The guest pays her QR(s) while the cashier's QRIS call is out, and the gateway then refuses that call. */
    private function cashierChargeRefusedAfterTheGuestPaid(int $guestAttempts = 1): Transaction
    {
        $this->useGateway('midtrans', fakeHttp: false);
        $order = $this->acceptedOrder();
        $invoice = $this->bill($order)->invoice;
        Http::fake(['app.sandbox.midtrans.com/*' => function (Request $request) use ($invoice, $guestAttempts) {
            if ($request['transaction_details']['order_id'] !== "{$invoice}-r".($guestAttempts + 1)) {
                return Http::response(['redirect_url' => 'https://pay.test/midtrans'], 201); // a guest's charge
            }
            for ($attempt = 1; $attempt <= $guestAttempts; $attempt++) {
                $this->notify('midtrans', "{$invoice}-r{$attempt}", 'paid');
            }

            return Http::response(['status_message' => 'down'], 500);
        }]);
        for ($attempt = 1; $attempt <= $guestAttempts; $attempt++) {
            $this->guestPays($order)->assertOk();
        }

        try {
            $this->cashierPays($order, 'qris');
            $this->fail('The gateway refused the charge.');
        } catch (ValidationException) {
            // expected
        }

        return $this->bill($order);
    }

    /** @return array<int, array<string, mixed>> the meta of the rows that booked a flagged payment */
    private function booked(): array
    {
        return AuditLog::where('event', 'payment.late_after_failed')->orderBy('id')->get()
            ->pluck('meta')->where('booked', true)->values()->all();
    }

    public function test_flagged_guest_payment_is_booked_when_the_cashier_charge_fails(): void
    {
        $bill = $this->cashierChargeRefusedAfterTheGuestPaid();

        $this->assertSame(['paid', "mt-{$bill->invoice}-r1"], [$bill->payment_status, $bill->payment_reference]);
        $this->assertNull($bill->late_superseded_payment_at);
        $this->assertSame(0, $bill->tenders()->count());
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count());
        [$meta] = $this->booked();
        $this->assertSame([true, 'cashier_charge_failed'], [$meta['rechecked'], $meta['trigger']]);
    }

    public function test_flagged_guest_payment_is_booked_when_the_cashier_charge_expires(): void
    {
        $bill = $this->flaggedWhileTheCashierCharges();

        $this->notify('midtrans', "{$bill->invoice}-r2", 'expired')->assertOk();

        $bill->refresh();
        $this->assertSame(['paid', "mt-{$bill->invoice}-r1"], [$bill->payment_status, $bill->payment_reference]);
        $this->assertNull($bill->late_superseded_payment_at);
        $this->assertSame('paid', $bill->tenders()->sole()->payment_status); // the late-booking rule settles the failed tender
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count());
        [$meta] = $this->booked();
        $this->assertSame([true, 'expired'], [$meta['rechecked'], $meta['trigger']]);
    }

    public function test_recheck_keeps_the_flag_when_the_amount_differs(): void
    {
        $bill = $this->flaggedWhileTheCashierCharges(amount: 20000);

        $this->notify('midtrans', "{$bill->invoice}-r2", 'failed')->assertOk();

        $bill->refresh();
        $this->assertSame('failed', $bill->payment_status);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $meta = AuditLog::where('event', 'payment.late_rechecked')->sole()->meta;
        $this->assertSame([false, 'amount_mismatch', 'cashier_charge_failed'], [$meta['booked'], $meta['reason'], $meta['trigger']]);
        $this->assertSame([], $this->booked());
    }

    public function test_a_flag_from_an_earlier_charge_stays_flagged_when_a_later_charge_ends(): void
    {
        // Both kinds of flag, raised while attempt 2 was pending: an older charge paid, and an order id above the bill's.
        $bill = $this->flaggedWhileTheCashierCharges(amount: 20000);
        $this->notify('midtrans', "{$bill->invoice}-r9", 'paid', amount: 20000)->assertOk();
        $this->assertSame([2], AuditLog::where('event', 'payment.late_superseded')->get()->pluck('meta.bill_attempt')->all());
        $this->notify('midtrans', "{$bill->invoice}-r2", 'expired')->assertOk(); // amount differs: both stay, refunded by hand
        // A return makes the bill owe exactly that amount, and the cashier's next charge expires too.
        SalesReturn::create([
            'code' => 'SR-RECHECK', 'transaction_id' => $bill->id, 'cashier_id' => $this->cashier->id, 'status' => 'completed',
            'return_type' => 'refund_cash', 'refund_amount' => 5000, 'credited_amount' => 0, 'total_return_amount' => 5000,
            'completed_at' => now(),
        ]);
        $this->cashierPays($bill->dineOrder()->sole(), 'qris');

        $this->notify('midtrans', "{$bill->invoice}-r3", 'expired')->assertOk();

        $bill->refresh();
        $this->assertSame(['failed', 20000], [$bill->payment_status, $bill->remainingTotal()]);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertSame([], $this->booked());
        $this->assertSame(2, AuditLog::where('event', 'payment.late_rechecked')->count()); // from attempt 2 only
    }

    public function test_a_failing_recheck_runs_on_its_own_leaves_the_restore_and_is_reported(): void
    {
        Exceptions::fake();
        $baseline = DB::transactionLevel();
        $level = null;
        $this->partialMock(PaymentWebhookController::class)->shouldReceive('recheckFlaggedPayments')->once()
            ->andReturnUsing(function () use (&$level) {
                $level = DB::transactionLevel();

                throw new RuntimeException('deadlock');
            });

        $bill = $this->cashierChargeRefusedAfterTheGuestPaid();

        // Not nested in the restore's transaction: a deadlock there would take the restore with it.
        $this->assertSame($baseline + 1, $level);
        $this->assertSame(['failed', 'gateway_error', 0], [$bill->payment_status, $bill->payment_failure_reason, $bill->tenders()->count()]);
        $this->assertNotNull($bill->late_superseded_payment_at); // the flag is still there for a refund by hand
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_recheck_never_books_twice_on_redelivery(): void
    {
        $bill = $this->flaggedWhileTheCashierCharges();
        $this->notify('midtrans', "{$bill->invoice}-r2", 'expired')->assertOk();

        $this->notify('midtrans', "{$bill->invoice}-r2", 'expired')->assertOk();
        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();
        app(PaymentWebhookController::class)->recheckFlaggedPayments($bill->fresh(), null, 'expired', 2);

        $this->assertCount(1, $this->booked());
        $this->assertSame(0, AuditLog::where('event', 'payment.late_rechecked')->count()); // a booked key is no flag any more
        $this->assertNull($bill->fresh()->late_superseded_payment_at);
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count());
    }

    public function test_recheck_books_only_one_of_two_flags(): void
    {
        $bill = $this->flaggedWhileTheCashierCharges(guestAttempts: 2);

        $this->notify('midtrans', "{$bill->invoice}-r3", 'expired')->assertOk();

        $bill->refresh();
        $this->assertSame("mt-{$bill->invoice}-r1", $bill->payment_reference); // the oldest
        $this->assertNotNull($bill->late_superseded_payment_at); // the other still has to be refunded
        $this->assertCount(1, $this->booked());
        $meta = AuditLog::where('event', 'payment.late_rechecked')->sole()->meta;
        $this->assertSame(["{$bill->invoice}-r2", 'still_held'], [$meta['order_id'], $meta['reason']]);
    }

    public function test_recheck_never_books_a_second_flag_after_one_was_booked(): void
    {
        // The refused charge leaves no tender, so only "the bill is unpaid" keeps the second payment from being booked.
        $bill = $this->cashierChargeRefusedAfterTheGuestPaid(guestAttempts: 2);
        $this->assertSame(0, $bill->tenders()->count());

        foreach (['expired', 'expired'] as $trigger) {
            app(PaymentWebhookController::class)->recheckFlaggedPayments($bill->fresh(), null, $trigger, 3);
        }

        $this->assertCount(1, $this->booked());
        $this->assertSame("mt-{$bill->invoice}-r1", $bill->fresh()->payment_reference);
        $this->assertNotNull($bill->fresh()->late_superseded_payment_at);
        // Audited as rechecked once per payment and trigger.
        $this->assertSame(['cashier_charge_failed', 'expired'], AuditLog::where('event', 'payment.late_rechecked')->orderBy('id')->get()->pluck('meta.trigger')->all());
    }
}
