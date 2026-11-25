<?php

namespace App\Services;

use App\Models\DineOrder;
use App\Models\KitchenItem;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KitchenItemService
{
    /** Forward-only order of the steps a kitchen item goes through. */
    private const RANK = [
        KitchenItem::STATUS_ACCEPTED => 0,
        KitchenItem::STATUS_PREPARING => 1,
        KitchenItem::STATUS_READY => 2,
        KitchenItem::STATUS_SERVED => 3,
    ];

    /** Mulai buat: accepted -> preparing. */
    public function start(KitchenItem $item, User $user): void
    {
        $this->step($item, KitchenItem::STATUS_ACCEPTED, KitchenItem::STATUS_PREPARING, $user);
    }

    /** Siap: preparing -> ready. */
    public function ready(KitchenItem $item, User $user): void
    {
        $this->step($item, KitchenItem::STATUS_PREPARING, KitchenItem::STATUS_READY, $user);
    }

    /** Semua siap: every open item of the sale becomes ready; one never started gets its preparing time at the same moment. */
    public function allReady(Transaction $transaction, User $user): void
    {
        DB::transaction(function () use ($transaction, $user) {
            $now = now();

            $items = KitchenItem::where('transaction_id', $transaction->id)
                ->whereIn('status', [KitchenItem::STATUS_ACCEPTED, KitchenItem::STATUS_PREPARING])
                ->lockForUpdate()
                ->get();

            foreach ($items as $item) {
                $item->update([
                    'status' => KitchenItem::STATUS_READY,
                    'preparing_at' => $item->preparing_at ?? $now,
                    'preparing_by' => $item->preparing_at ? $item->preparing_by : $user->id,
                    'ready_at' => $now,
                    'ready_by' => $user->id,
                ]);
            }

            $this->syncDineOrder($transaction);
        });
    }

    /**
     * BR-1: a paid dine order with nothing to make (no line whose product needs preparation) needs no kitchen step, so it
     * is served at payment (by the system, served_by null) and completed at once. Decided from the sale's lines, not from
     * the kitchen rows: no rows can also mean a sale without a warehouse or an intake that has not run yet (#289), and
     * those orders must still reach the kitchen. Pay later (served at accept, completed once paid) lands in #279 (T6c).
     */
    public function serveIfNoKitchenItems(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $order = DineOrder::where('transaction_id', $transaction->id)->lockForUpdate()->first();

            if (! $order || $order->status !== DineOrder::STATUS_ACCEPTED || $this->hasLinesToMake($transaction)) {
                return;
            }

            $order->update(['status' => DineOrder::STATUS_SERVED, 'served_at' => now(), 'served_by' => null]);
            $order->completeIfSettled();
        });
    }

    private function hasLinesToMake(Transaction $transaction): bool
    {
        return $transaction->details()->whereHas('product', fn ($product) => $product->where('needs_preparation', true))->exists();
    }

    /**
     * A completed sales return takes its lines off the kitchen: a partial return lowers the item's qty, a full one cancels it
     * (BR-3). Served items are a normal return and are left alone (BR-5). Call it inside the return's DB::transaction and before
     * any stock row is locked: the item locks come first, as in a start tap, so the return wins the race (BR-7) without a deadlock.
     */
    public function cancelForReturn(SalesReturn $return): void
    {
        // items() and a query, not the property: Larastan cannot see the untyped SalesReturn relations.
        $lines = $return->items()->get();
        $items = KitchenItem::whereIn('transaction_detail_id', $lines->pluck('transaction_detail_id'))
            ->open()->lockForUpdate()->get()->keyBy('transaction_detail_id');

        foreach ($lines as $line) {
            if ($item = $items->get($line->transaction_detail_id)) {
                $qty = max(0, $item->qty - $line->qty_return);
                // a cancelled item keeps its last qty: the display shows what was cancelled
                $item->update($qty > 0 ? ['qty' => $qty] : ['status' => KitchenItem::STATUS_CANCELLED]);
            }
        }

        // Also without a kitchen item of its own: returning the last plain line can complete the cancellation of the order.
        $this->syncDineOrder(Transaction::findOrFail($return->transaction_id), $return);
    }

    /** One step: valid only from `$from`. The row lock makes the first tap win; a later one is told who did it (AC-23). */
    private function step(KitchenItem $item, string $from, string $to, User $user): void
    {
        DB::transaction(function () use ($item, $from, $to, $user) {
            $item = KitchenItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

            // BR-7: a return that won the race has already cancelled the item
            if ($item->status === KitchenItem::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['item' => 'Sudah dibatalkan']);
            }

            if ((self::RANK[$item->status] ?? -1) >= self::RANK[$to]) {
                $who = $item->{"{$to}User"}->name ?? 'staf lain';

                throw ValidationException::withMessages(['item' => "Sudah diproses oleh {$who}"]);
            }

            if ($item->status !== $from) {
                throw ValidationException::withMessages(['item' => 'Langkah tidak valid untuk item ini']);
            }

            $item->update(['status' => $to, "{$to}_at" => now(), "{$to}_by" => $user->id]);

            $this->syncDineOrder($item->transaction);
        });
    }

    /**
     * BR-11 (Business, 2026-11-26): a dine order counts as cancelled only when every sale LINE is fully returned. Returning
     * just the dishes cancels their kitchen items, but the order still owes the plain lines (a packaged drink), so it is
     * served and completed as usual. `$including` is the return being completed: it is not marked completed yet.
     */
    private function allLinesReturned(Transaction $transaction, ?SalesReturn $including): bool
    {
        $returned = SalesReturnItem::query()
            ->whereIn('sales_return_id', SalesReturn::where('transaction_id', $transaction->id)
                ->where('status', 'completed')->where('id', '!=', $including->id ?? 0)->select('id'))
            ->selectRaw('transaction_detail_id, SUM(qty_return) as qty')->groupBy('transaction_detail_id')
            ->pluck('qty', 'transaction_detail_id')->all();

        foreach ($including?->items()->get() ?? [] as $line) {
            $returned[$line->transaction_detail_id] = ($returned[$line->transaction_detail_id] ?? 0) + $line->qty_return;
        }

        return TransactionDetail::where('transaction_id', $transaction->id)->get(['id', 'qty'])
            ->every(fn (TransactionDetail $detail) => ($returned[$detail->id] ?? 0) >= $detail->qty);
    }

    /**
     * The dine order's own status and trail follow its items: the first item preparing starts the order,
     * every item ready completes it. Direct updates: items exist only for a paid bill, so the pay-first gate holds.
     */
    private function syncDineOrder(Transaction $transaction, ?SalesReturn $including = null): void
    {
        $order = DineOrder::where('transaction_id', $transaction->id)->lockForUpdate()->first();

        if (! $order) {
            return;
        }

        $all = KitchenItem::where('transaction_id', $transaction->id)->get();
        $items = $all->where('status', '!=', KitchenItem::STATUS_CANCELLED); // a cancelled item no longer holds the order back

        // BR-11: nothing left to make. Every item cancelled cancels the order; a served one means it was fulfilled, so it completes as usual.
        if ($all->isNotEmpty() && $items->every(fn (KitchenItem $item) => $item->status === KitchenItem::STATUS_SERVED)
            && in_array($order->status, [DineOrder::STATUS_ACCEPTED, DineOrder::STATUS_PREPARING, DineOrder::STATUS_READY], true)) {
            if ($items->isEmpty() && $this->allLinesReturned($transaction, $including)) {
                $order->update(['status' => DineOrder::STATUS_CANCELLED]);
            } else {
                $order->update(['status' => DineOrder::STATUS_SERVED, 'served_at' => now(), 'served_by' => null]);
                $order->completeIfSettled();
            }

            return;
        }

        if ($order->status === DineOrder::STATUS_ACCEPTED && $first = $items->whereNotNull('preparing_at')->sortBy('preparing_at')->first()) {
            $order->update([
                'status' => DineOrder::STATUS_PREPARING,
                'preparing_at' => $first->preparing_at,
                'preparing_by' => $first->preparing_by,
            ]);
        }

        $allReady = $items->isNotEmpty() && $items->every(fn (KitchenItem $item) => in_array($item->status, [KitchenItem::STATUS_READY, KitchenItem::STATUS_SERVED], true));

        if ($order->status === DineOrder::STATUS_PREPARING && $allReady) {
            $last = $items->whereNotNull('ready_at')->sortByDesc('ready_at')->first();
            $order->update([
                'status' => DineOrder::STATUS_READY,
                'ready_at' => $last->ready_at ?? now(),
                'ready_by' => $last?->ready_by,
            ]);
        }
    }
}
