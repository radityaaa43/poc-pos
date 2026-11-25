<?php

namespace App\Services;

use App\Models\KitchenItem;
use App\Models\KitchenPrintStation;
use App\Models\KitchenTicketPrint;
use App\Support\Kitchen\KitchenOrders;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class KitchenTicketService
{
    // shortcut: a backlog longer than this prints over several polls (whole orders each), not in one go.
    private const ORDERS_PER_POLL = 30;

    /**
     * Tickets this station has not printed yet, oldest first, one per order. An order is never split across
     * two polls: the oldest ORDERS_PER_POLL orders come with all their pending items. Prices are never selected.
     */
    public function pending(KitchenPrintStation $station): Collection
    {
        $unprinted = fn (): Builder => KitchenItem::open()
            ->inOutlet($station->outlet_id)
            // Only what reached the kitchen at or after the station was named (a new tablet does not reprint the queue).
            ->whereRaw('COALESCE(accepted_at, created_at) >= ?', [$station->created_at])
            ->whereDoesntHave('ticketPrints', fn ($q) => $q->where('kitchen_print_station_id', $station->id));

        $transactionIds = $unprinted()
            ->select('transaction_id')
            ->groupBy('transaction_id')
            ->orderByRaw('MIN(COALESCE(accepted_at, created_at))')
            ->orderByRaw('MIN(id)')
            ->limit(self::ORDERS_PER_POLL)
            ->pluck('transaction_id');

        if ($transactionIds->isEmpty()) {
            return collect();
        }

        $items = $unprinted()
            ->whereIn('transaction_id', $transactionIds)
            ->with(KitchenOrders::RELATIONS)
            ->orderByRaw('COALESCE(accepted_at, created_at)')
            ->orderBy('id')
            ->get();

        return KitchenOrders::fromItems($items)->map(fn (array $order) => [
            'key' => 't'.$order['id'],
            'number' => $order['number'],
            'type' => $order['type'],
            'unpaid' => $order['unpaid'],
            'since' => $order['since']->toIso8601String(),
            'item_ids' => $order['items']->pluck('id')->all(),
            'items' => $order['items']->map(fn (array $item) => [
                'id' => $item['id'],
                'title' => $item['title'],
                'qty' => $item['qty'],
                'note' => $item['note'],
            ])->all(),
        ]);
    }

    /**
     * Record that the station printed these items. Idempotent (a retry, a double tap or two tabs add nothing)
     * and it never writes kitchen_items or stock: a print does not change an item's status.
     *
     * @param  array<int, int>  $itemIds
     */
    public function markPrinted(KitchenPrintStation $station, array $itemIds): void
    {
        // Ids of other outlets or unknown ids are dropped silently, so the log cannot be probed.
        $ids = KitchenItem::inOutlet($station->outlet_id)->whereIn('id', $itemIds)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        KitchenTicketPrint::insertOrIgnore(
            $ids->map(fn ($id) => ['kitchen_print_station_id' => $station->id, 'kitchen_item_id' => $id])->all()
        );
    }
}
