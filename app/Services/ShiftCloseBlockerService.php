<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\KitchenItem;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;

/**
 * What holds a shift open (REQ-003 AC-12, AC-17): the single source for the list the cashier reads and for what a forced
 * close marks and books. Only orders whose transaction is on that shift count (AC-27).
 */
class ShiftCloseBlockerService
{
    /**
     * @param  bool  $lock  true inside the close's DB::transaction: the rows cannot be paid or served under it
     * @return array{items: Collection<int, KitchenItem>, bills: Collection<int, Transaction>}
     */
    public function blockersFor(CashierShift $shift, bool $lock = false): array
    {
        // openBills() is the POS "Belum Lunas" list: dine-in bills and guest pay-later sales. A credit customer's
        // receivable is never in it (AC-17).
        $bills = Transaction::query()
            ->openBills()
            ->where('cashier_shift_id', $shift->id)
            ->with(['customer:id,name', 'dineOrder.table:id,name'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('id')
            ->get()
            ->filter(fn (Transaction $bill) => $bill->unpaidAmount() > 0)
            ->values();

        $items = KitchenItem::query()
            ->open()
            ->whereHas('transaction', fn ($query) => $query->where('cashier_shift_id', $shift->id))
            ->with(['product:id,title', 'transaction:id,invoice', 'detail:id,price'])
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->orderBy('id')
            ->get();

        return ['items' => $items, 'bills' => $bills];
    }

    /**
     * @param  array{items: Collection<int, KitchenItem>, bills: Collection<int, Transaction>}  $blockers
     * @return array<int, array{type: string, order: string, label: ?string, amount: int}>
     */
    public function rows(array $blockers): array
    {
        $items = $blockers['items']->toBase()->map(fn (KitchenItem $item) => [
            'type' => 'item',
            'order' => $item->transaction->invoice,
            'label' => trim(($item->product?->title ?? '-')." × {$item->qty}"),
            'amount' => (int) ($item->detail?->price ?? 0),
        ]);

        $bills = $blockers['bills']->toBase()->map(fn (Transaction $bill) => [
            'type' => 'bill',
            'order' => $bill->invoice,
            // A guest's pay-later sale has no table and no customer: the sale's note says who owes.
            'label' => $bill->dineOrder?->table?->name ?? $bill->customer?->name ?? $bill->note,
            'amount' => $bill->unpaidAmount(),
        ]);

        return $items->concat($bills)->values()->all();
    }
}
