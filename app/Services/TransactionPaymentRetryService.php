<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Models\PaymentSetting;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Re-opens the payment of a POS sale whose online payment failed or expired, under the next payment attempt.
 * The cart, prices and stock stay as they were at sale; only the payment is redone.
 */
class TransactionPaymentRetryService
{
    public const RETRYABLE_STATUSES = ['failed', 'expired'];

    public function __construct(
        private readonly CashierShiftService $cashierShiftService,
        private readonly TransactionTenderService $tenderService,
        private readonly PaymentGatewayManager $gatewayManager,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function isRetryable(Transaction $transaction): bool
    {
        return in_array($transaction->payment_status, self::RETRYABLE_STATUSES, true) && ! $this->wasRestocked($transaction);
    }

    /** ExpirePendingTransactionsCommand returned the stock of this sale: it is cancelled for good. */
    public function wasRestocked(Transaction $transaction): bool
    {
        return StockMutation::where('reference_type', 'transaction_expire')->where('reference_id', $transaction->id)->exists();
    }

    /**
     * @param  array{payment_method: string, cash_amount?: int, bank_account_id?: int}  $input
     *
     * @throws ValidationException when the sale cannot be retried by this user
     * @throws PaymentGatewayException when the gateway refuses the new charge (the sale stays failed)
     */
    public function retry(Transaction $transaction, User $user, array $input): Transaction
    {
        $method = $input['payment_method'];

        $sale = DB::transaction(function () use ($transaction, $user, $input, $method) {
            $sale = Transaction::with('warehouse.outlet')->lockForUpdate()->findOrFail($transaction->id);

            if (! in_array($sale->payment_status, self::RETRYABLE_STATUSES, true)) {
                throw ValidationException::withMessages(['payment' => 'Pembayaran ini tidak bisa diulang']);
            }

            if ($this->wasRestocked($sale)) {
                throw ValidationException::withMessages([
                    'payment' => 'Penjualan ini sudah dibatalkan otomatis dan stoknya dikembalikan — buat penjualan baru.',
                ]);
            }

            // A split sale keeps its paid part; a retry replaces every tender, so it would lose that money.
            if ($sale->tenders()->where('payment_status', TransactionTender::STATUS_PAID)->exists()) {
                throw ValidationException::withMessages([
                    'payment' => 'Sebagian penjualan ini sudah dibayar (pembayaran terpisah), jadi tidak bisa diulang.',
                ]);
            }

            $shift = $this->cashierShiftService->requireActiveShiftForUser($user->id, lockForUpdate: true);
            $shift->load('warehouse.outlet');
            $outlet = $shift->warehouse?->outlet;

            if ($sale->warehouse?->outlet_id !== $outlet?->id) {
                throw ValidationException::withMessages(['payment' => 'Buka shift di outlet penjualan ini']);
            }

            $total = (int) $sale->grand_total;
            $tender = $this->tenderService->normalize([[
                'method' => $method,
                'amount' => $total,
                'cash_received' => $input['cash_amount'] ?? null,
                'bank_account_id' => $input['bank_account_id'] ?? null,
            ]], $total, allowEmpty: false, outlet: $outlet)[0];

            $before = ['payment_method' => $sale->payment_method, 'payment_status' => $sale->payment_status, 'payment_attempt' => $sale->payment_attempt];

            // A retry is a single method: the failed attempt's tenders never held money, so they are replaced.
            $sale->tenders()->delete();
            $sale->update([
                'payment_attempt' => $sale->payment_attempt + 1,
                'cashier_shift_id' => $shift->id,
                'payment_method' => $method,
                'payment_status' => $tender['payment_status'],
                'cash' => $tender['cash_received'] ?? 0,
                'change' => $tender['change'],
                'bank_account_id' => $tender['bank_account_id'],
                'payment_reference' => null,
                'payment_url' => null,
                'qr_string' => null,
            ]);

            $this->auditLogService->log(
                event: 'transaction.payment_retried',
                module: 'transactions',
                auditable: $sale,
                description: "Pembayaran invoice {$sale->invoice} diulang dengan metode {$method}.",
                before: $before,
                after: ['payment_method' => $sale->payment_method, 'payment_status' => $sale->payment_status, 'payment_attempt' => $sale->payment_attempt],
                meta: ['invoice' => $sale->invoice],
                actor: $user,
            );

            return $sale;
        });

        // The charge goes out only after the new attempt is committed, so a webhook can never beat the attempt number.
        if (in_array($method, TransactionTenderService::GATEWAY_METHODS, true)) {
            $this->charge($sale, $method);
        }

        return $sale;
    }

    private function charge(Transaction $sale, string $method): void
    {
        $setting = PaymentSetting::forOutlet($sale->warehouse?->outlet);
        $orderId = "{$sale->invoice}-r{$sale->payment_attempt}";

        try {
            $charge = $method === TransactionTender::METHOD_QRIS
                ? $this->gatewayManager->createQrisPayment($sale, $setting, null, $orderId)
                : $this->gatewayManager->createPayment($sale, $method, $setting, null, $orderId);
        } catch (PaymentGatewayException $exception) {
            $sale->update(['payment_status' => 'failed']);

            throw $exception;
        }

        $sale->update([
            'payment_reference' => $charge['reference'] ?? null,
            'payment_url' => $charge['payment_url'] ?? null,
            'qr_string' => $charge['qr_string'] ?? null,
        ]);
    }
}
