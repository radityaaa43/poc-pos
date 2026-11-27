<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\Outlet;
use App\Models\SalesReturn;
use App\Models\ShiftCashMovement;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierShiftService
{
    public function __construct(private readonly OutletAccessService $outletAccessService) {}

    public function getActiveShiftForUser(int $userId): ?CashierShift
    {
        return CashierShift::query()
            ->with(['user:id,name', 'openedBy:id,name'])
            ->open()
            ->where('user_id', $userId)
            ->latest('opened_at')
            ->first();
    }

    public function requireActiveShiftForUser(int $userId, bool $lockForUpdate = false): CashierShift
    {
        $query = CashierShift::query()
            ->open()
            ->where('user_id', $userId)
            ->latest('opened_at');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $shift = $query->first();

        if (! $shift) {
            throw ValidationException::withMessages([
                'shift' => 'Shift kasir belum dibuka.',
            ]);
        }

        return $shift;
    }

    public function openShift(User $cashier, User $actor, int $openingCash, ?string $notes = null, ?int $warehouseId = null): CashierShift
    {
        $existing = CashierShift::query()
            ->open()
            ->where('user_id', $cashier->id)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'opening_cash' => 'Kasir ini masih memiliki shift aktif.',
            ]);
        }

        return CashierShift::create([
            'user_id' => $cashier->id,
            'opened_by' => $actor->id,
            'opened_at' => now(),
            'opening_cash' => $openingCash,
            'expected_cash' => $openingCash,
            'notes' => $notes,
            'warehouse_id' => $warehouseId,
            'status' => CashierShift::STATUS_OPEN,
        ]);
    }

    /**
     * Does a recap taken while a bill is unpaid already hold it? An unpaid bill (Belum Lunas) and an unpaid gateway sale are left out
     * (calculateSummary), so once such a bill is paid after its shift closed, the closed recap lacks it and the payment is
     * flagged "paid after close". A credit, split, transfer or cash sale was counted by its method.
     * Pass the bill's payment_method as it was before the payment.
     */
    public static function countedInClosedRecap(?string $paymentMethod): bool
    {
        return $paymentMethod !== Transaction::METHOD_OPEN_BILL
            && ! in_array($paymentMethod, TransactionTenderService::GATEWAY_METHODS, true);
    }

    public function calculateSummary(CashierShift $shift): array
    {
        $transactions = Transaction::query()
            ->where('cashier_shift_id', $shift->id);

        $salesReturns = SalesReturn::query()
            ->where('cashier_shift_id', $shift->id)
            ->where('status', 'completed');

        $cashSalesTotal = (int) (clone $transactions)
            ->where('payment_method', 'cash')
            ->where('payment_status', 'paid')
            ->sum('grand_total');

        // Split payments: cash tender portions must count toward the drawer,
        // the parent row's payment_method ('split') puts grand_total in the non-cash bucket.
        // A split waiting for its transfer is pending, but its cash part is already in the drawer.
        $splitCashTenderTotal = (int) TransactionTender::query()
            ->whereHas('transaction', fn ($q) => $q
                ->where('cashier_shift_id', $shift->id)
                ->where('payment_method', 'split')
                ->whereIn('payment_status', ['paid', 'pending']))
            ->where('method', TransactionTender::METHOD_CASH)
            ->sum('amount');

        $cashSalesTotal += $splitCashTenderTotal;

        // Only money received counts: an unpaid bill (Belum Lunas) has taken none yet, and a gateway sale counts once it is paid (see countedInClosedRecap).
        $nonCashSalesTotal = (int) (clone $transactions)
            ->whereNotIn('payment_method', ['cash', Transaction::METHOD_OPEN_BILL])
            ->where(fn ($sales) => $sales
                ->whereNotIn('payment_method', TransactionTenderService::GATEWAY_METHODS)
                ->orWhere('payment_status', 'paid'))
            ->sum('grand_total');

        // Split payments: non-cash portion = grand_total minus cash tenders.
        $nonCashSalesTotal -= $splitCashTenderTotal;

        // A return completed before an open bill (pay-later or dine-in) was paid refunded nothing: that part was never
        // received, so it comes off the bucket the sale is counted in. Any other sale was asked for its grand total.
        // shortcut: a return completed while the bill's charge or transfer was already waiting comes off too, although the
        // full amount may still arrive; telling them apart needs the time the money was asked, when one is stored.
        $neverReceived = fn ($sales) => (int) SalesReturn::query()
            ->where('status', 'completed')
            ->where('refund_amount', 0)
            ->where('credited_amount', 0)
            ->whereIn('transaction_id', $sales
                ->where(fn ($openBills) => $openBills->where('is_pay_later', true)->orWhereHas('dineOrder'))
                ->doesntHave('receivable')
                ->select('id'))
            ->sum('total_return_amount');

        $cashSalesTotal -= $neverReceived((clone $transactions)
            ->where('payment_method', 'cash')
            ->where('payment_status', 'paid'));
        $nonCashSalesTotal -= $neverReceived((clone $transactions)
            ->whereNotIn('payment_method', ['cash', Transaction::METHOD_OPEN_BILL])
            ->where(fn ($sales) => $sales
                ->whereNotIn('payment_method', TransactionTenderService::GATEWAY_METHODS)
                ->orWhere('payment_status', 'paid')));

        $cashRefundTotal = (int) (clone $salesReturns)
            ->where('return_type', 'refund_cash')
            ->sum('refund_amount');

        $nonCashRefundTotal = (int) (clone $salesReturns)
            ->where('return_type', '!=', 'refund_cash')
            ->sum(DB::raw('COALESCE(credited_amount, 0)'));

        $transactionsCount = (int) (clone $transactions)->count();
        $salesReturnsCount = (int) (clone $salesReturns)->count();

        $cashInTotal = (int) $shift->cashMovements()
            ->where('type', ShiftCashMovement::TYPE_IN)
            ->sum('amount');
        $cashOutTotal = (int) $shift->cashMovements()
            ->where('type', ShiftCashMovement::TYPE_OUT)
            ->sum('amount');

        $expectedCash = (int) $shift->opening_cash + $cashSalesTotal - $cashRefundTotal + $cashInTotal - $cashOutTotal;

        return [
            'cash_sales_total' => $cashSalesTotal,
            'non_cash_sales_total' => $nonCashSalesTotal,
            'cash_refund_total' => $cashRefundTotal,
            'non_cash_refund_total' => $nonCashRefundTotal,
            'cash_in_total' => $cashInTotal,
            'cash_out_total' => $cashOutTotal,
            'transactions_count' => $transactionsCount,
            'sales_returns_count' => $salesReturnsCount,
            'expected_cash' => $expectedCash,
        ];
    }

    public function recordCashMovement(CashierShift $shift, User $actor, string $type, int $amount, ?string $note = null): ShiftCashMovement
    {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages([
                'shift' => 'Shift sudah ditutup, tidak dapat mencatat pergerakan kas.',
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => 'Nominal harus lebih besar dari nol.',
            ]);
        }

        if (! in_array($type, [ShiftCashMovement::TYPE_IN, ShiftCashMovement::TYPE_OUT], true)) {
            throw ValidationException::withMessages([
                'type' => 'Tipe pergerakan kas tidak valid.',
            ]);
        }

        return ShiftCashMovement::create([
            'cashier_shift_id' => $shift->id,
            'type' => $type,
            'amount' => $amount,
            'note' => $note,
            'user_id' => $actor->id,
        ]);
    }

    public function closeShift(
        CashierShift $shift,
        User $actor,
        int $actualCash,
        ?string $closeNotes = null,
        bool $forceClose = false
    ): CashierShift {
        if (! $shift->isOpen()) {
            throw ValidationException::withMessages([
                'shift' => 'Shift yang sudah ditutup tidak dapat diubah.',
            ]);
        }

        return DB::transaction(function () use ($shift, $actor, $actualCash, $closeNotes, $forceClose) {
            $lockedShift = CashierShift::query()->lockForUpdate()->findOrFail($shift->id);

            if (! $lockedShift->isOpen()) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift yang sudah ditutup tidak dapat diubah.',
                ]);
            }

            $summary = $this->calculateSummary($lockedShift);
            $cashDifference = $actualCash - $summary['expected_cash'];

            $lockedShift->update([
                'actual_cash' => $actualCash,
                'expected_cash' => $summary['expected_cash'],
                'cash_sales_total' => $summary['cash_sales_total'],
                'non_cash_sales_total' => $summary['non_cash_sales_total'],
                'cash_refund_total' => $summary['cash_refund_total'],
                'non_cash_refund_total' => $summary['non_cash_refund_total'],
                'transactions_count' => $summary['transactions_count'],
                'sales_returns_count' => $summary['sales_returns_count'],
                'cash_difference' => $cashDifference,
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'close_notes' => $closeNotes,
                'status' => $forceClose
                    ? CashierShift::STATUS_FORCE_CLOSED
                    : CashierShift::STATUS_CLOSED,
            ]);

            return $lockedShift->fresh(['user:id,name', 'openedBy:id,name', 'closedBy:id,name']);
        });
    }

    public function summarizeForDisplay(?CashierShift $shift): ?array
    {
        if (! $shift) {
            return null;
        }

        $summary = $this->calculateSummary($shift);

        return [
            'id' => $shift->id,
            'status' => $shift->status,
            'opening_cash' => (int) $shift->opening_cash,
            'opened_at' => optional($shift->opened_at)?->toISOString(),
            'notes' => $shift->notes,
            'warehouse' => $shift->warehouse ? [
                'id' => $shift->warehouse->id,
                'code' => $shift->warehouse->code,
                'name' => $shift->warehouse->name,
            ] : null,
            'user' => $shift->user ? [
                'id' => $shift->user->id,
                'name' => $shift->user->name,
            ] : null,
            ...$summary,
        ];
    }

    public function visibleToUser(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->can('cashier-shifts-force-close')) {
            if (Outlet::active()->count() <= 1) {
                return $query;
            }

            $warehouseIds = $this->outletAccessService->warehousesFor($user)->pluck('id')->all();

            return $query->where(function (Builder $query) use ($user, $warehouseIds) {
                $query->where('user_id', $user->id);
                if ($warehouseIds !== []) {
                    $query->orWhereIn('warehouse_id', $warehouseIds);
                }
            });
        }

        return $query->where('user_id', $user->id);
    }
}
