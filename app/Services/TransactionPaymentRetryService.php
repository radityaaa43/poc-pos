<?php

namespace App\Services;

use App\Exceptions\PaymentGatewayException;
use App\Models\PaymentSetting;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        // A dine-in bill is paid through its open bill (dine-orders.pay), which already settles failed bills.
        // A credit sale is collected through its receivable: a retry would charge it a second time.
        return in_array($transaction->payment_status, self::RETRYABLE_STATUSES, true)
            && ! $transaction->dineOrder()->exists()
            && ! $transaction->receivable()->exists()
            && ! $transaction->wasRestocked();
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

        [$sale, $total] = DB::transaction(function () use ($transaction, $user, $input, $method) {
            // Lock order shift, then sale (the webhook and the cashier's payment take them the same way), so no cycle:
            // the sale is read only after both locks, never before.
            $shift = $this->cashierShiftService->requireActiveShiftForUser($user->id, lockForUpdate: true);
            $sale = Transaction::with('warehouse.outlet')->lockForUpdate()->findOrFail($transaction->id);

            if (! in_array($sale->payment_status, self::RETRYABLE_STATUSES, true)) {
                throw ValidationException::withMessages(['payment' => 'Pembayaran ini tidak bisa diulang']);
            }

            if ($sale->dineOrder()->exists()) {
                throw ValidationException::withMessages(['payment' => 'Bayar lewat daftar Belum Lunas di POS']);
            }

            if ($sale->receivable()->exists()) {
                throw ValidationException::withMessages(['payment' => 'Penjualan kredit dibayar lewat piutang']);
            }

            if ($sale->wasRestocked()) {
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

            $shift->load('warehouse.outlet');
            $outlet = $shift->warehouse?->outlet;

            if ($sale->warehouse?->outlet_id !== $outlet?->id) {
                throw ValidationException::withMessages(['payment' => 'Buka shift di outlet penjualan ini']);
            }

            $total = $sale->amountDue(); // failed or expired: what is still owed

            if (! Transaction::whereKey($sale->id)->notFullyReturned()->exists() || $total < 1) {
                throw ValidationException::withMessages(['payment' => 'Penjualan ini sudah diretur penuh, tidak ada yang perlu dibayar.']);
            }

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

            return [$sale, $total];
        });

        // The charge goes out only after the new attempt is committed, so a webhook can never beat the attempt number.
        if (in_array($method, TransactionTenderService::GATEWAY_METHODS, true)) {
            $this->charge($sale, $method, $total);
        }

        return $sale;
    }

    private function charge(Transaction $sale, string $method, int $amount): void
    {
        $setting = PaymentSetting::forOutlet($sale->warehouse?->outlet);
        $orderId = "{$sale->invoice}-r{$sale->payment_attempt}";

        try {
            $charge = $method === TransactionTender::METHOD_QRIS
                ? $this->gatewayManager->createQrisPayment($sale, $setting, $amount, $orderId)
                : $this->gatewayManager->createPayment($sale, $method, $setting, $amount, $orderId);
        } catch (Throwable $exception) {
            // Any failure (also a timeout): a pending sale without a QR is not retryable, so it must end failed.
            $sale->update(['payment_status' => 'failed']);

            if ($exception instanceof PaymentGatewayException) {
                throw $exception;
            }

            report($exception);

            throw new PaymentGatewayException('Gagal menghubungi gateway pembayaran. Coba lagi.', previous: $exception);
        }

        $sale->update([
            'payment_reference' => $charge['reference'] ?? null,
            'payment_url' => $charge['payment_url'] ?? null,
            'qr_string' => $charge['qr_string'] ?? null,
        ]);
    }
}
