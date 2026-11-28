<?php

namespace Tests\Feature\DineIn;

use App\Exceptions\PaymentGatewayException;
use App\Models\AuditLog;
use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\KitchenItem;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\TransactionSettlementService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\DineIn\Concerns\MakesDineBills;
use Tests\TestCase;

/**
 * #358: the cashier's gateway charge is claimed and committed first, created with no transaction open, and stored
 * only while that claim is still the bill's current one. A failed charge puts the bill back.
 */
class DineOrderGatewayChargeTest extends TestCase
{
    use MakesDineBills;
    use RefreshDatabase;

    private const CHARGE = ['reference' => 'ref-1', 'payment_url' => 'https://pay.test/1', 'qr_string' => '000201QR'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDineBill();
    }

    /** Midtrans enabled, and every charge answered by $answer instead of the gateway (called exactly once). */
    private function fakeCharge(Closure $answer): void
    {
        $this->useGateway('midtrans', fakeHttp: false);
        $this->mock(PaymentGatewayManager::class)->shouldReceive('createTenderPayment')->once()->andReturnUsing($answer);
    }

    /** @return array<string, array<int, string>> the errors of a payment that must be refused */
    private function refusal(Closure $payment): array
    {
        try {
            $payment();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('The payment should have been refused.');
    }

    /** What is committed when a request dies between saving the payment and the gateway's answer. */
    private function claimLeftBehind(DineOrder $order): void
    {
        $bill = $this->bill($order);
        $shift = CashierShift::findOrFail($bill->cashier_shift_id);

        DB::transaction(fn () => app(TransactionSettlementService::class)->claim(
            $bill, $this->cashier, $shift, null, [['method' => 'qris', 'amount' => self::TOTAL]],
        ));
    }

    public function test_the_gateway_is_called_with_no_transaction_open(): void
    {
        $baseline = DB::transactionLevel(); // RefreshDatabase wraps every test in one transaction
        $levelInCall = null;
        $this->fakeCharge(function () use (&$levelInCall) {
            $levelInCall = DB::transactionLevel();

            return self::CHARGE;
        });

        $this->cashierPays($this->acceptedOrder(), 'qris');

        $this->assertSame($baseline, $levelInCall);
    }

    #[DataProvider('gateways')]
    public function test_a_gateway_payment_is_stored_on_bill_and_tender_then_booked_by_the_webhook(string $gateway): void
    {
        $this->useGateway($gateway);
        $order = $this->acceptedOrder();
        $orderId = "{$this->bill($order)->invoice}-r1"; // every gateway claim is a new attempt with its own order id

        $returned = $this->cashierPays($order, 'qris');

        Http::assertSent(fn (Request $request) => ($request['transaction_details']['order_id'] ?? $request['external_id'] ?? null) === $orderId);
        $bill = $this->bill($order);
        $this->assertSame(['pending', 'qris', 1], [$bill->payment_status, $bill->payment_method, $bill->payment_attempt]);
        $charge = [
            'payment_reference' => $gateway === 'midtrans' ? $orderId : 'xnd-1', // Midtrans knows the charge by its order id
            'payment_url' => "https://pay.test/{$gateway}",
            'qr_string' => '000201'.strtoupper($gateway),
        ];
        $this->assertEquals($charge, $bill->only(array_keys($charge)));
        $tender = $bill->tenders()->sole();
        $this->assertEquals($charge, $tender->only(array_keys($charge)));
        $this->assertSame($charge['qr_string'], $returned->qr_string); // the caller gets the stored state

        $this->notify($gateway, $orderId, 'paid')->assertOk();

        $this->assertSame('paid', $this->bill($order)->payment_status);
        $this->assertSame('paid', $tender->fresh()->payment_status);
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count());
    }

    public function test_a_cash_payment_of_an_attempt_0_bill_does_not_move_the_counter(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();

        $this->cashierPays($order);

        $bill = $this->bill($order);
        $this->assertSame(['paid', 'cash', 0], [$bill->payment_status, $bill->payment_method, $bill->payment_attempt]);
        Http::assertNothingSent();
    }

    public function test_each_try_after_a_failed_charge_has_its_own_order_id(): void
    {
        $this->useGateway('midtrans', fakeHttp: false);
        $orderIds = [];
        Http::fake(function (Request $request) use (&$orderIds) {
            $orderIds[] = $request['transaction_details']['order_id'];

            return count($orderIds) === 1
                ? Http::response(['status_message' => 'down'], 500)
                : Http::response(['redirect_url' => 'https://pay.test/2'], 201);
        });
        $order = $this->acceptedOrder();
        $invoice = $this->bill($order)->invoice;

        $errors = $this->refusal(fn () => $this->cashierPays($order, 'qris'));
        $this->cashierPays($order, 'qris');

        $this->assertSame(['tenders' => ['Midtrans error: down']], $errors);
        $this->assertSame(["{$invoice}-r1", "{$invoice}-r2"], $orderIds);
        $this->assertSame('pending', $this->bill($order)->payment_status);
    }

    public function test_a_failed_charge_puts_the_bill_back_as_it_was(): void
    {
        $this->fakeCharge(fn () => throw new PaymentGatewayException('Gateway down'));
        $order = $this->acceptedOrder();
        // Another cashier than the one who accepted: her shift and name must not stay on a bill she took no money for.
        $second = User::factory()->create();
        app(CashierShiftService::class)->openShift($second, $second, 0, null, $this->warehouse->id);
        $before = Arr::except($this->bill($order)->getAttributes(), ['payment_attempt', 'updated_at']);
        $level = DB::transactionLevel();

        $errors = $this->refusal(fn () => $this->cashierPays($order, 'qris', $second));

        $this->assertSame(['tenders' => ['Gateway down']], $errors);
        $bill = $this->bill($order);
        $this->assertEquals($before, Arr::except($bill->getAttributes(), ['payment_attempt', 'updated_at']));
        $this->assertSame(1, $bill->payment_attempt); // the counter only grows
        $this->assertSame(0, $bill->tenders()->count());
        $this->assertSame($level, DB::transactionLevel()); // nothing left open, so the shift and the bill are not locked
        $log = AuditLog::where('event', 'transaction.gateway_charge_failed')->sole();
        $this->assertEquals(['attempt' => 1, 'method' => 'qris', 'exception' => PaymentGatewayException::class], $log->meta);
        $this->assertSame($second->id, (int) $log->user_id);
    }

    public function test_a_gateway_timeout_restores_the_bill(): void
    {
        Exceptions::fake();
        $this->fakeCharge(fn () => throw new ConnectionException('cURL error 28: timed out'));
        $order = $this->acceptedOrder();

        $errors = $this->refusal(fn () => $this->cashierPays($order, 'qris'));

        $this->assertSame(['tenders' => ['Gagal menghubungi gateway pembayaran. Coba lagi.']], $errors);
        $bill = $this->bill($order);
        $this->assertSame(['unpaid', Transaction::METHOD_OPEN_BILL, 1], [$bill->payment_status, $bill->payment_method, $bill->payment_attempt]);
        $this->assertSame(0, $bill->tenders()->count());
        Exceptions::assertReported(ConnectionException::class);
    }

    public function test_a_failed_charge_on_a_failed_bill_keeps_it_failed_with_its_reason(): void
    {
        $this->fakeCharge(fn () => throw new PaymentGatewayException('Gateway down'));
        $order = $this->acceptedOrder();
        $this->bill($order)->update(['payment_attempt' => 1, 'payment_method' => 'qris', 'payment_status' => 'failed', 'payment_failure_reason' => 'expired']);

        $this->refusal(fn () => $this->cashierPays($order, 'qris'));

        $bill = $this->bill($order);
        $this->assertSame(['failed', 'expired', 2], [$bill->payment_status, $bill->payment_failure_reason, $bill->payment_attempt]);
        $this->assertSame(0, $bill->tenders()->count());
    }

    public function test_a_second_payment_while_a_charge_is_in_flight_is_refused_and_the_gateway_is_called_once(): void
    {
        $order = $this->acceptedOrder();
        $refusals = [];
        $this->fakeCharge(function () use ($order, &$refusals) {
            // Caught here: an assertion failing inside the faked call would be swallowed as a gateway failure.
            foreach (['qris', 'cash'] as $method) {
                try {
                    $this->cashierPays($order, $method);
                } catch (ValidationException $exception) {
                    $refusals[] = $exception->errors()['order'][0] ?? '';
                }
            }

            return self::CHARGE;
        });

        $this->cashierPays($order, 'qris');

        $this->assertCount(2, $refusals);
        foreach ($refusals as $refusal) {
            $this->assertStringContainsString('menunggu pembayaran', $refusal);
        }
        $bill = $this->bill($order);
        $this->assertSame(['pending', 'ref-1', 1], [$bill->payment_status, $bill->payment_reference, $bill->payment_attempt]);
        $this->assertSame(1, $bill->tenders()->count());
    }

    public function test_a_superseded_charge_result_is_dropped_and_logged_with_its_reference(): void
    {
        Log::spy();
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        $this->fakeCharge(function () use ($bill) {
            Transaction::whereKey($bill->id)->update(['payment_attempt' => 2]); // a newer claim took the bill meanwhile

            return self::CHARGE;
        });

        $this->cashierPays($order, 'qris');

        $fresh = $this->bill($order);
        $this->assertSame([2, null, null, null], [$fresh->payment_attempt, $fresh->payment_reference, $fresh->payment_url, $fresh->qr_string]);
        $this->assertNull($fresh->tenders()->sole()->payment_reference);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => $message === 'Dine cashier charge not stored'
                && $context['reference'] === 'ref-1' && $context['order_id'] === "{$bill->invoice}-r1")
            ->once();
    }

    public function test_a_settlement_that_arrives_before_the_store_is_not_overwritten(): void
    {
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        $webhookStatus = null;
        $this->fakeCharge(function () use ($bill, &$webhookStatus) {
            $webhookStatus = $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->status();

            return self::CHARGE;
        });

        $returned = $this->cashierPays($order, 'qris');

        $this->assertSame(200, $webhookStatus);
        $this->assertSame('paid', $returned->payment_status);
        $fresh = $this->bill($order);
        $this->assertSame(['paid', null, null], [$fresh->payment_status, $fresh->qr_string, $fresh->late_superseded_payment_at]);
        $tender = $fresh->tenders()->sole();
        $this->assertSame(['paid', "mt-{$bill->invoice}-r1"], [$tender->payment_status, $tender->payment_reference]);
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count());
    }

    public function test_a_late_payment_of_a_failed_charge_takes_the_gateway_as_method_and_counts_in_the_shift(): void
    {
        $this->fakeCharge(fn () => throw new PaymentGatewayException('Gateway down'));
        $order = $this->acceptedOrder();
        $this->refusal(fn () => $this->cashierPays($order, 'qris'));
        $invoice = $this->bill($order)->invoice;

        // The gateway had created the charge after all, and it is paid.
        $this->notify('midtrans', "{$invoice}-r1", 'paid')->assertOk();

        $bill = $this->bill($order);
        $this->assertSame(['paid', 'midtrans'], [$bill->payment_status, $bill->payment_method]);
        $summary = app(CashierShiftService::class)->calculateSummary(CashierShift::findOrFail($bill->cashier_shift_id));
        $this->assertSame(self::TOTAL, $summary['non_cash_sales_total']);
    }

    public function test_restore_removes_only_the_claims_own_tenders(): void
    {
        $this->fakeCharge(fn () => throw new PaymentGatewayException('Gateway down'));
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        $settlement = app(TransactionSettlementService::class);
        $claim = DB::transaction(fn () => $settlement->claim(
            $bill, $this->cashier, CashierShift::findOrFail($bill->cashier_shift_id), null, [['method' => 'qris', 'amount' => self::TOTAL]],
        ));
        // A paid part the claim did not write (a split with a gateway is refused today): the restore leaves it alone
        // and takes the bill's status from before the claim, never from the tenders that remain.
        $cash = $bill->tenders()->create(['method' => TransactionTender::METHOD_CASH, 'amount' => 10000, 'cash_received' => 10000, 'payment_status' => 'paid']);

        $this->refusal(fn () => $settlement->charge($claim));

        $this->assertEquals([$cash->id], $bill->tenders()->pluck('id')->all());
        $this->assertSame('unpaid', $this->bill($order)->payment_status);
    }

    public function test_a_fresh_claim_is_not_replaced(): void
    {
        $this->freezeTime();
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->claimLeftBehind($order);
        $this->travel(119)->seconds();

        $errors = $this->refusal(fn () => $this->cashierPays($order, 'qris'));

        $this->assertStringContainsString('menunggu pembayaran', $errors['order'][0]);
        $this->assertSame(1, $this->bill($order)->payment_attempt);
        $this->assertSame(1, $this->bill($order)->tenders()->count());
        Http::assertNothingSent();
    }

    public function test_an_abandoned_claim_is_replaced_after_two_minutes(): void
    {
        $this->freezeTime();
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->claimLeftBehind($order);
        $this->travel(121)->seconds();

        $this->cashierPays($order, 'qris');

        $bill = $this->bill($order);
        $this->assertSame(['pending', 2, "{$bill->invoice}-r2"], [$bill->payment_status, $bill->payment_attempt, $bill->payment_reference]);
        $this->assertSame("{$bill->invoice}-r2", $bill->tenders()->sole()->payment_reference);
    }

    public function test_a_late_payment_of_an_abandoned_claim_is_flagged_not_booked_twice(): void
    {
        $this->freezeTime();
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->claimLeftBehind($order);
        $this->travel(121)->seconds();
        $this->cashierPays($order, 'qris');
        $bill = $this->bill($order);

        // The gateway had created the first charge after all, and it is paid now.
        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();

        $bill->refresh();
        $this->assertSame('pending', $bill->payment_status);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    public function test_a_failed_charge_that_replaced_an_abandoned_claim_leaves_the_bill_payable(): void
    {
        $this->freezeTime();
        $this->fakeCharge(fn () => throw new PaymentGatewayException('Gateway down'));
        $order = $this->acceptedOrder();
        $this->claimLeftBehind($order);
        $this->travel(121)->seconds();

        $this->refusal(fn () => $this->cashierPays($order, 'qris'));

        $bill = $this->bill($order);
        $this->assertSame(['failed', 'gateway_error', 2], [$bill->payment_status, $bill->payment_failure_reason, $bill->payment_attempt]);
        $this->assertSame(0, $bill->tenders()->count());
    }
}
