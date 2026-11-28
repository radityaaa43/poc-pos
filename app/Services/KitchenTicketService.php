<?php

namespace App\Services;

use App\Models\KitchenCancellation;
use App\Models\KitchenCancellationPrint;
use App\Models\KitchenItem;
use App\Models\KitchenPrintDevice;
use App\Models\KitchenStation;
use App\Models\KitchenTicketPrint;
use App\Support\Kitchen\KitchenOrders;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class KitchenTicketService
{
    // shortcut: a backlog longer than this prints over several polls (whole orders each), not in one go.
    private const ORDERS_PER_POLL = 30;

    // shortcut: a device off over a weekend must not print Friday's cancels; revisit for overnight shops.
    public const CANCEL_WINDOW_HOURS = 12;

    // shortcut: the grace covers the gap between the poll that built the original ticket and its record; inside it a cancel
    // may already be on the printed ticket and one harmless extra DIBATALKAN prints, while a missing one would let the kitchen
    // cook it. The compare uses the time the server recorded the print: if that record is delayed by more than the grace
    // (a failed confirm retried later), a return in between prints no DIBATALKAN. Upgrade: store the printed qty and the
    // client's print time in the record.
    public const CANCEL_GRACE_SECONDS = 30;

    /**
     * Tickets this device has not printed yet, oldest first, one per order (one per station in a split shop). An
     * order is never split across two polls: the oldest ORDERS_PER_POLL orders come with all their pending items.
     * Prices are never selected.
     */
    public function pending(KitchenPrintDevice $device): Collection
    {
        // The stations this device serves (#345). None: every station. Chosen stations all deleted: Dapur only.
        $served = $this->servedStationIds($device);
        // A split shop prints one titled ticket per station; a one-station shop keeps one untitled ticket per order.
        $multi = KitchenStation::count() > 1;
        $defaultId = ($multi || $served !== []) ? KitchenStation::defaultId() : null;
        $includesDefault = $served !== [] && in_array($defaultId, $served, true);

        $unprinted = fn (): Builder => KitchenItem::open()
            ->inOutlet($device->outlet_id)
            // Only what reached the kitchen at or after the device was named (a new tablet does not reprint the queue).
            ->whereRaw('COALESCE(accepted_at, created_at) >= ?', [$device->created_at])
            ->whereDoesntHave('ticketPrints', fn ($q) => $q->where('kitchen_print_device_id', $device->id))
            // A device that serves some stations prints only theirs. Dapur's items (a null or deleted station, B1)
            // print only on a device that serves Dapur (Tech Lead answer on #345).
            ->when($served !== [], fn (Builder $q) => $q->where(function (Builder $stations) use ($served, $includesDefault) {
                $stations->whereIn('kitchen_station_id', $served);

                if ($includesDefault) {
                    $stations->orWhereNull('kitchen_station_id')
                        ->orWhereNotIn('kitchen_station_id', KitchenStation::query()->select('id'));
                }
            }));

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

        $orders = KitchenOrders::fromItems($items);

        if (! $multi) {
            return $orders->map(fn (array $order) => $this->ticket($order, 't'.$order['id']));
        }

        // One ticket per (order, station), titled with the station name. A null or deleted station is Dapur.
        // An all-stations device gets the same split, one titled ticket per station.
        $liveStations = KitchenStation::pluck('id')->map(fn ($id) => (int) $id)->all();
        $effective = fn ($stationId) => $stationId !== null && in_array((int) $stationId, $liveStations, true) ? $stationId : $defaultId;

        return $orders->flatMap(function (array $order) use ($effective, $defaultId) {
            $groups = $order['items']->groupBy(fn (array $item) => $effective($item['station_id']));

            return $groups->map(function (Collection $stationItems, $stationId) use ($order, $groups, $defaultId) {
                $key = $groups->count() === 1 ? 't'.$order['id'] : 't'.$order['id'].'-s'.$stationId;
                $title = (int) $stationId === (int) $defaultId
                    ? KitchenStation::DEFAULT_NAME
                    : $stationItems->first()['station'];

                return $this->ticket($order, $key, $stationItems, $title);
            })->values();
        })->values();
    }

    /**
     * The live stations this device serves (#345). No station chosen: every station. Every chosen station deleted:
     * Dapur only, so a device never falls back to printing every station's items.
     *
     * @return array<int, int>
     */
    public function servedStationIds(KitchenPrintDevice $device): array
    {
        $chosen = $device->stations()->withTrashed()->pluck('kitchen_stations.id')->map(fn ($id) => (int) $id)->all();

        if ($chosen === []) {
            return [];
        }

        $live = KitchenStation::whereIn('id', $chosen)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $live === [] ? [KitchenStation::defaultId()] : $live;
    }

    /**
     * One ticket from an order and a set of its items, optionally titled with a station name (#345).
     *
     * @param  array<string, mixed>  $order
     * @param  Collection<int, array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    private function ticket(array $order, string $key, ?Collection $items = null, ?string $station = null): array
    {
        $items ??= $order['items'];

        $ticket = [
            'key' => $key,
            'kind' => $order['root_id'] === $order['id'] ? 'order' : 'addon',
            'root_id' => $order['root_id'],
            'number' => $order['number'],
            'type' => $order['type'],
            'unpaid' => $order['unpaid'],
            'since' => $order['since']->toIso8601String(),
            'item_ids' => $items->pluck('id')->all(),
            'items' => $items->map(fn (array $item) => [
                'id' => $item['id'],
                'title' => $item['title'],
                'qty' => $item['qty'],
                'note' => $item['note'],
            ])->all(),
        ];

        // A single-station shop prints no title (REQ-006 decision 8); a station split does.
        if ($station !== null) {
            $ticket['station'] = $station;
        }

        return $ticket;
    }

    /**
     * Record that the device printed these items. Idempotent (a retry, a double tap or two tabs add nothing)
     * and it never writes kitchen_items or stock: a print does not change an item's status.
     *
     * @param  array<int, int>  $itemIds
     */
    public function markPrinted(KitchenPrintDevice $device, array $itemIds): void
    {
        // Ids of other outlets or unknown ids are dropped silently, so the log cannot be probed.
        $ids = KitchenItem::inOutlet($device->outlet_id)->whereIn('id', $itemIds)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        KitchenTicketPrint::insertOrIgnore(
            $ids->map(fn ($id) => ['kitchen_print_device_id' => $device->id, 'kitchen_item_id' => $id, 'printed_at' => now()])->all()
        );
    }

    /**
     * DIBATALKAN tickets this device has not printed yet, oldest first, one per (return, order, station) — a split
     * shop titles each ticket like a normal one. Only an item whose original this device printed (BR-4), within
     * CANCEL_WINDOW_HOURS, and not cancelled before that print by more than the grace (the original then already
     * carried the lowered qty). The grace is compared in PHP: SQLite and MySQL differ in date arithmetic and the
     * tests run on SQLite. The quantity is what the return cancelled. Prices are never selected.
     */
    public function pendingCancellations(KitchenPrintDevice $device): Collection
    {
        $printedHere = KitchenTicketPrint::where('kitchen_print_device_id', $device->id);

        $events = KitchenCancellation::query()
            ->where('created_at', '>=', now()->subHours(self::CANCEL_WINDOW_HOURS))
            ->whereIn('kitchen_item_id', KitchenItem::inOutlet($device->outlet_id)->select('id'))
            ->whereIn('kitchen_item_id', (clone $printedHere)->select('kitchen_item_id'))
            ->whereDoesntHave('prints', fn ($q) => $q->where('kitchen_print_device_id', $device->id))
            ->with(array_map(fn (string $relation) => 'item.'.$relation, KitchenOrders::RELATIONS))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($events->isEmpty()) {
            return collect();
        }

        // A split shop groups a return's cancellations per station and titles each ticket, like a normal ticket.
        $multi = KitchenStation::count() > 1;
        $defaultId = $multi ? KitchenStation::defaultId() : null;
        $liveStations = $multi ? KitchenStation::pluck('id')->map(fn ($id) => (int) $id)->all() : [];
        $effective = fn ($stationId) => $stationId !== null && in_array((int) $stationId, $liveStations, true) ? $stationId : $defaultId;

        $printedAt = $printedHere->whereIn('kitchen_item_id', $events->pluck('kitchen_item_id'))->pluck('printed_at', 'kitchen_item_id');

        $tickets = $events
            ->filter(fn (KitchenCancellation $event) => ($print = $printedAt->get($event->kitchen_item_id))
                && $event->created_at->gte($print->copy()->subSeconds(self::CANCEL_GRACE_SECONDS)))
            ->groupBy(fn (KitchenCancellation $event) => 'c'.$event->sales_return_id.'t'.$event->item->transaction_id
                .($multi ? 's'.$effective($event->item->kitchen_station_id) : ''))
            ->take(self::ORDERS_PER_POLL);

        // The same presenter as the display card and the print ticket, so all three show the same number.
        $orders = KitchenOrders::fromItems($tickets->flatMap(fn ($group) => $group->pluck('item'))->unique('id'))->keyBy('id');
        $lines = $orders->flatMap(fn (array $order) => $order['items'])->keyBy('id');

        return $tickets->map(function ($group, $key) use ($orders, $lines, $multi, $effective, $defaultId) {
            $first = $group->first();
            $ticket = [
                'key' => $key,
                ...Arr::only($orders[$first->item->transaction_id], ['number', 'type', 'unpaid']),
                'since' => $first->created_at->toIso8601String(),
                'cancellation_ids' => $group->pluck('id')->all(),
                'items' => $group->map(fn (KitchenCancellation $event) => [
                    'id' => $event->id,
                    'title' => $lines[$event->kitchen_item_id]['title'],
                    'qty' => $event->qty_cancelled,
                    'note' => $lines[$event->kitchen_item_id]['note'],
                ])->values()->all(),
            ];

            if ($multi) {
                $stationId = $effective($first->item->kitchen_station_id);
                $ticket['station'] = (int) $stationId === (int) $defaultId
                    ? KitchenStation::DEFAULT_NAME
                    : $first->item->kitchenStation?->name;
            }

            return $ticket;
        })->values();
    }

    /**
     * Record that the device printed these cancellations. Idempotent, one statement, no lock, and it never writes stock
     * or kitchen_items (a ticket does not change anything).
     *
     * @param  array<int, int>  $cancellationIds
     */
    public function markCancellationsPrinted(KitchenPrintDevice $device, array $cancellationIds): void
    {
        // Ids of other outlets or unknown ids are dropped silently, so the log cannot be probed.
        $ids = KitchenCancellation::whereIn('id', $cancellationIds)
            ->whereIn('kitchen_item_id', KitchenItem::inOutlet($device->outlet_id)->select('id'))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        KitchenCancellationPrint::insertOrIgnore(
            $ids->map(fn ($id) => ['kitchen_print_device_id' => $device->id, 'kitchen_cancellation_id' => $id])->all()
        );
    }
}
