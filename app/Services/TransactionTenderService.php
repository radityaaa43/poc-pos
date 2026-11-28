<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Models\TransactionTender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TransactionTenderService
{
    public const METHODS = [
        TransactionTender::METHOD_CASH,
        TransactionTender::METHOD_BANK_TRANSFER,
        TransactionTender::METHOD_MIDTRANS,
        TransactionTender::METHOD_XENDIT,
        TransactionTender::METHOD_QRIS,
    ];

    public const GATEWAY_METHODS = [
        TransactionTender::METHOD_MIDTRANS,
        TransactionTender::METHOD_XENDIT,
        TransactionTender::METHOD_QRIS,
    ];

    public function __construct(private readonly PaymentSetting $paymentSetting) {}

    /**
     * @param  array<int, array<string, mixed>>  $input
     * @param  array<int, array<string, mixed>>  $normalized
     */
    public function normalize(array $input, int $grandTotal, bool $allowEmpty = true, ?Outlet $outlet = null): array
    {
        if (empty($input)) {
            if ($allowEmpty) {
                return [];
            }

            throw ValidationException::withMessages([
                'tenders' => 'Metode pembayaran wajib diisi.',
            ]);
        }

        if (count($input) > 2) {
            throw ValidationException::withMessages([
                'tenders' => 'Maksimal dua metode pembayaran diperbolehkan.',
            ]);
        }

        $normalized = [];
        $gatewayCount = 0;
        $bankTransferCount = 0;

        foreach (array_values($input) as $index => $tender) {
            $method = strtolower(trim((string) ($tender['method'] ?? '')));

            if (! in_array($method, self::METHODS, true)) {
                throw ValidationException::withMessages([
                    "tenders.{$index}.method" => 'Metode pembayaran tidak valid.',
                ]);
            }

            if (in_array($method, self::GATEWAY_METHODS, true)) {
                $gatewayCount++;
            }

            if ($method === TransactionTender::METHOD_BANK_TRANSFER) {
                $bankTransferCount++;
            }

            $amount = (int) ($tender['amount'] ?? 0);

            if ($amount < 1) {
                throw ValidationException::withMessages([
                    "tenders.{$index}.amount" => 'Nominal pembayaran harus lebih besar dari nol.',
                ]);
            }

            $cashReceived = null;
            $change = 0;

            if ($method === TransactionTender::METHOD_CASH) {
                $cashReceived = isset($tender['cash_received'])
                    ? max(0, (int) $tender['cash_received'])
                    : $amount;

                if ($cashReceived < $amount) {
                    throw ValidationException::withMessages([
                        "tenders.{$index}.cash_received" => 'Uang tunai kurang dari nominal tender.',
                    ]);
                }

                $change = $cashReceived - $amount;
            } elseif (isset($tender['cash_received']) && (int) $tender['cash_received'] > 0) {
                throw ValidationException::withMessages([
                    "tenders.{$index}.cash_received" => 'Uang diterima hanya berlaku untuk pembayaran tunai.',
                ]);
            }

            $bankAccountId = null;

            if ($method === TransactionTender::METHOD_BANK_TRANSFER) {
                $bankAccountId = $tender['bank_account_id'] ?? null;

                $account = $bankAccountId
                    ? BankAccount::where('id', $bankAccountId)->active()->forOutlet($outlet)->first()
                    : null;

                if (! $account) {
                    throw ValidationException::withMessages([
                        "tenders.{$index}.bank_account_id" => 'Rekening tujuan transfer wajib dipilih.',
                    ]);
                }

                $bankAccountId = $account->id;
            }

            $normalized[] = [
                'method' => $method,
                'amount' => $amount,
                'cash_received' => $cashReceived,
                'change' => $change,
                'bank_account_id' => $bankAccountId,
                // A transfer waits for a user to confirm it (see confirmTransfer), like a single transfer.
                'payment_status' => $method === TransactionTender::METHOD_CASH
                    ? TransactionTender::STATUS_PAID
                    : TransactionTender::STATUS_PENDING,
            ];
        }

        if ($gatewayCount > 1) {
            throw ValidationException::withMessages([
                'tenders' => 'Hanya satu pembayaran gateway yang diperbolehkan per transaksi.',
            ]);
        }

        if ($bankTransferCount > 1) {
            throw ValidationException::withMessages([
                'tenders' => 'Hanya satu transfer bank yang diperbolehkan per transaksi.',
            ]);
        }

        $total = array_sum(array_column($normalized, 'amount'));

        if ($total !== $grandTotal) {
            throw ValidationException::withMessages([
                'tenders' => 'Jumlah pembayaran harus sama dengan total transaksi.',
            ]);
        }

        foreach ($normalized as $tender) {
            if (in_array($tender['method'], self::GATEWAY_METHODS, true)) {
                $gateway = $tender['method'] === TransactionTender::METHOD_QRIS
                     ? $this->resolveQrisGateway($outlet)
                    : $tender['method'];

                if (! $gateway || ! $this->setting($outlet)->isGatewayReady($gateway)) {
                    throw ValidationException::withMessages([
                        'tenders' => 'Gateway pembayaran belum dikonfigurasi.',
                    ]);
                }
            }
        }

        return $normalized;
    }

    /**
     * Aggregate tender statuses into a parent transaction status.
     *
     * @param  iterable<App\Models\TransactionTender>  $tenders
     */
    public function aggregateStatus(iterable $tenders): string
    {
        $statuses = [];

        foreach ($tenders as $tender) {
            $statuses[] = is_array($tender)
                ? ($tender['payment_status'] ?? null)
                : $tender->payment_status;
        }

        if (in_array(TransactionTender::STATUS_FAILED, $statuses, true)) {
            return 'failed';
        }

        if (in_array(TransactionTender::STATUS_PENDING, $statuses, true)) {
            return 'pending';
        }

        return 'paid';
    }

    public function hasTransfer(Transaction $transaction): bool
    {
        return $transaction->payment_method === TransactionTender::METHOD_BANK_TRANSFER
            || $transaction->tenders()->where('method', TransactionTender::METHOD_BANK_TRANSFER)->exists();
    }

    /**
     * Mark the transfer tender paid, then store and return the sale's new status: a split sale with another pending
     * tender (a gateway) stays pending. Under the sale's row lock, because a shift close, the expiry command or a return
     * may have changed it since the page was read. Locks in the webhook's order
     * (PaymentWebhookController::applyNotification): shift (shared), dine order (marking the bill paid may complete it),
     * receivable, sale.
     */
    public function confirmTransfer(Transaction $transaction): string
    {
        return DB::transaction(function () use ($transaction) {
            CashierShift::whereKey($transaction->cashier_shift_id)->sharedLock()->value('id');
            DineOrder::where('transaction_id', $transaction->id)->lockForUpdate()->first();
            Receivable::where('transaction_id', $transaction->id)->lockForUpdate()->value('id'); // no row is fine
            $bill = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            // A sale that moved to another shift meanwhile is refused too: that shift is not the one locked.
            if (! in_array($bill->payment_status, ['pending', 'pending_approval'], true)
                || $bill->cashier_shift_id !== $transaction->cashier_shift_id) {
                throw ValidationException::withMessages(['payment' => 'Status transaksi tidak dapat dikonfirmasi saat ini.']);
            }

            $transaction->tenders()
                ->where('method', TransactionTender::METHOD_BANK_TRANSFER)
                ->update(['payment_status' => TransactionTender::STATUS_PAID]);

            $tenders = $transaction->tenders()->get();
            $newStatus = $tenders->isEmpty() ? 'paid' : $this->aggregateStatus($tenders);

            $this->flagIfBookedAtClose($transaction, $newStatus);
            $transaction->update(['payment_status' => $newStatus]);

            if ($newStatus === 'paid') {
                // Without tenders, the transfer asked the grand total at checkout, or what a retry asked then.
                $asked = $bill->payment_attempt
                    ? AuditLog::where('event', 'transaction.payment_retried')
                        ->where('auditable_type', $bill->getMorphClass())
                        ->where('auditable_id', $bill->id)
                        ->latest('id')
                        ->first()?->meta['amount'] ?? null
                    : (int) $bill->grand_total;

                $this->flagOverpayment($bill, $asked, ['source' => 'transfer']);
            }

            return $newStatus;
        });
    }

    /**
     * Money taken beyond what a sale owes after its returns (#444): kept in overpaid_amount, which the shift recap leaves
     * out, and marked "Bayar ganda — refund manual". A credit sale is left to its receivable. Call it with the sale
     * locked, from the payment that has just made it paid: a repeat of that payment is refused or ignored before this.
     *
     * @param  ?int  $untendered  what a sale without tenders was paid; null when unknown, then nothing is flagged
     * @param  array<string, string>  $meta  the source (gateway or transfer) and the gateway order id
     */
    public function flagOverpayment(Transaction $bill, ?int $untendered, array $meta): void
    {
        if ($bill->receivable()->exists()) {
            return;
        }

        $tenders = $bill->tenders()->get();
        $settled = $tenders->isEmpty()
            ? $untendered
            : (int) $tenders->where('payment_status', TransactionTender::STATUS_PAID)->sum('amount');
        $owed = $bill->owedAfterReturns();

        if ($settled === null || $settled <= $owed) {
            return;
        }

        $excess = $settled - $owed;
        $bill->update([
            'overpaid_amount' => $bill->overpaid_amount + $excess,
            'late_superseded_payment_at' => $bill->late_superseded_payment_at ?? now(),
        ]);

        app(AuditLogService::class)->log(
            event: 'payment.overpaid_after_return',
            module: 'transactions',
            auditable: $bill,
            description: "Pembayaran invoice {$bill->invoice} melebihi sisa tagihan setelah retur; kelebihan Rp ".number_format($excess, 0, ',', '.').' perlu refund manual.',
            meta: ['settled' => $settled, 'owed' => $owed, 'excess' => $excess, ...$meta],
        );

        Log::warning('Payment beyond what the sale owed after returns', ['invoice' => $bill->invoice, 'excess' => $excess]);
    }

    /**
     * A bill a forced close booked as a shortfall is in no recap of that shift (REQ-003 BR-16): paid by a later
     * confirmation it stays on the shift, so it is marked "Dibayar setelah shift ditutup", as a gateway payment is.
     */
    private function flagIfBookedAtClose(Transaction $transaction, string $newStatus): void
    {
        $shift = CashierShift::find($transaction->cashier_shift_id);

        if ($newStatus !== 'paid' || ! $shift || $shift->isOpen() || $shift->unpaid_bills_total === 0
            || (! $transaction->is_pay_later && ! $transaction->dineOrder()->exists())
            || $transaction->receivable()->exists()) {
            return;
        }

        $transaction->paid_after_close_at = now();

        app(AuditLogService::class)->log(
            event: 'payment.paid_after_close',
            module: 'transactions',
            auditable: $transaction,
            description: "Pembayaran invoice {$transaction->invoice} dikonfirmasi setelah shift ditutup.",
            meta: ['shift_id' => $shift->id, 'invoice' => $transaction->invoice],
        );
    }

    public function resolveQrisGateway(?Outlet $outlet = null): ?string
    {
        if ($this->setting($outlet)->isGatewayReady(PaymentSetting::GATEWAY_MIDTRANS)) {
            return PaymentSetting::GATEWAY_MIDTRANS;
        }

        if ($this->setting($outlet)->isGatewayReady(PaymentSetting::GATEWAY_XENDIT)) {
            return PaymentSetting::GATEWAY_XENDIT;
        }

        return null;
    }

    private function setting(?Outlet $outlet = null): PaymentSetting
    {
        return PaymentSetting::forOutlet($outlet) ?? $this->paymentSetting;
    }
}
