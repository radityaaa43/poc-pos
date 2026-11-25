<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\PaymentSetting;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Services\AuditLogService;
use App\Services\CashierShiftService;
use App\Services\TransactionTenderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    /**
     * Handle Midtrans notification webhook
     * URL: POST /api/webhooks/midtrans
     */
    public function midtrans(Request $request)
    {
        try {
            $orderId = $request->input('order_id');
            $transaction = Transaction::where('invoice', $this->baseInvoice($orderId))->with('warehouse.outlet')->first();
            $paymentSetting = PaymentSetting::forOutlet($transaction?->warehouse?->outlet);

            if (! $paymentSetting || ! $paymentSetting->midtrans_enabled) {
                return response()->json(['status' => 'error', 'message' => 'Midtrans not configured'], 400);
            }

            // Get notification data
            $statusCode = $request->input('status_code');
            $grossAmount = $request->input('gross_amount');
            $serverKey = $paymentSetting->resolvedSecret('midtrans_server_key');

            // Verify signature
            $signatureKey = $request->input('signature_key');
            $expectedSignature = hash('sha512', $orderId.$statusCode.$grossAmount.$serverKey);

            if (! is_string($signatureKey) || ! hash_equals($expectedSignature, $signatureKey)) {
                Log::warning('Midtrans Webhook: Invalid signature', [
                    'provider' => 'midtrans',
                    'order_id' => $orderId,
                    'verification_result' => 'invalid',
                    'error_category' => 'invalid_signature',
                ]);

                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 403);
            }

            // Split gateway tenders use invoice-method as the provider order id.
            $invoice = $this->baseInvoice($orderId);
            $transaction ??= Transaction::where('invoice', $invoice)->with('warehouse.outlet')->first();

            if (! $transaction) {
                Log::warning('Midtrans Webhook: Transaction not found', [
                    'provider' => 'midtrans',
                    'order_id' => $orderId,
                    'verification_result' => 'valid',
                    'error_category' => 'transaction_not_found',
                ]);

                return response()->json(['status' => 'error', 'message' => 'Transaction not found'], 404);
            }

            $transactionStatus = (string) $request->input('transaction_status');
            $this->applyNotification(
                $transaction,
                'midtrans',
                $orderId,
                $this->mapMidtransStatus($transactionStatus, $request->input('fraud_status')),
                $transactionStatus === 'expire',
                $request->input('transaction_id'),
                $request->input('gross_amount'),
            );

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error('Midtrans Webhook Error', [
                'provider' => 'midtrans',
                'order_id' => $request->input('order_id'),
                'verification_result' => 'unknown',
                'error_category' => 'exception',
                'message' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle Xendit notification webhook
     * URL: POST /api/webhooks/xendit
     */
    public function xendit(Request $request)
    {
        try {
            $externalId = $request->input('external_id');
            $transaction = Transaction::where('invoice', $this->baseInvoice($externalId))->with('warehouse.outlet')->first();
            $paymentSetting = PaymentSetting::forOutlet($transaction?->warehouse?->outlet);

            if (! $paymentSetting || ! $paymentSetting->xendit_enabled) {
                return response()->json(['status' => 'error', 'message' => 'Xendit not configured'], 400);
            }

            $callbackToken = $request->header('X-CALLBACK-TOKEN');
            $expectedToken = $paymentSetting->resolvedSecret('xendit_callback_token');

            if (blank($expectedToken)) {
                Log::warning('Xendit Webhook: Callback token is not configured.', [
                    'provider' => 'xendit',
                    'external_id' => $request->input('external_id'),
                    'verification_result' => 'misconfigured',
                    'error_category' => 'missing_callback_token',
                ]);

                return response()->json(['status' => 'error', 'message' => 'Xendit callback token is not configured'], 400);
            }

            if (! is_string($callbackToken) || ! hash_equals($expectedToken, $callbackToken)) {
                Log::warning('Xendit Webhook: Invalid callback token', [
                    'provider' => 'xendit',
                    'external_id' => $request->input('external_id'),
                    'verification_result' => 'invalid',
                    'error_category' => 'invalid_callback_token',
                ]);

                return response()->json(['status' => 'error', 'message' => 'Invalid callback token'], 403);
            }

            $status = $request->input('status');
            $paymentId = $request->input('id');

            if (blank($externalId) || blank($status) || blank($paymentId)) {
                return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 422);
            }

            // Split gateway tenders use invoice-method as the provider external id.
            $invoice = $this->baseInvoice($externalId);
            $transaction ??= Transaction::where('invoice', $invoice)->with('warehouse.outlet')->first();

            if (! $transaction) {
                Log::warning('Xendit Webhook: Transaction not found', [
                    'provider' => 'xendit',
                    'external_id' => $externalId,
                    'verification_result' => 'valid',
                    'error_category' => 'transaction_not_found',
                ]);

                return response()->json(['status' => 'error', 'message' => 'Transaction not found'], 404);
            }

            $this->applyNotification(
                $transaction,
                'xendit',
                $externalId,
                $this->mapXenditStatus($status),
                strtoupper($status) === 'EXPIRED',
                $paymentId,
                $request->input('paid_amount') ?? $request->input('amount'),
            );

            return response()->json(['status' => 'success']);

        } catch (\Exception $e) {
            Log::error('Xendit Webhook Error', [
                'provider' => 'xendit',
                'external_id' => $request->input('external_id'),
                'verification_result' => 'unknown',
                'error_category' => 'exception',
                'message' => $e->getMessage(),
            ]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * A gateway payment of a pay-later sale settles its receivable like a manual receivable payment:
     * a ReceivablePayment for the amount actually paid, capped at what is still owed. Returns the
     * sale's payment_status ('paid' only once nothing is owed). A sale without a receivable is paid.
     */
    private function settleReceivable(Transaction $transaction, mixed $paidAmount, string $method): string
    {
        return DB::transaction(function () use ($transaction, $paidAmount, $method) {
            $receivable = Receivable::where('transaction_id', $transaction->id)->lockForUpdate()->first();

            if (! $receivable) {
                return 'paid';
            }

            // No amount in the notification: take it as the whole balance.
            $applied = min((float) ($paidAmount ?: $receivable->remaining), $receivable->remaining);

            if ($applied > 0) {
                ReceivablePayment::create([
                    'receivable_id' => $receivable->id,
                    'paid_at' => now(),
                    'amount' => $applied,
                    'method' => $method,
                    'note' => 'Pembayaran gateway '.$transaction->invoice,
                ]);
                $receivable->paid = ($receivable->paid ?? 0) + $applied;
            }

            $receivable->status = $receivable->remaining <= 0 ? 'paid' : 'partial';
            if ($receivable->status !== 'paid' && $receivable->due_date && now()->gt($receivable->due_date)) {
                $receivable->status = 'overdue';
            }
            $receivable->save();

            return $receivable->status === 'paid' ? 'paid' : 'unpaid';
        });
    }

    /**
     * Why a bill failed, for the guest page (Kedaluwarsa vs Pembayaran gagal): both gateways report an expiry as `failed`.
     * Other statuses leave the column untouched.
     *
     * @return array<string, ?string>
     */
    private function failureReason(string $newStatus, bool $expired): array
    {
        return $newStatus === 'failed' ? ['payment_failure_reason' => $expired ? 'expired' : null] : [];
    }

    /**
     * Map Midtrans transaction status to our payment status
     */
    private function mapMidtransStatus(string $transactionStatus, ?string $fraudStatus = null): string
    {
        // Handle fraud status first
        if ($fraudStatus === 'challenge' || $fraudStatus === 'deny') {
            return 'failed';
        }

        return match ($transactionStatus) {
            'capture', 'settlement' => 'paid',
            'pending' => 'pending',
            'deny', 'cancel', 'expire' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Map Xendit invoice status to our payment status
     */
    private function mapXenditStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'PAID', 'SETTLED' => 'paid',
            'PENDING' => 'pending',
            'EXPIRED', 'FAILED' => 'failed',
            default => 'pending',
        };
    }

    /**
     * One gateway notification, decided on current data.
     *
     * Lock order, the same as DineOrderService::pay and closeShift: the owning shift (shared, so a close and a settlement
     * are ordered: the closed recap holds the payment or the payment is flagged, never neither), the dine order (its
     * completion runs inside the bill's update), then the bill. The bill is re-read under its lock, so a cashier payment
     * or a new guest charge that committed after the caller's read is seen. No network call runs in here.
     *
     * The shift is known before the bill is locked, so a takeover or a retry can move the bill to another shift in
     * between. The bill's shift is then re-checked under its lock; on a mismatch the transaction ends (nothing written, so
     * no lock on a second shift is ever taken while the bill is locked) and runs once more with the new shift. A second
     * mismatch fails closed: nothing is booked, the gateway gets a 500 and redelivers, and the decision is idempotent.
     */
    private function applyNotification(Transaction $transaction, string $gateway, string $providerId, string $newStatus, bool $expired, ?string $reference, mixed $amount): void
    {
        $shiftId = $transaction->cashier_shift_id;

        for ($round = 1; $round <= 2; $round++) {
            $settled = DB::transaction(function () use ($transaction, $gateway, $providerId, $newStatus, $expired, $reference, $amount, &$shiftId) {
                $shift = $shiftId ? CashierShift::whereKey($shiftId)->sharedLock()->first() : null;
                DineOrder::where('transaction_id', $transaction->id)->lockForUpdate()->value('id');
                $bill = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

                if ($bill->cashier_shift_id !== $shiftId) {
                    $shiftId = $bill->cashier_shift_id;

                    return false;
                }

                $attempt = $this->attemptOf($providerId);
                $outcome = $this->decide($bill, $attempt, $newStatus === 'paid', $reference);

                if ($outcome === 'flag') {
                    $this->markLatePayment($bill, 'payment.late_superseded', "Pembayaran lama invoice {$bill->invoice} diterima setelah dibayar ulang; perlu refund manual.", ['gateway' => $gateway, 'order_id' => $providerId, 'amount' => $amount], $reference);
                } elseif ($outcome === 'late') {
                    $this->settleLatePayment($bill, $shift, $gateway, $providerId, $attempt, $reference, $amount);
                } elseif ($outcome === 'record') {
                    $this->record($bill, $shift, $gateway, $providerId, $newStatus, $expired, $reference, $amount);
                } else {
                    Log::info(ucfirst($gateway).' Webhook: notification ignored', [
                        'provider' => $gateway,
                        'order_id' => $providerId,
                        'payment_status' => $bill->payment_status,
                        'payment_attempt' => $bill->payment_attempt,
                    ]);
                }

                return true;
            });

            if ($settled) {
                return;
            }
        }

        Log::warning(ucfirst($gateway).' Webhook: the bill kept moving between shifts, not booked; the gateway will redeliver', [
            'provider' => $gateway,
            'order_id' => $providerId,
            'transaction_id' => $transaction->id,
        ]);

        throw new \RuntimeException('The bill moved to another shift while the notification was being applied; try again.');
    }

    /**
     * What a notification does to the bill, read under its lock.
     * record = the normal update; flag = a second or stale payment, never booked, marked for a manual refund;
     * late = a paid notification for an older or failed attempt (booked or flagged by settleLatePayment); ignore = nothing.
     */
    private function decide(Transaction $bill, int $attempt, bool $paid, ?string $reference): string
    {
        $current = (int) $bill->payment_attempt;
        $terminal = in_array($bill->payment_status, ['paid', 'failed'], true);

        if (! $paid) {
            return $attempt === $current && ! $terminal ? 'record' : 'ignore';
        }

        if ($bill->payment_status === 'paid') {
            // Attempt 0 bills (portal and plain POS sales) keep today's rule: a repeat is ignored.
            return ($attempt === 0 && $current === 0) || $this->isRedelivery($bill, $attempt, $reference) ? 'ignore' : 'flag';
        }

        if ($attempt === $current) {
            if ($bill->payment_status !== 'failed') {
                return 'record';
            }

            // Failed is final for attempt 0; a bill with a payment history can still receive its late payment.
            return $current === 0 ? 'ignore' : 'late';
        }

        return $current > 0 && $attempt < $current ? 'late' : 'flag';
    }

    /**
     * The same payment again, or a second one? The gateway's transaction id is the key (the bill's own or its tenders'):
     * when either side has none, fall back to the order id's attempt.
     */
    private function isRedelivery(Transaction $bill, int $attempt, ?string $reference): bool
    {
        $known = $bill->tenders()->pluck('payment_reference')->push($bill->payment_reference)->filter()->all();

        return filled($reference) && $known !== []
            ? in_array($reference, $known, true)
            : $attempt === (int) $bill->payment_attempt;
    }

    /**
     * A paid notification for an older attempt, or for a failed one, on a bill that is not paid. Booked as the payment
     * when nothing else holds the bill (no pending or paid tender rows, see hasBlockingTenders) and the amount is what is
     * still owed; flagged for a manual refund otherwise. The event is audited either way.
     */
    private function settleLatePayment(Transaction $bill, ?CashierShift $shift, string $gateway, string $providerId, int $attempt, ?string $reference, mixed $amount): void
    {
        $priorStatus = $bill->payment_status;
        $meta = ['gateway' => $gateway, 'order_id' => $providerId, 'amount' => $amount, 'order_attempt' => $attempt, 'bill_attempt' => $bill->payment_attempt, 'prior_status' => $priorStatus];

        // A sale that expired and was restocked has no goods left to pay for: refund, do not book.
        $booked = ! $bill->hasBlockingTenders()
            && is_numeric($amount) && (int) round((float) $amount) === $bill->remainingTotal()
            && ! $bill->wasRestocked();

        if ($booked) {
            $this->record($bill, $shift, $gateway, $providerId, 'paid', false, $reference, $amount, late: true);
            app(AuditLogService::class)->log(
                event: 'payment.late_after_failed',
                module: 'transactions',
                auditable: $bill,
                description: "Pembayaran terlambat invoice {$bill->invoice} diterima dan dicatat sebagai pembayaran (status sebelumnya: {$priorStatus}).",
                meta: [...$meta, 'reference' => $reference, 'booked' => true],
            );

            return;
        }

        $this->markLatePayment($bill, 'payment.late_after_failed', "Pembayaran terlambat invoice {$bill->invoice} tidak dicatat; perlu refund manual.", [...$meta, 'booked' => false], $reference);
    }

    /**
     * The normal update of a bill by a notification of its current attempt (or a late one booked as its payment).
     * A tender whose order id matches is updated first (a cashier's own gateway charge); a pay-later sale settles its receivable.
     */
    private function record(Transaction $bill, ?CashierShift $shift, string $gateway, string $providerId, string $newStatus, bool $expired, ?string $reference, mixed $amount, bool $late = false): void
    {
        // Split payments: tender-level update when the order id targets one (invoice-{method}[-r{attempt}]).
        // A late payment of a bill whose own gateway charge failed (it blocks nothing, see hasBlockingTenders) settles that tender.
        // Every failed tender is settled: one left failed would keep the whole bill failed after the money was booked.
        $tenders = $late
            ? $bill->tenders()->where('payment_status', TransactionTender::STATUS_FAILED)->get()
            : TransactionTender::where('transaction_id', $bill->id)
                ->whereIn('method', [$gateway, TransactionTender::METHOD_QRIS])
                ->get()
                ->filter(fn (TransactionTender $t) => $providerId === $t->order_reference);

        if ($tenders->isNotEmpty()) {
            foreach ($tenders as $tender) {
                $tender->update([
                    'payment_status' => $newStatus,
                    'payment_reference' => $reference ?: $tender->payment_reference,
                    'paid_at' => $newStatus === 'paid' ? now() : $tender->paid_at,
                ]);
            }
            $values = ['payment_status' => app(TransactionTenderService::class)->aggregateStatus(
                $bill->tenders()->get()->map(fn (TransactionTender $t) => ['method' => $t->method, 'payment_status' => $t->payment_status])->all()
            )];

            // The bill keeps the reference of what was actually booked: a later callback carrying the cashier charge's own
            // reference must not read as a redelivery of this payment (isRedelivery compares against it).
            if ($late) {
                $values['payment_reference'] = $reference ?: $bill->payment_reference;
            }
        } else {
            $values = [
                'payment_status' => $newStatus === 'paid' ? $this->settleReceivable($bill, $amount, $gateway) : $newStatus,
                'payment_reference' => $reference ?: $bill->payment_reference,
                ...$this->failureReason($newStatus, $expired),
                ...($late ? ['payment_failure_reason' => null] : []),
            ];
        }

        // The owning shift closed first: its recap is a stored snapshot and stays as it was; the payment is only flagged.
        $afterClose = $values['payment_status'] === 'paid' && $shift && ! $shift->isOpen()
            && ! CashierShiftService::countedInClosedRecap($bill->payment_method);

        $bill->update($afterClose ? [...$values, 'paid_after_close_at' => now()] : $values);

        if ($afterClose) {
            app(AuditLogService::class)->log(
                event: 'payment.paid_after_close',
                module: 'transactions',
                auditable: $bill,
                description: "Invoice {$bill->invoice} dibayar setelah shift ditutup; rekap shift tidak berubah.",
                meta: ['shift_id' => $shift->id, 'gateway' => $gateway, 'order_id' => $providerId, 'amount' => $amount],
            );
            Log::info(ucfirst($gateway).' Webhook: paid after shift close', ['provider' => $gateway, 'order_id' => $providerId, 'transaction_id' => $bill->id]);
        }

        Log::info(ucfirst($gateway).' Webhook: Transaction updated', [
            'provider' => $gateway,
            'order_id' => $providerId,
            'payment_reference' => $reference,
            'normalized_status' => $newStatus,
            'verification_result' => 'valid',
        ]);
    }

    /**
     * A payment that must not be booked. The marker (late_superseded_payment_at) is only the "needs refund" flag and is set once;
     * the audit is written for every distinct gateway reference (the order id when there is none), so a second late payment
     * is not lost behind the first and a redelivery of the same callback is not audited twice. Marker and audit commit together:
     * if the audit fails the redelivery must find both unset.
     *
     * @param  array<string, mixed>  $meta
     */
    private function markLatePayment(Transaction $bill, string $event, string $description, array $meta, ?string $reference): void
    {
        $meta = [...$meta, 'reference' => $reference];
        $key = filled($reference) ? $reference : (string) ($meta['order_id'] ?? '');

        [$marked, $audited] = DB::transaction(function () use ($bill, $event, $description, $meta, $key) {
            $marked = (bool) Transaction::whereKey($bill->id)->whereNull('late_superseded_payment_at')->update(['late_superseded_payment_at' => now()]);

            $seen = AuditLog::where('event', $event)
                ->where('auditable_type', $bill->getMorphClass())
                ->where('auditable_id', $bill->id)
                ->get()
                ->contains(fn (AuditLog $log) => (filled($log->meta['reference'] ?? null) ? $log->meta['reference'] : ($log->meta['order_id'] ?? null)) === $key);

            if (! $seen) {
                app(AuditLogService::class)->log(event: $event, module: 'transactions', auditable: $bill, description: $description, meta: $meta);
            }

            return [$marked, ! $seen];
        });

        $gateway = (string) $meta['gateway'];
        Log::warning(ucfirst($gateway).' Webhook: late payment not booked', [
            'provider' => $gateway,
            'order_id' => $meta['order_id'] ?? null,
            'payment_attempt' => $bill->payment_attempt,
            'late_paid_marked' => $marked,
            'late_paid_audited' => $audited,
        ]);
    }

    private function attemptOf(string $providerId): int
    {
        return preg_match('/-r(\d+)$/', $providerId, $matches) ? (int) $matches[1] : 0;
    }

    private function baseInvoice(string $providerId): string
    {
        $providerId = preg_replace('/-r\d+$/', '', $providerId);

        foreach (TransactionTenderService::GATEWAY_METHODS as $method) {
            $suffix = '-'.$method;
            if (str_ends_with($providerId, $suffix)) {
                return substr($providerId, 0, -strlen($suffix));
            }
        }

        return $providerId;
    }
}
