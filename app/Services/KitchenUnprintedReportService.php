<?php

namespace App\Services;

use App\Models\KitchenItem;
use App\Models\KitchenPrintDevice;
use App\Models\KitchenStation;
use App\Models\Warehouse;
use App\Support\Kitchen\KitchenOrders;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Read-only report (#555): kitchen tickets that were due to print but no print device of the outlet ever
 * recorded. Derived from the print log, so a ticket printed later through Coba lagi leaves the report and
 * Cetak ulang (client-side) changes nothing. A ticket counts only after the "Belum tercetak" grace.
 */
class KitchenUnprintedReportService
{
    // The "Belum tercetak" chip shows after this long (resources/js/Utils/printerStatus.js:5): younger is still printing.
    public const GRACE_SECONDS = 30;

    // shortcut: one outlet-period is loaded in memory like KitchenSpeedReportService; chunk beyond ~50k items.
    private const MAX_ROWS = 500;

    /**
     * @param  array<int, int>  $warehouseIds
     * @return array{tickets: array<int, array<string, mixed>>, days: array<int, array{date: string, count: int}>, total: int, truncated: bool}
     */
    public function build(array $warehouseIds, CarbonInterface $from, CarbonInterface $to): array
    {
        $graceEnd = Carbon::now()->subSeconds(self::GRACE_SECONDS);

        // The day a ticket was due: accepted_at, or created_at on release-carried rows that have none.
        // No print row on any device means it never printed.
        $items = KitchenItem::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('status', '!=', KitchenItem::STATUS_CANCELLED)
            ->whereRaw('COALESCE(accepted_at, created_at) between ? and ?', [$from, $to])
            ->whereRaw('COALESCE(accepted_at, created_at) <= ?', [$graceEnd])
            ->whereDoesntHave('ticketPrints')
            ->orderByRaw('COALESCE(accepted_at, created_at) desc')
            ->orderByDesc('id')
            ->get(['id', 'transaction_id', 'warehouse_id', 'kitchen_station_id', 'accepted_at', 'created_at']);

        if ($items->isEmpty()) {
            return ['tickets' => [], 'days' => [], 'total' => 0, 'truncated' => false];
        }

        // The outlet's print devices that existed by the ticket's due time: a kitchen that had none was not printing.
        $outletOfWarehouse = Warehouse::whereIn('id', $warehouseIds)->pluck('outlet_id', 'id');
        $devicesByOutlet = KitchenPrintDevice::whereIn('outlet_id', $outletOfWarehouse->unique()->values())
            ->get(['id', 'outlet_id', 'name', 'created_at'])
            ->groupBy(fn (KitchenPrintDevice $device) => (int) $device->outlet_id);

        $dueOf = fn ($item) => $item->accepted_at ?? $item->created_at;
        $devicesAt = fn (int $outletId, CarbonInterface $due) => ($devicesByOutlet[$outletId] ?? collect())
            ->filter(fn (KitchenPrintDevice $device) => $device->created_at->lte($due))
            ->sortBy('name')
            ->pluck('name')
            ->values()
            ->all();

        $items = $items->filter(fn ($item) => $devicesAt((int) ($outletOfWarehouse[$item->warehouse_id] ?? 0), $dueOf($item)) !== [])->values();

        // A null station is the default one (Dapur) by name, without KitchenStation::defaultId(), which writes.
        $defaultId = KitchenStation::where('is_default', true)->value('id');
        $stationOf = fn ($item) => $item->kitchen_station_id ?? $defaultId;

        $stationNames = KitchenStation::withTrashed()
            ->whereIn('id', $items->map($stationOf)->filter()->unique())
            ->pluck('name', 'id');

        $groups = $items->groupBy(fn ($item) => $item->transaction_id.'-'.($stationOf($item) ?? 0))->map(function ($group) use ($dueOf, $stationOf, $outletOfWarehouse, $devicesAt, $stationNames) {
            $first = $group->first();
            $due = $group->map($dueOf)->sortBy(fn (CarbonInterface $at) => $at->getTimestamp())->first();
            $stationId = $stationOf($first);

            return [
                'transaction_id' => (int) $first->transaction_id,
                'station_id' => $stationId === null ? null : (int) $stationId,
                'station' => $stationId === null ? KitchenStation::DEFAULT_NAME : ($stationNames[(int) $stationId] ?? KitchenStation::DEFAULT_NAME),
                'due' => $due,
                'devices' => $devicesAt((int) ($outletOfWarehouse[$first->warehouse_id] ?? 0), $due),
                'itemIds' => $group->pluck('id')->all(),
            ];
        })->sortByDesc(fn ($ticket) => $ticket['due']->getTimestamp() * 1000 + $ticket['transaction_id'])->values();

        // Counts come from the whole period before the 500-row cap, so they stay exact.
        $timezone = config('app.timezone');
        $days = $groups->groupBy(fn ($ticket) => $ticket['due']->copy()->setTimezone($timezone)->toDateString())
            ->map(fn ($group, $date) => ['date' => $date, 'count' => $group->count()])
            ->sortBy('date')
            ->values()
            ->all();

        $total = $groups->count();
        $shown = $groups->take(self::MAX_ROWS)->values();
        $loaded = KitchenItem::whereIn('id', $shown->flatMap(fn ($ticket) => $ticket['itemIds']))
            ->with(KitchenOrders::RELATIONS)
            ->get()
            ->keyBy('id');

        $tickets = $shown->map(function ($ticket) use ($loaded, $timezone) {
            // The same presenter as the display and the print ticket, so the number matches the screen.
            $order = KitchenOrders::fromItems(collect($ticket['itemIds'])->map(fn ($id) => $loaded[$id])->filter()->values())->first();
            $due = $ticket['due']->copy()->setTimezone($timezone);

            return [
                'key' => 't'.$ticket['transaction_id'].'-s'.($ticket['station_id'] ?? 0),
                'date' => $due->toDateString(),
                'time' => $due->toIso8601String(),
                'number' => (string) ($order['number'] ?? ''),
                'type' => $order['type'] ?? null,
                'station' => $ticket['station'],
                'devices' => $ticket['devices'],
                'items' => (int) count($ticket['itemIds']),
            ];
        })->values()->all();

        return ['tickets' => $tickets, 'days' => $days, 'total' => $total, 'truncated' => $total > self::MAX_ROWS];
    }
}
