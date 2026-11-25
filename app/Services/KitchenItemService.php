<?php

namespace App\Services;

use App\Models\DineOrder;
use App\Models\KitchenItem;
use App\Models\Transaction;
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

    /** One step: valid only from `$from`. The row lock makes the first tap win; a later one is told who did it (AC-23). */
    private function step(KitchenItem $item, string $from, string $to, User $user): void
    {
        DB::transaction(function () use ($item, $from, $to, $user) {
            $item = KitchenItem::whereKey($item->id)->lockForUpdate()->firstOrFail();

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
     * The dine order's own status and trail follow its items: the first item preparing starts the order,
     * every item ready completes it. Direct updates: items exist for a paid bill, or for a pay-later one (#279), which skips the pay-first gate by design.
     */
    private function syncDineOrder(Transaction $transaction): void
    {
        $order = DineOrder::where('transaction_id', $transaction->id)->lockForUpdate()->first();

        if (! $order) {
            return;
        }

        $items = KitchenItem::where('transaction_id', $transaction->id)->get();

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
