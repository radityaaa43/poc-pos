<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\PaymentSetting;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Services\AuditLogService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\TransactionTenderService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the gateway to expire a guest charge that a newer attempt superseded, so it cannot be paid a second time.
 *
 * It writes audit and log rows, never money: a payment that still arrives is the webhook's to book or flag, and that
 * flag is the safety net when this fails. handle() never throws, because under the sync queue it runs inside the
 * request that superseded the charge, and that request must not fail for it.
 */
class ExpireSupersededGatewayCharge implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const EXPIRED = 'payment.superseded_charge_expired';

    private const NOT_EXPIRED = 'payment.superseded_charge_not_expired';

    private const CANCEL_ERROR = 'payment.superseded_charge_cancel_error';

    /** The webhook writes one of these, with the order id in its meta, when a superseded charge is reported paid. */
    private const REPORTED_PAID = ['payment.late_superseded', 'payment.late_after_failed'];

    public int $tries = 5;

    /** @var array<int, int> about 11 minutes in all: a guest charge expires by itself after 15 (DineOrderOnlinePaymentService::EXPIRES_IN_MINUTES) */
    public array $backoff = [15, 60, 180, 420];

    public int $timeout = 30; // two gateway calls of 10 s at most; below the database queue's retry_after (90 s)

    public int $uniqueFor = 1800;

    public function __construct(
        public int $transactionId,
        public string $orderId,
        public int $attempt,
        public string $method,
        public ?string $reference,
        public ?string $pageUrl = null,
    ) {}

    /**
     * Queue the expiry of a superseded guest charge, to run once the surrounding transaction commits.
     *
     * @param  array<string, mixed>  $charge  that charge's payment_attempt, payment_method, payment_reference and payment_url
     */
    public static function dispatchFor(Transaction $bill, array $charge): void
    {
        $attempt = (int) $charge['payment_attempt'];

        // No reference yet: the gateway is still creating the charge, and the request doing so dispatches this when the
        // answer finds it superseded. Asking now would record "not found" for a charge that is about to exist.
        if ($attempt < 1 || blank($charge['payment_reference'])) {
            return;
        }

        self::dispatch($bill->id, "{$bill->invoice}-r{$attempt}", $attempt, $charge['payment_method'], $charge['payment_reference'], $charge['payment_url'] ?? null)->afterCommit();
    }

    public function uniqueId(): string
    {
        return $this->orderId;
    }

    public function handle(PaymentGatewayManager $gateways, TransactionTenderService $tenders, AuditLogService $audit): void
    {
        $check = null;

        try {
            $check = $this->check($tenders);

            if (! $check) {
                return;
            }

            // No transaction open: no lock is held while the gateway answers.
            $result = $gateways->expireCharge($check['gateway'], $check['setting'], $this->orderId, $this->reference, $this->pageUrl);
            $this->record($audit, $check, $result);
        } catch (Throwable $exception) {
            report($exception);
            $result = 'transient';

            // Nothing was audited for this try, and after the last one nothing else will be. rescue(): never throw.
            if ($this->attempts() >= $this->tries) {
                rescue(fn () => $this->record($audit, $check, 'error'));
            }
        }

        if ($result === 'transient' && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1]);
        } elseif (in_array($result, ['transient', 'permanent'], true)) {
            Log::error('Superseded charge cancel gave up', [...$this->context(), 'result' => $result, 'job_attempt' => $this->attempts()]);
        }
    }

    /**
     * Whether there is still a charge to expire, read before the call.
     *
     * @return array{gateway: string, setting: PaymentSetting, seen: int}|null
     */
    private function check(TransactionTenderService $tenders): ?array
    {
        return DB::transaction(function () use ($tenders) {
            $bill = $this->lockedBill();

            if (! $bill || $this->orderId !== "{$bill->invoice}-r{$this->attempt}") {
                return $this->skip('warning', 'no bill with this order id');
            }

            // A superseded charge is older than the bill's attempt: a live one is never expired.
            if ($bill->payment_attempt <= $this->attempt) {
                return $this->skip('info', 'the charge is still current');
            }

            if ($this->audits($bill, self::REPORTED_PAID)->exists()) {
                return $this->skip('info', 'already reported paid');
            }

            if ($this->audits($bill, [self::EXPIRED, self::NOT_EXPIRED])->exists()) {
                return $this->skip('info', 'already done');
            }

            $outlet = $bill->warehouse?->outlet;
            $gateway = $this->method === TransactionTender::METHOD_QRIS ? $tenders->resolveQrisGateway($outlet) : $this->method;
            $setting = PaymentSetting::forOutlet($outlet);

            if (! $gateway || ! $setting?->isGatewayReady($gateway)) {
                return $this->skip('warning', 'no gateway ready');
            }

            if ($gateway === PaymentSetting::GATEWAY_XENDIT && blank($this->reference)) {
                return $this->skip('warning', 'no reference');
            }

            return ['gateway' => $gateway, 'setting' => $setting, 'seen' => $this->settlement($bill)];
        });
    }

    /** @param  array{gateway: string, setting: PaymentSetting, seen: int}|null  $check  null: the check itself failed */
    private function record(AuditLogService $audit, ?array $check, string $result): void
    {
        DB::transaction(function () use ($audit, $check, $result) {
            $bill = $this->lockedBill();

            if (! $bill) {
                return;
            }

            $settled = $check && $this->settlement($bill) !== $check['seen'];
            // A settlement that arrived while the gateway was answering wins in the record too.
            $outcome = $result === 'expired' && $settled ? 'settled_during_call' : $result;

            [$event, $description, $extra] = match ($outcome) {
                'expired' => [self::EXPIRED, 'dikedaluwarsakan di gateway', []],
                'not_pending', 'not_found', 'settled_during_call' => [self::NOT_EXPIRED, "tidak dikedaluwarsakan ({$outcome})", ['reason' => $outcome]],
                default => [self::CANCEL_ERROR, "gagal dikedaluwarsakan (percobaan {$this->attempts()} dari {$this->tries})", ['final' => $result === 'permanent' || $this->attempts() >= $this->tries]],
            };
            $meta = [
                'gateway' => $check['gateway'] ?? $this->method,
                'order_id' => $this->orderId,
                'attempt' => $this->attempt,
                'bill_attempt' => $bill->payment_attempt,
                'result' => $result,
                'settled_during_call' => $settled,
                'job_attempt' => $this->attempts(),
                ...$extra,
            ];

            Log::log($event === self::EXPIRED ? 'info' : 'warning', $event === self::EXPIRED ? 'Superseded charge expired' : 'Superseded charge not expired', [...$this->context(), ...$meta]);
            $audit->log(
                event: $event,
                module: 'transactions',
                auditable: $bill,
                description: "Tagihan gateway lama {$this->orderId} invoice {$bill->invoice} {$description}.",
                meta: $meta,
            );
        });
    }

    /** The bill as committed: a shared lock waits for a webhook that is deciding on it right now. Call inside a transaction. */
    private function lockedBill(): ?Transaction
    {
        return Transaction::with('warehouse.outlet')->whereKey($this->transactionId)->sharedLock()->first();
    }

    /**
     * This charge's audit rows among the given events.
     *
     * @param  array<int, string>  $events
     * @return Builder<AuditLog>
     */
    private function audits(Transaction $bill, array $events): Builder
    {
        return AuditLog::query()->whereIn('event', $events)
            ->where('auditable_type', $bill->getMorphClass())
            ->where('auditable_id', $bill->id)
            ->where('meta->order_id', $this->orderId);
    }

    /**
     * The reports of this charge being paid, to compare across the call. Not the bill's status or refund marker: another
     * payment failing, being paid or being re-checked meanwhile says nothing about this one.
     */
    private function settlement(Transaction $bill): int
    {
        return $this->audits($bill, self::REPORTED_PAID)->count();
    }

    private function skip(string $level, string $reason): null
    {
        Log::log($level, "Superseded charge not asked to expire: {$reason}", $this->context());

        return null;
    }

    /** @return array<string, mixed> */
    private function context(): array
    {
        return ['transaction_id' => $this->transactionId, 'order_id' => $this->orderId];
    }
}
