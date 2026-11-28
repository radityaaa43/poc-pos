<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The rows of the POS unpaid bill ("Belum Lunas") list: one query, shared by the POS page and the endpoint the browser polls,
 * so the two cannot drift apart.
 */
class OpenBillListService
{
    public function __construct(private OutletAccessService $outletAccessService) {}

    /**
     * `awaiting_online` is the guest paying online (the tender-less pending charge of a dine-in bill); `late_payment` is a
     * payment received too late, to refund by hand.
     */
    public function rowsFor(User $user): Collection
    {
        return Transaction::openBills()
            ->whereIn('warehouse_id', $this->outletAccessService->warehousesFor($user)->pluck('id'))
            ->with('dineOrder:id,transaction_id,dine_table_id,status', 'dineOrder.table:id,name')
            // 1 or null: lets isAwaitingGuestPayment() answer without a query per row
            ->addSelect(['tenders_exists' => TransactionTender::query()->selectRaw('1')->whereColumn('transaction_tenders.transaction_id', 'transactions.id')->limit(1)])
            // 1 or null: the request died before storing the charge, so there is no QR to show and the row offers Bayar
            ->addSelect(['abandoned_claim' => TransactionTender::query()->abandonedClaim()->selectRaw('1')->whereColumn('transaction_tenders.transaction_id', 'transactions.id')->limit(1)])
            ->addSelect(['customer_name' => Customer::select('name')->whereColumn('customers.id', 'transactions.customer_id')->limit(1)])
            ->withSum(['salesReturns' => fn ($returns) => $returns->where('status', 'completed')], 'total_return_amount')
            ->oldest()
            ->get(['transactions.id', 'invoice', 'grand_total', 'payment_status', 'payment_attempt', 'late_superseded_payment_at', 'note', 'order_name', 'transactions.created_at'])
            ->map(fn (Transaction $bill) => [
                'id' => $bill->id,
                'order_id' => $bill->dineOrder?->id,
                'invoice' => $bill->invoice,
                'label' => data_get($bill, 'dineOrder.table.name') ?? $bill->getAttribute('customer_name') ?? $bill->note,
                'table' => $bill->dineOrder?->table?->name,
                'order_name' => $bill->order_name,
                'grand_total' => (int) $bill->grand_total,
                'amount' => max(0, (int) $bill->grand_total - (int) $bill->getAttribute('sales_returns_sum_total_return_amount')),
                'order_status' => $bill->dineOrder?->status,
                'pending' => $bill->payment_status === 'pending' && ! $bill->getAttribute('abandoned_claim'),
                // Dine-in only: taking over is DineOrderService::pay, and a pay-later POS sale has no guest charge.
                'awaiting_online' => $bill->dineOrder !== null && $bill->isAwaitingGuestPayment(),
                'late_payment' => $bill->late_superseded_payment_at !== null,
                'created_at' => $bill->created_at->toIso8601String(),
            ]);
    }
}
