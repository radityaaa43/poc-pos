<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Models\CashierShift;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes payment for an open bill: the one money path shared by dine-in (DineOrderService::pay)
 * and a POS pay-later bill settled at the counter (settle).
 */
class TransactionSettlementService
{
    public function __construct(
        private CashierShiftService $cashierShiftService,
        private TransactionTenderService $tenderService,
        private PaymentGatewayManager $gatewayManager,
    ) {}

    /**
     * Settle a guest pay-later bill in full, with any method, in the paying cashier's shift.
     * Stock and the kitchen item were taken when the bill was made, so only the payment is recorded.
     *
     * @param  array<int, array<string, mixed>>  $tenderInput
     */
    public function settle(Transaction $transaction, User $cashier, array $tenderInput): Transaction
    {
        return DB::transaction(function () use ($transaction, $cashier, $tenderInput) {
            $shift = $this->cashierShiftService->requireActiveShiftForUser($cashier->id, lockForUpdate: true);
            $shift->load('warehouse.outlet');
            $outlet = $shift->warehouse?->outlet;

            // First cashier wins: the second one waits on this lock, then finds the bill paid.
            $transaction = Transaction::with('warehouse')->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($transaction->payment_status === 'paid') {
                throw ValidationException::withMessages(['bill' => 'Tagihan ini sudah dilunasi.']);
            }

            // A pending gateway payment or transfer already holds the bill: no second charge or tender, finish that one.
            if ($transaction->payment_status === 'pending') {
                throw ValidationException::withMessages([
                    'bill' => 'Tagihan ini sedang menunggu pembayaran (gateway atau konfirmasi transfer). Selesaikan pembayaran itu dulu, lihat QR di struk.',
                ]);
            }

            // Only an unpaid, failed or expired bill can be settled (a pending one was refused above).
            if (! in_array($transaction->payment_status, Transaction::OPEN_BILL_PAYABLE, true)) {
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

            return $this->collect($transaction, $cashier, $shift, $outlet, $tenderInput, $transaction->remainingTotal());
        });
    }

    /**
     * Record the tenders on a bill the caller has locked and checked, and start any gateway charge.
     * Runs inside the caller's DB::transaction; a failed charge rolls the whole settlement back.
     * $expectedTotal is what the tenders must add up to (default: the grand total).
     *
     * @param  array<int, array<string, mixed>>  $tenderInput
     */
    public function collect(Transaction $transaction, User $cashier, CashierShift $shift, ?Outlet $outlet, array $tenderInput, ?int $expectedTotal = null): Transaction
    {
        $tenders = $this->tenderService->normalize($tenderInput, $expectedTotal ?? (int) $transaction->grand_total, allowEmpty: false, outlet: $outlet);
        $gatewayMethods = collect($tenders)->pluck('method')->intersect(TransactionTenderService::GATEWAY_METHODS);

        // shortcut: a gateway tender cannot share the bill with another tender, so a failed or expired one can be replaced without losing a paid part; allow split when someone needs it
        if ($gatewayMethods->isNotEmpty() && count($tenders) > 1) {
            throw ValidationException::withMessages([
                'tenders' => 'Pembayaran gateway tidak dapat digabung dengan metode lain pada tagihan dine-in.',
            ]);
        }

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
            // A bill with a payment history (a guest attempt, a retry) moves its counter on every settlement: an older
            // charge that is paid afterwards is then a late payment (marked, never booked twice), and a gateway charge
            // made here gets a fresh order id. Attempt 0 bills are written exactly as before.
            ...($transaction->payment_attempt > 0
                ? ['payment_attempt' => $transaction->payment_attempt + 1, 'payment_failure_reason' => null]
                : []),
        ]);

        foreach ($tenderModels->whereIn('method', TransactionTenderService::GATEWAY_METHODS) as $tender) {
            try {
                $charge = $this->gatewayManager->createTenderPayment($transaction, $tender, PaymentSetting::forOutlet($outlet));
            } catch (PaymentGatewayException $exception) {
                // rolls the whole settlement back: the bill stays unpaid and the cashier can pick another method
                throw ValidationException::withMessages(['tenders' => $exception->getMessage()]);
            }

            $tender->update(['payment_reference' => $charge['reference'], 'payment_url' => $charge['payment_url'], 'qr_string' => $charge['qr_string']]);
            $transaction->update(['payment_reference' => $charge['reference'], 'payment_url' => $charge['payment_url'], 'qr_string' => $charge['qr_string']]);
        }

        return $transaction;
    }
}
