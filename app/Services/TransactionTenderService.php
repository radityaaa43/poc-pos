<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\Transaction;
use App\Models\TransactionTender;
use Illuminate\Support\Facades\DB;
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
     * tender (a gateway) stays pending. Under the sale's row lock, because a shift close or the expiry command may have
     * cancelled it since the page was read.
     */
    public function confirmTransfer(Transaction $transaction): string
    {
        return DB::transaction(function () use ($transaction) {
            // The dine order first, as in a shift close: marking the bill paid may complete it.
            DineOrder::where('transaction_id', $transaction->id)->lockForUpdate()->first();
            $status = Transaction::whereKey($transaction->id)->lockForUpdate()->value('payment_status');

            if (! in_array($status, ['pending', 'pending_approval'], true)) {
                throw ValidationException::withMessages(['payment' => 'Status transaksi tidak dapat dikonfirmasi saat ini.']);
            }

            // What this confirmation collects, read before it does: what the sale was asked (a return recorded while it
            // waited changes nothing, #405) less the parts already received.
            $owed = max(0, $transaction->amountDue()
                - (int) $transaction->tenders()->where('payment_status', TransactionTender::STATUS_PAID)->sum('amount'));

            $transaction->tenders()
                ->where('method', TransactionTender::METHOD_BANK_TRANSFER)
                ->update(['payment_status' => TransactionTender::STATUS_PAID]);

            $tenders = $transaction->tenders()->get();
            $newStatus = $tenders->isEmpty() ? 'paid' : $this->aggregateStatus($tenders);

            // A plain read after the sale's lock: a close that held the sale has committed and is seen; one still waiting
            // for it counts this payment in its recap.
            $this->flagPaidAfterClose($transaction, CashierShift::find($transaction->cashier_shift_id), $newStatus, $owed);
            $transaction->update(['payment_status' => $newStatus]);

            return $newStatus;
        });
    }

    /**
     * Money the closed recap of `$shift`, the shift that owned the sale, lacks and that arrives by a later confirmation or
     * settlement is marked "Dibayar setelah shift ditutup", as a gateway payment is: a split's unreceived part, a pay-later
     * sale without a receivable (#396), or a bill a forced close booked as a shortfall (REQ-003 BR-16). When `$shift` is
     * open, a booked pay-later sale is still owed to the close that booked it. Sets the mark on `$transaction`; the
     * caller saves it.
     *
     * @param  string|null  $methodBefore  see CashierShiftService::countedInClosedRecap
     */
    public function flagPaidAfterClose(
        Transaction $transaction,
        ?CashierShift $shift,
        string $newStatus,
        int $owed,
        ?string $methodBefore = null,
    ): void {
        if ($newStatus !== 'paid') {
            return;
        }

        if (! $shift || $shift->isOpen()) {
            $shift = CashierShiftService::forcedCloseThatBooked($transaction);
        }

        if (! $shift) {
            return;
        }

        $booked = $shift->unpaid_bills_total > 0
            && ($transaction->is_pay_later || $transaction->dineOrder()->exists())
            && ! $transaction->receivable()->exists();

        if (! $booked && CashierShiftService::countedInClosedRecap($transaction, $methodBefore)) {
            return;
        }

        $transaction->paid_after_close_at = now();

        app(AuditLogService::class)->log(
            event: 'payment.paid_after_close',
            module: 'transactions',
            auditable: $transaction,
            description: "Invoice {$transaction->invoice} dibayar setelah shift ditutup; rekap shift tidak berubah.",
            meta: ['shift_id' => $shift->id, 'invoice' => $transaction->invoice, 'amount' => $owed],
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
