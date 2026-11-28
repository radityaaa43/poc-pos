<?php

namespace App\Services;

use App\Exceptions\ShiftCloseBlockedException;
use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\SalesReturn;
use App\Models\ShiftCashMovement;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierShiftService
{
    public function __construct(
        private readonly OutletAccessService $outletAccessService,
        private readonly ShiftCloseBlockerService $blockerService,
        private readonly UnpaidSaleCancellationService $cancellationService,
        private readonly KitchenItemService $kitchenItemService,
        private readonly KitchenIntakeService $kitchenIntakeService,
        private readonly AuditLogService $auditLogService,
    ) {}

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

    /** @param  array<int, int>  $bookedBillIds  unpaid bills a forced close books as a shortfall (see closeShift) */
    public function calculateSummary(CashierShift $shift, array $bookedBillIds = []): array
    {
        // A cancelled sale (expired, or unpaid at a shift close) is no sale: it leaves every total and the count.
        $transactions = Transaction::query()
            ->where('cashier_shift_id', $shift->id)
            ->notRestocked();

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
        $splitCashTenders = TransactionTender::query()
            ->whereHas('transaction', fn ($q) => $q
                ->where('cashier_shift_id', $shift->id)
                ->where('payment_method', 'split')
                ->whereIn('payment_status', ['paid', 'pending']))
            ->where('method', TransactionTender::METHOD_CASH);

        $cashSalesTotal += (int) (clone $splitCashTenders)->sum('amount');

        // Only money received counts: an unpaid bill (Belum Lunas) has taken none yet, and a gateway sale counts once it is paid (see countedInClosedRecap).
        $nonCashSalesTotal = (int) (clone $transactions)
            ->whereKeyNot($bookedBillIds)
            ->whereNotIn('payment_method', ['cash', Transaction::METHOD_OPEN_BILL])
            ->where(fn ($sales) => $sales
                ->whereNotIn('payment_method', TransactionTenderService::GATEWAY_METHODS)
                ->orWhere('payment_status', 'paid'))
            ->sum('grand_total');

        // Split payments: non-cash portion = grand_total minus cash tenders.
        $nonCashSalesTotal -= (int) (clone $splitCashTenders)->whereNotIn('transaction_id', $bookedBillIds)->sum('amount');

        // A booked bill is already a shortfall (BR-16): only the non-cash part it did receive counts here.
        $nonCashSalesTotal += (int) TransactionTender::query()
            ->whereIn('transaction_id', $bookedBillIds)
            ->where('method', '!=', TransactionTender::METHOD_CASH)
            ->where('payment_status', TransactionTender::STATUS_PAID)
            ->sum('amount');

        // What a sale was never asked for is not money received, so it comes off the bucket the sale is counted in. Only an
        // open bill (pay-later or dine-in) or a retried payment is asked for less than its grand total.
        $neverAsked = function ($sales) use ($bookedBillIds): int {
            $askedLater = $sales
                ->whereKeyNot($bookedBillIds) // booked by a forced close: not counted above, so nothing to take off
                ->where(fn ($later) => $later->where('is_pay_later', true)->orWhere('payment_attempt', '>', 0)->orWhereHas('dineOrder'))
                ->doesntHave('receivable');

            // A counter payment wrote tenders totalling what it asked, so a return completed later changes nothing here.
            $tenderedShort = (int) (clone $askedLater)->whereHas('tenders')->sum('grand_total')
                - (int) TransactionTender::query()->whereIn('transaction_id', (clone $askedLater)->select('id'))->sum('amount');

            // Without tenders (guest online, retry) the asked amount is the total less the returns that refunded nothing.
            // shortcut: a return made while a no-tender payment (guest online, retry) is waiting under-counts the recap;
            // exact fix = store the asked amount on the bill.
            $unrefunded = SalesReturn::query()
                ->where('status', 'completed')
                ->where('refund_amount', 0)
                ->where('credited_amount', 0)
                ->groupBy('transaction_id')
                ->selectRaw('transaction_id, sum(total_return_amount) as unrefunded_total');
            // Capped per sale: returns are priced before a bill discount, and a sale must not take off more than it added.
            $returnedShort = (int) (clone $askedLater)->doesntHave('tenders')
                ->joinSub($unrefunded, 'unrefunded', 'unrefunded.transaction_id', '=', 'transactions.id')
                ->sum(DB::raw('case when unrefunded_total > grand_total then grand_total else unrefunded_total end'));

            return $tenderedShort + $returnedShort;
        };

        $cashSalesTotal -= $neverAsked((clone $transactions)
            ->where('payment_method', 'cash')
            ->where('payment_status', 'paid'));
        $nonCashSalesTotal -= $neverAsked((clone $transactions)
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
        bool $forceClose = false,
        ?bool $overrideHold = null
    ): CashierShift {
        // Closing someone else's shift is a force close; overriding what holds it must still be asked for.
        $overrideHold ??= $forceClose;

        if (! $shift->isOpen()) {
            throw ValidationException::withMessages([
                'shift' => 'Shift yang sudah ditutup tidak dapat diubah.',
            ]);
        }

        // Outside the transaction, so a refused close keeps what it took in and the page lists it. A sale that cannot be
        // taken in is reported and left to the recovery command: it never blocks a close.
        $unrecoverable = $this->missedSales($shift)->reject(fn (Transaction $sale) => rescue(function () use ($sale) {
            $this->kitchenIntakeService->recover($sale);

            return true;
        }, false))->modelKeys();

        // Read before the transaction on purpose: a plain read inside it would fix MySQL's snapshot before the rows are locked.
        $saleIds = Transaction::where('cashier_shift_id', $shift->id)->pluck('id');

        return DB::transaction(function () use ($shift, $actor, $actualCash, $closeNotes, $forceClose, $overrideHold, $saleIds, $unrecoverable) {
            $lockedShift = CashierShift::query()->lockForUpdate()->findOrFail($shift->id);

            if (! $lockedShift->isOpen()) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift yang sudah ditutup tidak dapat diubah.',
                ]);
            }

            $this->lockOrders($lockedShift, $saleIds);

            // A checkout this close just waited for may not have reached the kitchen yet: its food holds the close too.
            $this->missedSales($lockedShift)->except($unrecoverable)
                ->each(fn (Transaction $sale) => $this->kitchenIntakeService->recover($sale));

            $blockers = $this->blockerService->blockersFor($lockedShift);
            $held = $blockers['items']->isNotEmpty() || $blockers['bills']->isNotEmpty();

            // AC-12: a held shift closes only by force, and then only with a note (AC-13).
            if ($held && ! $overrideHold) {
                throw ShiftCloseBlockedException::withBlockers($this->blockerService->rows($blockers));
            }

            if ($held && mb_strlen(trim((string) $closeNotes)) < 3) {
                throw ValidationException::withMessages([
                    'close_notes' => 'Catatan wajib diisi (minimal 3 huruf) untuk menutup paksa shift yang tertahan.',
                ]);
            }

            $cancelled = $this->cancelUnpaidPayNowSales($lockedShift, $actor);

            $unpaidBillsTotal = (int) $blockers['bills']->sum(fn (Transaction $bill) => $bill->unpaidAmount());
            $this->kitchenItemService->markNotCollected($blockers['items']);

            $summary = $this->calculateSummary($lockedShift, $blockers['bills']->modelKeys());
            // BR-9: the booked bills are money the shift should have and does not.
            $cashDifference = $actualCash - $summary['expected_cash'] - $unpaidBillsTotal;

            if ($held) {
                $this->auditLogService->log(
                    event: 'cashier_shift.hold_overridden',
                    module: 'cashier_shifts',
                    auditable: $lockedShift,
                    description: 'Shift ditutup paksa dengan item belum diserahkan atau tagihan belum lunas.',
                    meta: [
                        'note' => trim((string) $closeNotes),
                        'not_collected_item_ids' => $blockers['items']->modelKeys(),
                        'unpaid_bills' => $blockers['bills']->pluck('invoice')->all(),
                        'unpaid_bills_total' => $unpaidBillsTotal,
                        'cancelled_pay_now' => $cancelled,
                    ],
                    actor: $actor,
                );
            }

            $lockedShift->update([
                'unpaid_bills_total' => $unpaidBillsTotal,
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
        }, 3); // retried on a deadlock; nothing leaves the database inside
    }

    /**
     * Every writer takes these in one order (kitchen step, dine payment, webhook, return completion): shift, kitchen
     * items, dine orders, sales. Locking reads only, so every plain read after them sees the latest committed rows.
     *
     * shortcut: a sale committed between reading $saleIds and the shift lock has its kitchen rows locked after the
     * sale; the close's deadlock retry covers it.
     *
     * @param  Collection<int, int>  $saleIds
     */
    private function lockOrders(CashierShift $shift, Collection $saleIds): void
    {
        $lockKitchen = function (Collection $ids): void {
            KitchenItem::whereIn('transaction_id', $ids)->lockForUpdate()->get(['id']);
            DineOrder::whereIn('transaction_id', $ids)->lockForUpdate()->get(['id']);
        };
        $lockKitchen($saleIds);
        // A late arrival's rows are locked too, or a kitchen tap on it could be overwritten with "not collected".
        $locked = Transaction::where('cashier_shift_id', $shift->id)->orderByDesc('id')->lockForUpdate()->pluck('id'); // add-on before original
        $late = $locked->diff($saleIds);
        if ($late->isNotEmpty()) {
            $lockKitchen($late);
        }
    }

    /** @return EloquentCollection<int, Transaction> the shift's recent sales that never reached the kitchen */
    private function missedSales(CashierShift $shift): EloquentCollection
    {
        [$payNow, $payLater] = array_map(
            fn (Builder $missed) => $missed->where('cashier_shift_id', $shift->id)->get(),
            $this->kitchenIntakeService->missedSales(),
        );

        return $payNow->concat($payLater);
    }

    /**
     * AC-20: the shift's Bayar sekarang sales nobody paid are cancelled, restocked and audited. Call inside the close's
     * DB::transaction.
     *
     * @return array<int, string> the cancelled invoices
     */
    private function cancelUnpaidPayNowSales(CashierShift $shift, User $actor): array
    {
        $cancelled = [];

        // The close holds every sale of the shift locked, so these checks cannot go stale.
        foreach (Transaction::query()->unpaidPayNow()->where('cashier_shift_id', $shift->id)->orderBy('id')->get() as $sale) {
            $this->cancellationService->cancel(
                $sale,
                "Dibatalkan saat shift ditutup {$sale->invoice}",
                'Dibatalkan otomatis: belum dibayar saat shift ditutup.',
            );
            $sale->tenders()
                ->where('payment_status', TransactionTender::STATUS_PENDING)
                ->update(['payment_status' => TransactionTender::STATUS_FAILED]);

            $this->auditLogService->log(
                event: 'transaction.cancelled_on_shift_close',
                module: 'transactions',
                auditable: $sale,
                description: 'Pesanan Bayar sekarang dibatalkan otomatis saat shift ditutup.',
                meta: ['cashier_shift_id' => $shift->id, 'invoice' => $sale->invoice],
                actor: $actor,
            );

            $cancelled[] = $sale->invoice;
        }

        return $cancelled;
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
