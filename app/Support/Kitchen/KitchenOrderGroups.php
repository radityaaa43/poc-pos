<?php

namespace App\Support\Kitchen;

use App\Models\Transaction;
use Illuminate\Support\Collection;

/** Display only (#251): an add-on shows in its original's card. Not in KitchenOrders::fromItems: tickets stay per sale. */
class KitchenOrderGroups
{
    /** $items: as for KitchenOrders::fromItems. An item of an add-on also carries `add_on` (its sale id, age, unpaid). */
    public static function fromItems($items): Collection
    {
        $orders = KitchenOrders::fromItems($items);
        $parents = $items->pluck('transaction')->pluck('parent_transaction_id', 'id')->filter(); // add-on id => root id

        if ($parents->isEmpty()) {
            return $orders;
        }

        // An original with nothing left on the display still names the card.
        $roots = Transaction::with(['dineOrder:id,transaction_id,dine_table_id', 'dineOrder.table:id,name'])
            ->whereIn('id', $parents->unique())
            ->get()
            ->keyBy('id');

        // groupBy keeps first-seen order, so the card with the oldest item still comes first.
        return $orders->groupBy(fn (array $order) => $parents[$order['id']] ?? $order['id'])
            ->map(function (Collection $sales, int $rootId) use ($roots) {
                if ($sales->count() === 1 && $sales[0]['id'] === $rootId) {
                    return $sales[0];
                }

                $root = $sales->firstWhere('id', $rootId);

                return array_merge(KitchenOrders::header($roots[$rootId]), [
                    // Belum lunas is per sale: the card's mark is the original's own, and only while its items show.
                    'unpaid' => $root['unpaid'] ?? false,
                    'since' => $sales->min('since'),
                    'items' => $sales->sortBy(fn (array $sale) => $sale['id'] === $rootId ? 0 : 1)
                        ->flatMap(fn (array $sale) => $sale['id'] === $rootId ? $sale['items'] : $sale['items']->map(
                            fn (array $item) => $item + ['add_on' => ['id' => $sale['id'], 'since' => $sale['since'], 'unpaid' => $sale['unpaid'], 'type' => $sale['type']]]
                        ))
                        ->values(),
                ]);
            })->values();
    }
}
