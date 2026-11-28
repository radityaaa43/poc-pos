<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\Customer;
use App\Models\DiscountApprovalLog;
use App\Models\Outlet;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Services\AuditLogService;
use App\Services\BillTotalService;
use App\Services\LoyaltyService;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DiscountApprovalController extends Controller
{
    public function __construct(
        protected readonly AuditLogService $auditLogService,
        protected readonly OutletAccessService $outletAccessService
    ) {}

    public function pending()
    {
        $warehouseIds = $this->outletAccessService->warehousesFor(request()->user())->pluck('id');
        $includeLegacy = Outlet::active()->count() <= 1;

        $pending = Transaction::where('discount_approval_status', 'pending')
            ->where(function ($query) use ($warehouseIds, $includeLegacy) {
                if ($warehouseIds->isEmpty()) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('warehouse_id', $warehouseIds);
                    if ($includeLegacy) {
                        $query->orWhereNull('warehouse_id');
                    }
                }
            })
            ->with(['cashier:id,name', 'customer:id,name', 'cashierShift:id,opened_at'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'invoice' => $t->invoice,
                'cashier' => $t->cashier?->name,
                'customer' => $t->customer?->name ?? 'Umum',
                'discount' => (int) $t->discount,
                'grand_total' => (int) $t->grand_total,
                'created_at' => $t->created_at?->toISOString(),
            ]);

        return Inertia::render('Dashboard/DiscountApprovals', [
            'pendingTransactions' => $pending,
        ]);
    }

    public function approve(Transaction $transaction)
    {
        abort_if($transaction->discount_approval_status !== 'pending', 404);
        $this->ensureOutletAccess($transaction);

        $this->logAndUpdate($transaction, 'approved');

        return back()->with('success', 'Diskon disetujui.');
    }

    public function deny(Request $request, Transaction $transaction)
    {
        abort_if($transaction->discount_approval_status !== 'pending', 404);
        $this->ensureOutletAccess($transaction);

        $this->logAndUpdate($transaction, 'denied', $request->notes);

        return back()->with('success', 'Diskon ditolak.');
    }

    protected function ensureOutletAccess(Transaction $transaction): void
    {
        abort_unless(
            $this->outletAccessService->canUseWarehouse(request()->user(), $transaction->warehouse),
            404
        );
    }

    protected function logAndUpdate(Transaction $transaction, string $status, ?string $notes = null): void
    {
        $loyaltyDelta = 0;

        \DB::transaction(function () use (&$transaction, $status, $notes, &$loyaltyDelta) {
            // A denial also touches the customer: lock the shift then the customer first, the team order
            // shift → customer → receivable → sale (#494), so a return on the same sale cannot deadlock against it.
            if ($status === 'denied' && $transaction->customer_id) {
                CashierShift::whereKey($transaction->cashier_shift_id)->lockForUpdate()->value('id');
                Customer::whereKey($transaction->customer_id)->lockForUpdate()->value('id');
            }

            // Receivable before its sale, the lock order of receivable payments, returns and the payment webhook.
            $receivableId = Receivable::where('transaction_id', $transaction->id)->value('id');
            $receivable = $receivableId ? Receivable::whereKey($receivableId)->lockForUpdate()->first() : null;
            // Read again under the lock: a second answer must find the sale already answered.
            $transaction = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            abort_if($transaction->discount_approval_status !== 'pending', 404);

            // ponytail: approval only settles the approval state — payment stays tied to the actual method.
            // grand_total already excludes the manual discount (LoyaltyService), so denying restores the bill.
            // A confirmed payment is never undone (#567): the status below describes what is still owed, so a sale the
            // money already reached keeps `paid`; only a sale still waiting for it takes its method's status.
            $paymentStatus = $transaction->payment_status === 'paid'
                ? 'paid'
                : match ($transaction->payment_method) {
                    'cash' => 'paid',
                    'pay_later' => 'unpaid',
                    default => 'pending',
                };

            $updates = [
                'discount_approval_status' => $status,
                'discount_approved_by' => auth()->id(),
                'discount_approved_at' => now(),
            ];

            $deniedDiscount = (int) $transaction->discount;

            if ($status === 'denied') {
                $updates['discount'] = 0;
                $updates['payment_status'] = 'unpaid';
                if ($deniedDiscount > 0) {
                    $updates += app(BillTotalService::class)->restore($transaction, $deniedDiscount);
                }
            } else {
                $updates['payment_status'] = $paymentStatus;
            }

            // A pay-later sale owes the difference too (BR-6); a receivable already paid off is open again.
            if ($receivable && isset($updates['grand_total'])) {
                $receivable->total += $updates['grand_total'] - $transaction->grand_total;
                if ($receivable->status === 'paid' && $receivable->remaining > 0) {
                    $receivable->status = $receivable->due_date && now()->gt($receivable->due_date) ? 'overdue' : 'partial';
                }
                $receivable->save();
            }

            $oldGrandTotal = (int) $transaction->grand_total;
            $transaction->update($updates);

            // A denied discount raises the bill: loyalty follows the recomputed total (#508). Lock the shift then the
            // customer, in #494's order, and add the difference's points and total spent inside the same transaction.
            if ($status === 'denied' && isset($updates['grand_total']) && $transaction->customer_id) {
                $loyaltyDelta = (int) $updates['grand_total'] - $oldGrandTotal;
                if ($loyaltyDelta > 0) {
                    app(LoyaltyService::class)->awardAfterDenial($transaction, $oldGrandTotal, (int) $updates['grand_total']);
                }
            }

            DiscountApprovalLog::where('transaction_id', $transaction->id)
                ->where('status', 'pending')
                ->update([
                    'status' => $status,
                    'responded_by' => auth()->id(),
                    'responded_at' => now(),
                    'notes' => $notes,
                ]);
        });

        $this->auditLogService->log(
            event: 'discount_approval.'.$status,
            module: 'transactions',
            auditable: $transaction,
            description: "Diskon transaksi {$transaction->invoice} di".($status === 'approved' ? 'setujui' : 'tolak'),
            after: ['discount_approval_status' => $status],
        );

        if ($loyaltyDelta > 0) {
            $this->auditLogService->log(
                event: 'loyalty.discount_denied_adjustment',
                module: 'customers',
                auditable: $transaction,
                description: "Poin dan total belanja pelanggan disesuaikan setelah diskon ditolak pada transaksi {$transaction->invoice}.",
                meta: ['customer_id' => $transaction->customer_id, 'difference' => $loyaltyDelta],
            );
        }
    }
}
