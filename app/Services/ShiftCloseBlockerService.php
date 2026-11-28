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
    /** @return array{items: Collection<int, KitchenItem>, bills: Collection<int, Transaction>, splits: Collection<int, Transaction>, discounts: Collection<int, Transaction>} */
    public function blockersFor(CashierShift $shift): array
    {
        // openBills() is the POS "Belum Lunas" list: dine-in bills and guest pay-later sales. A credit customer's
        // receivable is never in it (AC-17).
        $bills = Transaction::query()
            ->openBills()
            ->where('cashier_shift_id', $shift->id)
            ->with(['dineOrder.table:id,name'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Transaction $bill) => $bill->unpaidAmount() > 0)
            ->values();

        $items = KitchenItem::query()
            ->open()
            ->whereHas('transaction', fn ($query) => $query->where('cashier_shift_id', $shift->id))
            ->with(['product:id,title', 'transaction:id,invoice', 'detail:id,price'])
            ->orderBy('id')
            ->get();

        // #396: a Bayar sekarang split sale with only part of its money in.
        $splits = Transaction::query()
            ->partlyPaidSplit()
            ->where('cashier_shift_id', $shift->id)
            ->orderBy('id')
            ->get()
            ->filter(fn (Transaction $sale) => $sale->unpaidAmount() > 0)
            ->values();

        // #542: a sale of this shift whose manual discount still waits for approval, so its final value is open. A
        // cancelled (restocked) sale does not hold. shortcut: the checkout writes `pending` after its own commit, so a
        // close in that gap can miss it; move the update into CheckoutService's transaction when that gap matters.
        $discounts = Transaction::query()
            ->where('cashier_shift_id', $shift->id)
            ->where('discount_approval_status', 'pending')
            ->notRestocked()
            ->with('customer:id,name')
            ->orderBy('id')
            ->get();

        return ['items' => $items, 'bills' => $bills, 'splits' => $splits, 'discounts' => $discounts];
    }

    /**
     * @param  array{items: Collection<int, KitchenItem>, bills: Collection<int, Transaction>, splits: Collection<int, Transaction>, discounts: Collection<int, Transaction>}  $blockers
     * @return array<int, array{type: string, order: string, label: ?string, amount: int, id?: int}>
     */
    public function rows(array $blockers): array
    {
        $items = $blockers['items']->toBase()->map(fn (KitchenItem $item) => [
            'type' => 'item',
            'order' => $item->transaction->invoice,
            'label' => data_get($item, 'product.title', '-')." × {$item->qty}",
            'amount' => (int) data_get($item, 'detail.price', 0),
        ]);

        $bills = $blockers['bills']->toBase()->map(fn (Transaction $bill) => [
            'type' => 'bill',
            'order' => $bill->invoice,
            // A guest's pay-later sale has no table and no customer: the sale's note says who owes.
            'label' => data_get($bill, 'dineOrder.table.name') ?? data_get($bill, 'customer.name') ?? $bill->note,
            'amount' => $bill->unpaidAmount(),
        ]);

        $splits = $blockers['splits']->toBase()->map(fn (Transaction $sale) => [
            'type' => 'split',
            'order' => $sale->invoice,
            'label' => data_get($sale, 'customer.name') ?? $sale->note,
            'amount' => $sale->unpaidAmount(),
        ]);

        // #542: the sale id lets the list approve or deny straight from the hold (the existing web routes).
        $discounts = $blockers['discounts']->toBase()->map(fn (Transaction $sale) => [
            'type' => 'discount',
            'id' => $sale->id,
            'order' => $sale->invoice,
            'label' => data_get($sale, 'customer.name') ?? $sale->note,
            'amount' => (int) $sale->discount,
        ]);

        return $items->concat($bills)->concat($splits)->concat($discounts)->values()->all();
    }
}
