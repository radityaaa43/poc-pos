<?php

namespace Tests\Feature\Jobs;

use App\Jobs\ExpireSupersededGatewayCharge;
use App\Models\AuditLog;
use App\Models\PaymentSetting;
use App\Models\Transaction;
use App\Services\AuditLogService;
use App\Services\DineOrderOnlinePaymentService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\DineIn\Concerns\MakesDineBills;
use Tests\TestCase;

/**
 * #359: a superseded guest charge is expired at its gateway by a queued job, after the commit that superseded it.
 * The job writes audit rows only; a payment that still arrives stays the webhook's to book or flag.
 */
class ExpireSupersededGatewayChargeTest extends TestCase
{
    use MakesDineBills;
    use RefreshDatabase;

    private const EXPIRED = 'payment.superseded_charge_expired';

    private const NOT_EXPIRED = 'payment.superseded_charge_not_expired';

    private const CANCEL_ERROR = 'payment.superseded_charge_cancel_error';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDineBill();
    }

    /** The gateway charges as usual; $expire answers its expire endpoint. */
    private function gatewayAnswering(string $gateway, mixed $expire): void
    {
        $this->useGateway($gateway, fakeHttp: false);

        Http::fake([
            'api.sandbox.midtrans.com/*' => $expire,
            'api.xendit.co/invoices/*' => $expire,
            'app.sandbox.midtrans.com/*' => Http::response(['redirect_url' => 'https://pay.test/midtrans', 'qr_string' => '000201'], 201),
            'api.xendit.co/*' => Http::response(['id' => 'xnd-1', 'invoice_url' => 'https://pay.test/xendit'], 200),
        ]);
    }

    /** A pending bill whose guest charge of attempt 1 was superseded by a second tap; the queued expiry is held back. */
    private function supersededCharge(): Transaction
    {
        Queue::fake();
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();
        $this->guestPays($order)->assertOk();

        return $this->bill($order);
    }

    private function jobFor(Transaction $bill, int $attempt = 1, string $method = 'qris', ?string $reference = 'ref-old'): ExpireSupersededGatewayCharge
    {
        return new ExpireSupersededGatewayCharge($bill->id, "{$bill->invoice}-r{$attempt}", $attempt, $method, $reference);
    }

    /** One run by a queue worker, as its $attempt-th try. */
    private function work(ExpireSupersededGatewayCharge $job, int $attempt = 1): ExpireSupersededGatewayCharge
    {
        $job->withFakeQueueInteractions();
        $job->job->attempts = $attempt;
        app()->call([$job, 'handle']);

        return $job;
    }

    private function assertNoExpireCall(): void
    {
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/expire'));
    }

    private function jobAudits(): int
    {
        return AuditLog::whereIn('event', [self::EXPIRED, self::NOT_EXPIRED, self::CANCEL_ERROR])->count();
    }

    // ---- the gateway calls -----------------------------------------------------------------------------

    /** @return array<string, array{0: string, 1: array<string, string>, 2: int, 3: string}> */
    public static function answers(): array
    {
        return [
            'midtrans expired' => ['midtrans', ['status_code' => '407'], 200, 'expired'],
            'midtrans cannot modify' => ['midtrans', ['status_code' => '412'], 200, 'not_pending'],
            'midtrans unknown' => ['midtrans', ['status_code' => '404'], 200, 'not_found'],
            'midtrans bad key' => ['midtrans', ['status_code' => '401'], 401, 'permanent'],
            'midtrans busy' => ['midtrans', [], 429, 'transient'],
            'midtrans down' => ['midtrans', ['status_code' => '503'], 200, 'transient'],
            'midtrans unexpected 200' => ['midtrans', ['status_code' => '200'], 200, 'permanent'],
            'xendit expired' => ['xendit', ['status' => 'EXPIRED'], 200, 'expired'],
            'xendit paid' => ['xendit', ['status' => 'PAID'], 200, 'not_pending'],
            'xendit refused' => ['xendit', ['error_code' => 'X'], 400, 'not_pending'],
            'xendit unknown' => ['xendit', ['error_code' => 'X'], 404, 'not_found'],
            'xendit bad key' => ['xendit', ['error_code' => 'X'], 401, 'permanent'],
            'xendit forbidden' => ['xendit', ['error_code' => 'X'], 403, 'permanent'],
            'xendit timeout' => ['xendit', [], 408, 'transient'],
            'xendit busy' => ['xendit', [], 429, 'transient'],
            'xendit down' => ['xendit', [], 502, 'transient'],
        ];
    }

    /** @param array<string, string> $body */
    #[DataProvider('answers')]
    public function test_each_gateway_answer_is_mapped(string $gateway, array $body, int $status, string $result): void
    {
        $this->useGateway($gateway, fakeHttp: false);
        Http::fake(['*' => Http::response($body, $status)]);

        $answer = app(PaymentGatewayManager::class)->expireCharge($gateway, PaymentSetting::sole(), 'INV-1-r1', 'xnd-1');

        $this->assertSame($result, $answer);
        $url = $gateway === 'midtrans' ? 'https://api.sandbox.midtrans.com/v2/INV-1-r1/expire' : 'https://api.xendit.co/invoices/xnd-1/expire!';
        $key = $gateway === 'midtrans' ? 'sk-test' : 'xnd-secret';
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === $url
            && $request->hasHeader('Authorization', 'Basic '.base64_encode("{$key}:")));
        Http::assertSentCount(1);
    }

    #[DataProvider('gateways')]
    public function test_an_unreachable_gateway_is_transient(string $gateway): void
    {
        $this->useGateway($gateway, fakeHttp: false);
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->assertSame('transient', app(PaymentGatewayManager::class)->expireCharge($gateway, PaymentSetting::sole(), 'INV-1-r1', 'xnd-1'));
    }

    public function test_midtrans_production_has_its_own_host_and_xendit_needs_the_invoice_id(): void
    {
        $this->useGateway('midtrans', fakeHttp: false);
        Http::fake(['*' => Http::response(['status_code' => '407'], 200)]);
        $setting = PaymentSetting::sole();
        $setting->midtrans_production = true;
        $manager = app(PaymentGatewayManager::class);

        $this->assertSame('permanent', $manager->expireCharge('xendit', $setting, 'INV-1-r1', null));
        Http::assertNothingSent();

        $manager->expireCharge('midtrans', $setting, 'INV-1-r1', null);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.midtrans.com/v2/INV-1-r1/expire');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: int, 2: string}> */
    public static function snapAnswers(): array
    {
        return [
            'canceled' => [['canceled_at' => '2026-01-01T00:00:00Z'], 200, 'expired'],
            'already canceled' => [['error_messages' => ['token already canceled']], 400, 'not_pending'],
            'unknown token' => [['error_messages' => ['token not found']], 404, 'not_found'],
            'a method picked meanwhile' => [['error_messages' => ['Transaction is on progress']], 400, 'transient'],
            'an unrecognised error' => [['error_messages' => ['something else']], 418, 'transient'],
            'success without a cancel time' => [[], 200, 'transient'],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('snapAnswers')]
    public function test_a_page_with_no_core_transaction_is_canceled_by_its_token(array $body, int $status, string $result): void
    {
        $this->useGateway('midtrans', fakeHttp: false);
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response(['status_code' => '404'], 200),
            'app.sandbox.midtrans.com/*' => Http::response($body, $status),
        ]);
        $page = 'https://app.sandbox.midtrans.com/snap/v3/redirection/tok-1';

        $answer = app(PaymentGatewayManager::class)->expireCharge('midtrans', PaymentSetting::sole(), 'INV-1-r1', 'INV-1-r1', $page);

        $this->assertSame($result, $answer);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions/tok-1/cancel'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('sk-test:')));
    }

    public function test_a_takeover_closes_the_guests_unused_payment_page(): void
    {
        $this->useGateway('midtrans', fakeHttp: false);
        Http::fake([
            'api.sandbox.midtrans.com/*' => Http::response(['status_code' => '404'], 200),
            'app.sandbox.midtrans.com/snap/v1/transactions/tok-1/cancel' => Http::response(['canceled_at' => 'now'], 200),
            'app.sandbox.midtrans.com/*' => Http::response(['redirect_url' => 'https://app.sandbox.midtrans.com/snap/v3/redirection/tok-1'], 201),
        ]);
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();

        $this->cashierPays($order); // sync queue: the expiry runs at the commit

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/tok-1/cancel'));
        $this->assertSame(1, AuditLog::where('event', self::EXPIRED)->count());
    }

    // ---- when it is queued ------------------------------------------------------------------------------

    public function test_a_newer_guest_tap_queues_the_expiry_of_the_older_charge_without_calling_the_gateway(): void
    {
        Queue::fake();
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();
        Queue::assertNothingPushed();

        $this->guestPays($order)->assertOk();

        $bill = $this->bill($order);
        Queue::assertPushed(ExpireSupersededGatewayCharge::class, fn (ExpireSupersededGatewayCharge $job) => $job->transactionId === $bill->id
            && $job->orderId === "{$bill->invoice}-r1" && $job->attempt === 1 && $job->method === 'qris' && $job->reference === "{$bill->invoice}-r1");
        Queue::assertPushedTimes(ExpireSupersededGatewayCharge::class, 1);
        $this->assertNoExpireCall();
    }

    public function test_a_cashier_takeover_queues_the_expiry_of_the_guest_charge(): void
    {
        Queue::fake();
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();

        $this->cashierPays($order);

        $orderId = "{$this->bill($order)->invoice}-r1";
        Queue::assertPushed(ExpireSupersededGatewayCharge::class, fn (ExpireSupersededGatewayCharge $job) => $job->orderId === $orderId);
        $this->assertNoExpireCall();
    }

    public function test_nothing_but_a_pending_guest_charge_is_expired(): void
    {
        Queue::fake();
        $this->useGateway('midtrans');

        $this->cashierPays($this->acceptedOrder()); // no charge at all

        $retried = $this->acceptedOrder(); // a guest retry after a failure: the old charge is dead already
        $this->guestPays($retried)->assertOk();
        $this->notify('midtrans', "{$this->bill($retried)->invoice}-r1", 'failed')->assertOk();
        $this->guestPays($retried)->assertOk();

        $own = $this->acceptedOrder(); // the cashier's own failed charge, then cash
        $this->cashierPays($own, 'qris');
        $this->notify('midtrans', "{$this->bill($own)->invoice}-r1", 'failed')->assertOk();
        $this->cashierPays($own);

        Queue::assertNothingPushed();
    }

    public function test_a_claim_that_rolls_back_queues_nothing(): void
    {
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();
        $this->mock(AuditLogService::class)->shouldReceive('log')->andThrow(new RuntimeException('audit down'));

        try {
            app(DineOrderOnlinePaymentService::class)->start($order->fresh(), DineOrderOnlinePaymentService::METHOD_QRIS);
            $this->fail('The claim should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit down', $exception->getMessage());
        }

        $this->assertSame(1, $this->bill($order)->payment_attempt);
        $this->assertNoExpireCall(); // the sync queue would have run it at the commit
    }

    public function test_a_rolled_back_claim_does_not_block_the_next_expiry(): void
    {
        // The driver production runs: it holds the job until the commit. Queue::fake() would skip that handling.
        config(['queue.default' => 'database']);
        $this->useGateway('midtrans');
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();
        $tapAgain = fn () => app(DineOrderOnlinePaymentService::class)->start($order->fresh(), DineOrderOnlinePaymentService::METHOD_QRIS);

        $this->mock(AuditLogService::class)->shouldReceive('log')->andThrow(new RuntimeException('audit down'));
        try {
            $tapAgain();
            $this->fail('The claim should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit down', $exception->getMessage());
        }
        $this->assertDatabaseCount('jobs', 0);
        $this->forgetMock(AuditLogService::class);

        // Same order id again: the rolled-back claim must have given its unique lock back.
        $tapAgain();

        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_a_dropped_guest_charge_is_expired_with_the_reference_the_gateway_returned(): void
    {
        $this->useGateway('xendit', fakeHttp: false);
        $order = $this->acceptedOrder();
        $bill = $this->bill($order);
        Http::fake([
            'api.xendit.co/invoices/*' => Http::response(['status' => 'EXPIRED'], 200),
            'api.xendit.co/v2/invoices' => function () use ($bill) {
                Transaction::whereKey($bill->id)->update(['payment_attempt' => 2]); // a newer claim while this one is out

                return Http::response(['id' => 'xnd-old', 'invoice_url' => 'https://pay.test/old'], 200);
            },
        ]);

        $this->guestPays($order)->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.xendit.co/invoices/xnd-old/expire!');
        $this->assertSame(1, AuditLog::where('event', self::EXPIRED)->count());
    }

    public function test_there_is_one_job_per_order_id_and_none_without_a_reference(): void
    {
        Queue::fake();
        $this->useGateway('midtrans');
        $bill = $this->bill($this->acceptedOrder());
        $charge = ['payment_attempt' => 1, 'payment_method' => 'qris', 'payment_reference' => null];

        ExpireSupersededGatewayCharge::dispatchFor($bill, $charge); // still being created: its own request dispatches it
        Queue::assertNothingPushed();

        ExpireSupersededGatewayCharge::dispatchFor($bill, ['payment_reference' => 'ref-old'] + $charge);
        ExpireSupersededGatewayCharge::dispatchFor($bill, ['payment_reference' => 'ref-old'] + $charge);

        Queue::assertPushedTimes(ExpireSupersededGatewayCharge::class, 1);
        $job = $this->jobFor($bill);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame("{$bill->invoice}-r1", $job->uniqueId());
    }

    // ---- what the job does ------------------------------------------------------------------------------

    #[DataProvider('gateways')]
    public function test_the_old_charge_is_expired_with_no_transaction_open_and_only_the_audit_log_is_written(string $gateway): void
    {
        $levelInCall = null;
        $this->gatewayAnswering($gateway, function () use (&$levelInCall) {
            $levelInCall = DB::transactionLevel();

            return Http::response(['status_code' => '407', 'status' => 'EXPIRED'], 200);
        });
        $bill = $this->supersededCharge();
        $before = $bill->getAttributes();
        $baseline = DB::transactionLevel();
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $job = $this->work($this->jobFor($bill));

        $this->assertFalse($job->job->isReleased());
        $this->assertSame($baseline, $levelInCall);
        $this->assertCount(1, $writes);
        $this->assertStringContainsString('audit_logs', $writes[0]);
        $this->assertSame($before, $bill->fresh()->getAttributes());
        $log = AuditLog::where('event', self::EXPIRED)->sole();
        $this->assertSame(['transactions', $bill->id, null], [$log->module, (int) $log->auditable_id, $log->user_id]);
        $this->assertEquals([
            'gateway' => $gateway, 'order_id' => "{$bill->invoice}-r1", 'attempt' => 1, 'bill_attempt' => 2,
            'result' => 'expired', 'settled_during_call' => false, 'job_attempt' => 1,
        ], $log->meta);
    }

    public function test_a_second_run_for_the_same_order_id_calls_nothing(): void
    {
        $this->gatewayAnswering('midtrans', Http::response(['status_code' => '407'], 200));
        $bill = $this->supersededCharge();

        $this->work($this->jobFor($bill));
        $this->work($this->jobFor($bill));

        Http::assertSentCount(3); // the two guest charges and one expiry
        $this->assertSame(1, $this->jobAudits());
    }

    public function test_a_charge_that_is_still_current_is_never_expired(): void
    {
        $this->gatewayAnswering('midtrans', Http::response(['status_code' => '407'], 200));
        $bill = $this->supersededCharge();

        $this->work($this->jobFor($bill, attempt: 2));

        $this->assertNoExpireCall();
        $this->assertSame(0, $this->jobAudits());
    }

    public function test_a_charge_already_reported_paid_is_left_to_the_webhook(): void
    {
        $this->gatewayAnswering('midtrans', Http::response(['status_code' => '407'], 200));
        $bill = $this->supersededCharge();
        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk(); // settled before the job ran

        $this->work($this->jobFor($bill));

        $this->assertNoExpireCall();
        $this->assertSame(0, $this->jobAudits());
        $this->assertSame('paid', $bill->fresh()->payment_status);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function notExpired(): array
    {
        return ['cannot be modified' => ['412', 'not_pending'], 'unknown to the gateway' => ['404', 'not_found']];
    }

    #[DataProvider('notExpired')]
    public function test_a_charge_the_gateway_did_not_expire_is_never_recorded_as_expired(string $code, string $reason): void
    {
        $this->gatewayAnswering('midtrans', Http::response(['status_code' => $code], 200));
        $bill = $this->supersededCharge();

        $job = $this->work($this->jobFor($bill));

        $this->assertFalse($job->job->isReleased());
        $this->assertSame($reason, AuditLog::where('event', self::NOT_EXPIRED)->sole()->meta['reason']);
        $this->assertSame(0, AuditLog::where('event', self::EXPIRED)->count());
    }

    public function test_a_settlement_during_the_cancel_wins(): void
    {
        $bill = null;
        $recording = false;
        $webhookStatus = null;
        $this->gatewayAnswering('midtrans', function () use (&$bill, &$recording, &$webhookStatus) {
            $recording = false; // the webhook's own writes are not the job's
            $webhookStatus = $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->status();
            $recording = true;

            return Http::response(['status_code' => '412'], 200);
        });
        $bill = $this->supersededCharge();
        $writes = [];
        DB::listen(function ($query) use (&$writes, &$recording) {
            if ($recording && preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $recording = true;

        $this->work($this->jobFor($bill));
        $recording = false;

        $this->assertSame(200, $webhookStatus);
        $this->assertSame(['paid', "mt-{$bill->invoice}-r1"], [$bill->fresh()->payment_status, $bill->fresh()->payment_reference]);
        $this->assertTrue(AuditLog::where('event', self::NOT_EXPIRED)->sole()->meta['settled_during_call']);
        $this->assertCount(1, $writes);
        $this->assertStringContainsString('audit_logs', $writes[0]);
    }

    public function test_an_expired_answer_after_a_settlement_is_audited_as_not_expired(): void
    {
        $bill = null;
        $webhookStatus = null;
        $this->gatewayAnswering('midtrans', function () use (&$bill, &$webhookStatus) {
            $webhookStatus = $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->status();

            return Http::response(['status_code' => '407'], 200);
        });
        $bill = $this->supersededCharge();

        $job = $this->work($this->jobFor($bill));

        $this->assertFalse($job->job->isReleased());
        $this->assertSame(200, $webhookStatus);
        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->assertSame(0, AuditLog::where('event', self::EXPIRED)->count());
        $meta = AuditLog::where('event', self::NOT_EXPIRED)->sole()->meta;
        $this->assertSame(['settled_during_call', 'expired', true], [$meta['reason'], $meta['result'], $meta['settled_during_call']]);
    }

    /** @return array<string, array<int, string>> */
    public static function otherPayments(): array
    {
        return ['the cashier is paid' => ['paid'], 'the current charge fails' => ['failed']];
    }

    #[DataProvider('otherPayments')]
    public function test_another_payments_outcome_during_the_call_still_records_the_expiry(string $outcome): void
    {
        $bill = null;
        $this->gatewayAnswering('midtrans', function () use (&$bill, $outcome) {
            $outcome === 'paid'
                ? $this->cashierPays($bill->dineOrder)
                : $this->notify('midtrans', "{$bill->invoice}-r2", 'failed');

            return Http::response(['status_code' => '407'], 200);
        });
        $bill = $this->supersededCharge();

        $this->work($this->jobFor($bill));

        $this->assertSame($outcome, $bill->fresh()->payment_status);
        $this->assertFalse(AuditLog::where('event', self::EXPIRED)->sole()->meta['settled_during_call']);
    }

    public function test_a_recheck_booking_another_charge_during_the_call_leaves_the_expiry_recorded(): void
    {
        $bill = null;
        $this->gatewayAnswering('midtrans', function () use (&$bill) {
            // The cashier's QR expires meanwhile: the flagged payment of r1 is booked and the refund marker cleared.
            $this->notify('midtrans', "{$bill->invoice}-r3", 'expired');

            return Http::response(['status_code' => '407'], 200);
        });
        $bill = $this->supersededCharge(); // guest taps r1 and r2
        $this->cashierPays($bill->dineOrder()->sole(), 'qris'); // r3, pending
        $this->notify('midtrans', "{$bill->invoice}-r1", 'paid')->assertOk();
        $this->assertNotNull($bill->fresh()->late_superseded_payment_at);

        $this->work($this->jobFor($bill, attempt: 2));

        $this->assertSame(['paid', null], [$bill->fresh()->payment_status, $bill->fresh()->late_superseded_payment_at]);
        $this->assertFalse(AuditLog::where('event', self::EXPIRED)->sole()->meta['settled_during_call']);
    }

    public function test_a_transient_failure_is_retried(): void
    {
        $this->gatewayAnswering('midtrans', Http::sequence()->push([], 503)->push(['status_code' => '407'], 200));
        $bill = $this->supersededCharge();

        $this->work($this->jobFor($bill))->assertReleased(15);
        $error = AuditLog::where('event', self::CANCEL_ERROR)->sole();
        $this->assertSame([false, 1], [$error->meta['final'], $error->meta['job_attempt']]);

        $job = $this->work($this->jobFor($bill), attempt: 2);

        $this->assertFalse($job->job->isReleased());
        $this->assertSame(2, AuditLog::where('event', self::EXPIRED)->sole()->meta['job_attempt']);
        $this->assertSame('pending', $bill->fresh()->payment_status);
    }

    public function test_the_expiry_gives_up_after_five_tries(): void
    {
        $this->gatewayAnswering('midtrans', Http::response([], 503));
        $bill = $this->supersededCharge();
        $before = $bill->getAttributes();
        Log::spy();

        foreach ([1 => 15, 2 => 60, 3 => 180, 4 => 420] as $attempt => $delay) {
            $this->work($this->jobFor($bill), $attempt)->assertReleased($delay);
        }
        $last = $this->work($this->jobFor($bill), 5);

        $this->assertFalse($last->job->isReleased());
        $finals = AuditLog::where('event', self::CANCEL_ERROR)->orderBy('id')->get()->map(fn (AuditLog $log) => $log->meta['final'])->all();
        $this->assertSame([false, false, false, false, true], $finals);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message) => $message === 'Superseded charge cancel gave up')->once();
        $this->assertSame($before, $bill->fresh()->getAttributes());
    }

    public function test_a_permanent_failure_is_not_retried(): void
    {
        $this->gatewayAnswering('midtrans', Http::response(['status_code' => '401'], 401));
        $bill = $this->supersededCharge();

        $job = $this->work($this->jobFor($bill));

        $this->assertFalse($job->job->isReleased());
        $this->assertTrue(AuditLog::where('event', self::CANCEL_ERROR)->sole()->meta['final']);
    }

    public function test_an_unexpected_error_is_reported_and_retried_instead_of_thrown(): void
    {
        Exceptions::fake();
        $this->gatewayAnswering('midtrans', fn () => throw new RuntimeException('boom'));
        $bill = $this->supersededCharge();

        $this->work($this->jobFor($bill))->assertReleased(15);

        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_an_unexpected_error_is_audited_on_the_last_try(): void
    {
        Exceptions::fake();
        $this->gatewayAnswering('midtrans', fn () => throw new RuntimeException('boom'));
        $bill = $this->supersededCharge();

        $this->work($this->jobFor($bill), 4)->assertReleased(420);
        $this->assertSame(0, $this->jobAudits()); // not the last try yet
        $last = $this->work($this->jobFor($bill), 5);

        $this->assertFalse($last->job->isReleased());
        $meta = AuditLog::where('event', self::CANCEL_ERROR)->sole()->meta;
        $this->assertSame([true, 'error', 5, 'midtrans'], [$meta['final'], $meta['result'], $meta['job_attempt'], $meta['gateway']]);
    }

    public function test_a_failing_expiry_never_breaks_the_payment(): void
    {
        // No Queue::fake(): the suite's sync queue runs the job inside the cashier's payment, right after its commit.
        $this->gatewayAnswering('midtrans', fn () => throw new ConnectionException('timeout'));
        $order = $this->acceptedOrder();
        $this->guestPays($order)->assertOk();

        $paid = $this->cashierPays($order);

        $this->assertSame('paid', $paid->payment_status);
        $this->assertSame('paid', $this->bill($order)->payment_status);
        $this->assertSame(1, AuditLog::where('event', self::CANCEL_ERROR)->count());
    }

    public function test_no_ready_gateway_stops_quietly(): void
    {
        $this->gatewayAnswering('midtrans', Http::response(['status_code' => '407'], 200));
        $bill = $this->supersededCharge();
        PaymentSetting::sole()->update(['midtrans_enabled' => false]);

        $job = $this->work($this->jobFor($bill));

        $this->assertFalse($job->job->isReleased());
        $this->assertNoExpireCall();
        $this->assertSame(0, $this->jobAudits());
    }

    public function test_xendit_without_a_reference_is_not_called(): void
    {
        $this->gatewayAnswering('xendit', Http::response(['status' => 'EXPIRED'], 200));
        $bill = $this->supersededCharge();

        $this->work($this->jobFor($bill, reference: null));

        $this->assertNoExpireCall();
        $this->assertSame(0, $this->jobAudits());
    }

    public function test_a_missing_bill_or_an_order_id_of_another_bill_is_skipped(): void
    {
        $this->gatewayAnswering('midtrans', Http::response(['status_code' => '407'], 200));
        $bill = $this->supersededCharge();

        $this->work(new ExpireSupersededGatewayCharge($bill->id + 1000, "{$bill->invoice}-r1", 1, 'qris', 'ref-old'));
        $this->work(new ExpireSupersededGatewayCharge($bill->id, 'OTHER-r1', 1, 'qris', 'ref-old'));

        $this->assertNoExpireCall();
        $this->assertSame(0, $this->jobAudits());
    }

    #[DataProvider('gateways')]
    public function test_the_webhook_ignores_the_notification_our_own_expiry_causes(string $gateway): void
    {
        $this->useGateway($gateway);
        $bill = $this->supersededCharge();
        $before = $bill->getAttributes();
        $orderId = "{$bill->invoice}-r1";

        $this->notify($gateway, $orderId, 'expired')->assertOk();
        $this->notify($gateway, $orderId, 'failed')->assertOk();

        if ($gateway === 'midtrans') {
            $gross = self::TOTAL.'.00';
            $this->postJson(route('webhooks.midtrans'), [
                'order_id' => $orderId, 'status_code' => '200', 'gross_amount' => $gross, 'transaction_status' => 'cancel',
                'transaction_id' => 'mt-cancel', 'signature_key' => hash('sha512', $orderId.'200'.$gross.'sk-test'),
            ])->assertOk();
        }

        $this->assertSame($before, $bill->fresh()->getAttributes());
    }
}
