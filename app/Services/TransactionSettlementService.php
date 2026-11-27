<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Takes payment for an open bill: the one money path shared by dine-in (DineOrderService::pay)
 * and a POS pay-later bill settled at the counter (settle).
 *
 * Two steps: claim() writes the payment under the caller's row locks; charge() talks to the gateway after that
 * commit, so a slow gateway never holds a lock or an open transaction.
 *
 * @phpstan-type Claim array{transaction: Transaction, cashier: User, outlet: ?Outlet, tender_ids: array<int, int|string>, gateway_tender: ?TransactionTender, before: array<string, mixed>}
 */
class TransactionSettlementService
{
    /** What a failed charge puts back as the claim found it (the status and its reason have their own rule). */
    private const RESTORED = ['cashier_id', 'cashier_shift_id', 'cash', 'change', 'payment_method', 'bank_account_id'];

    public function __construct(
        private CashierShiftService $cashierShiftService,
        private TransactionTenderService $tenderService,
        private PaymentGatewayManager $gatewayManager,
        private AuditLogService $auditLogService,
    ) {}

    /**
     * Settle a guest pay-later bill in full, with any method, in the paying cashier's shift.
     * Stock and the kitchen item were taken when the bill was made, so only the payment is recorded.
     *
     * @param  array<int, array<string, mixed>>  $tenderInput
     */
    public function settle(Transaction $transaction, User $cashier, array $tenderInput): Transaction
    {
        $claim = DB::transaction(function () use ($transaction, $cashier, $tenderInput) {
            $shift = $this->cashierShiftService->requireActiveShiftForUser($cashier->id, lockForUpdate: true);

            // First cashier wins: the second one waits on this lock, then finds the bill paid or claimed.
            $transaction = Transaction::with('warehouse')->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            // No plain read before the bill lock: on MySQL it would fix the snapshot, and the tender checks would then
            // miss a claim committed while this request waited.
            $shift->load('warehouse.outlet');
            $outlet = $shift->warehouse?->outlet;

            if ($transaction->payment_status === 'paid') {
                throw ValidationException::withMessages(['bill' => 'Tagihan ini sudah dilunasi.']);
            }

            // A pending gateway payment or transfer already holds the bill: no second charge or tender, finish that one.
            // An abandoned claim does not: it is replaced.
            if ($transaction->payment_status === 'pending' && ! $transaction->tenders()->abandonedClaim()->exists()) {
                throw ValidationException::withMessages([
                    'bill' => 'Tagihan ini sedang menunggu pembayaran (gateway atau konfirmasi transfer). Selesaikan pembayaran itu dulu, lihat QR di struk.',
                ]);
            }

            // Only an unpaid, failed or expired bill can be settled (a pending one still here is an abandoned claim).
            if (! in_array($transaction->payment_status, [...Transaction::OPEN_BILL_PAYABLE, 'pending'], true)) {
                throw ValidationException::withMessages(['bill' => 'Tagihan ini tidak bisa dilunasi di sini.']);
            }

            // Credit bills go through the receivable flow, dine bills through dine-orders.pay.
            if (! $transaction->is_pay_later || $transaction->receivable()->exists() || $transaction->dineOrder()->exists()) {
                throw ValidationException::withMessages(['bill' => 'Tagihan ini tidak bisa dilunasi di sini.']);
            }

            if (! Transaction::whereKey($transaction->id)->notFullyReturned()->exists() || $transaction->remainingTotal() < 1) {
                throw ValidationException::withMessages(['bill' => 'Penjualan ini sudah diretur penuh, tidak ada yang perlu dibayar.']);
            }

            if ($transaction->warehouse?->outlet_id !== $outlet?->id) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift kasir harus berada di outlet yang sama dengan pesanan.',
                ]);
            }

            return $this->claim($transaction, $cashier, $shift, $outlet, $tenderInput, $transaction->remainingTotal());
        });

        return $this->charge($claim);
    }

    /**
     * Record the tenders on a bill the caller has locked and checked, and claim any gateway charge: the committed
     * pending bill and its tender refuse a second payment while charge() talks to the gateway.
     * Runs inside the caller's DB::transaction, with no network call.
     * $expectedTotal is what the tenders must add up to (default: the grand total).
     *
     * @param  array<int, array<string, mixed>>  $tenderInput
     * @return Claim
     */
    public function claim(Transaction $transaction, User $cashier, CashierShift $shift, ?Outlet $outlet, array $tenderInput, ?int $expectedTotal = null): array
    {
        $tenders = $this->tenderService->normalize($tenderInput, $expectedTotal ?? (int) $transaction->grand_total, allowEmpty: false, outlet: $outlet);
        $gatewayMethods = collect($tenders)->pluck('method')->intersect(TransactionTenderService::GATEWAY_METHODS);

        // shortcut: a gateway tender cannot share the bill with another tender, so a failed or expired one can be replaced without losing a paid part; allow split when someone needs it
        if ($gatewayMethods->isNotEmpty() && count($tenders) > 1) {
            throw ValidationException::withMessages([
                'tenders' => 'Pembayaran gateway tidak dapat digabung dengan metode lain pada tagihan dine-in.',
            ]);
        }

        $before = $transaction->only([...self::RESTORED, 'payment_status', 'payment_failure_reason', 'payment_attempt', 'payment_reference', 'payment_url']);

        // A failed or expired gateway attempt leaves its tender behind; the new attempt replaces it (it never held money).
        $transaction->tenders()->delete();
        $tenderModels = $transaction->tenders()->createMany($tenders);

        $transaction->update([
            'cashier_id' => $cashier->id,
            'cashier_shift_id' => $shift->id,
            'cash' => (int) collect($tenders)->sum('cash_received'),
            'change' => (int) collect($tenders)->sum('change'),
            'payment_method' => count($tenders) > 1 ? 'split' : $tenders[0]['method'],
            'payment_status' => $this->tenderService->aggregateStatus($tenders),
            'bank_account_id' => collect($tenders)->firstWhere('bank_account_id', '!==', null)['bank_account_id'] ?? null,
            'payment_reference' => null,
            'payment_url' => null,
            'qr_string' => null,
            // Every gateway charge is its own attempt, so its own order id: one that timed out may still exist at the
            // gateway. A bill with a payment history (a guest attempt, a retry) moves its counter on any settlement: an
            // older charge paid afterwards is then a late payment, never booked twice. Cash at attempt 0 leaves it.
            ...($gatewayMethods->isNotEmpty() || $transaction->payment_attempt > 0
                ? ['payment_attempt' => $transaction->payment_attempt + 1, 'payment_failure_reason' => null]
                : []),
        ]);

        return [
            'transaction' => $transaction,
            'cashier' => $cashier,
            'outlet' => $outlet,
            'tender_ids' => $tenderModels->modelKeys(),
            'gateway_tender' => $tenderModels->whereIn('method', TransactionTenderService::GATEWAY_METHODS)->first(),
            'before' => $before,
        ];
    }

    /**
     * Create the claim's gateway charge, after the claim committed and with no transaction open, then store it only
     * while the claim is still the bill's current attempt. A claim without a gateway tender is already complete.
     *
     * @param  Claim  $claim
     *
     * @throws ValidationException tenders: the charge failed and the bill is payable again
     */
    public function charge(array $claim): Transaction
    {
        ['transaction' => $transaction, 'gateway_tender' => $tender] = $claim;

        if (! $tender) {
            return $transaction;
        }

        $attempt = (int) $transaction->payment_attempt;
        // Read before the call: a newer claim changes the bill's tenders, and this id is derived from them.
        $orderId = $tender->setRelation('transaction', $transaction)->order_reference;
        $context = ['transaction_id' => $transaction->id, 'attempt' => $attempt, 'method' => $tender->method];
        Log::info('Dine cashier charge started', $context);

        try {
            $charge = $this->gatewayManager->createTenderPayment($transaction, $tender, PaymentSetting::forOutlet($claim['outlet']));
        } catch (Throwable $exception) {
            // Any failure counts, a timeout too: a pending bill without a QR could not be paid.
            $this->restore($claim, $exception);
        }

        $values = ['payment_reference' => $charge['reference'], 'payment_url' => $charge['payment_url'], 'qr_string' => $charge['qr_string']];

        DB::transaction(function () use ($transaction, $tender, $attempt, $values, $context, $orderId) {
            if (! Transaction::storeChargeResult($transaction->id, $attempt, $values)) {
                // The charge exists at the gateway but the bill has moved on (a webhook, or a newer claim).
                Log::warning('Dine cashier charge not stored', [...$context, 'reference' => $values['payment_reference'], 'order_id' => $orderId]);

                return;
            }

            $tender->update($values);
        });

        return $transaction->refresh();
    }

    /**
     * The charge failed: put the bill back as the claim found it, guarded like the store, and delete only the claim's
     * own tenders (the status is never derived from other tenders). The attempt stays: the gateway may know its order id.
     *
     * @param  Claim  $claim
     *
     * @throws ValidationException
     */
    private function restore(array $claim, Throwable $exception): never
    {
        ['transaction' => $transaction, 'gateway_tender' => $tender, 'before' => $before] = $claim;
        $refused = $exception instanceof PaymentGatewayException;

        if (! $refused) {
            report($exception);
        }

        // A bill found pending held the guest's charge (a takeover), which is superseded now: failed, so payable.
        $status = $before['payment_status'] === 'pending'
            ? ['payment_status' => 'failed', 'payment_failure_reason' => 'gateway_error']
            : Arr::only($before, ['payment_status', 'payment_failure_reason']);
        $meta = ['attempt' => (int) $transaction->payment_attempt, 'method' => $tender?->method, 'exception' => $exception::class];

        DB::transaction(function () use ($claim, $transaction, $before, $status, $meta) {
            // The re-check below may book a payment: take the webhook's locks, in its order (shift, dine order, bill).
            $shift = $before['cashier_shift_id'] ? CashierShift::whereKey($before['cashier_shift_id'])->sharedLock()->first() : null;
            DineOrder::where('transaction_id', $transaction->id)->lockForUpdate()->value('id');

            $restored = Transaction::storeChargeResult($transaction->id, $meta['attempt'], [
                ...Arr::only($before, self::RESTORED),
                ...$status,
                'payment_reference' => null,
                'payment_url' => null,
                'qr_string' => null,
            ]);

            if ($restored) {
                $transaction->tenders()->whereKey($claim['tender_ids'])->delete();
            }

            // No exception message in the log or the audit: a gateway answer may echo request data.
            Log::warning(
                $restored ? 'Dine cashier charge failed, claim restored' : 'Dine cashier charge failed, claim no longer current',
                ['transaction_id' => $transaction->id, ...$meta],
            );
            $this->auditLogService->log(
                event: 'transaction.gateway_charge_failed',
                module: 'transactions',
                auditable: $transaction,
                description: "Tagihan gateway invoice {$transaction->invoice} gagal dibuat.",
                meta: $meta,
                actor: $claim['cashier'],
            );

            if ($restored) {
                // BR-4: payments flagged because this charge was pending. A savepoint: a failure keeps the restore.
                rescue(fn () => DB::transaction(fn () => app(PaymentWebhookController::class)->recheckFlaggedPayments(
                    Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail(), $shift, 'cashier_charge_failed',
                )));
            }
        });

        throw ValidationException::withMessages([
            'tenders' => $refused ? $exception->getMessage() : 'Gagal menghubungi gateway pembayaran. Coba lagi.',
        ]);
    }
}
