<?php

namespace App\Services;

use App\Models\KitchenItem;
use App\Models\KitchenStation;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-only kitchen speed report (#243): per menu item the counts, the preparing -> ready ("make") and
 * accepted -> ready ("wait + make") times and the share within the menu's target, plus the average
 * accepted -> last served time of fully served orders. Times are minutes, rounded to one decimal at the end.
 */
class KitchenSpeedReportService
{
    /** REQ-003 "Kualitas data": the share of kitchen items whose times are complete up to ready. */
    public const COMPLETENESS_TARGET = 95;

    /**
     * @param  array<int, int>  $warehouseIds
     * @return array{rows: array<int, array<string, mixed>>, orders: array{orders: int, avg_minutes: float|null}, empty: bool, completeness: array{items: int, complete: int, percent: float|null, below_target: bool, target: int}}
     */
    public function build(array $warehouseIds, CarbonInterface $from, CarbonInterface $to, ?int $stationId = null): array
    {
        // shortcut: loads one outlet-month in memory; chunk it beyond ~200k rows.
        // The period is the day the item was accepted. Release-carried rows (no accepted_at) are outside it.
        $scoped = KitchenItem::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereNotNull('accepted_at')
            ->whereBetween('accepted_at', [$from, $to]);
        // Cancelled, not collected and still open rows never reach the per-menu numbers; the completeness card counts the open ones.
        $base = (clone $scoped)->whereIn('status', [KitchenItem::STATUS_READY, KitchenItem::STATUS_SERVED]);
        $columns = ['transaction_id', 'product_id', 'kitchen_station_id', 'qty', 'status', 'accepted_at', 'preparing_at', 'ready_at'];

        // A read-only lookup, not KitchenStation::defaultId(), which creates the station: a report GET must not write.
        // An item without a station (the column is nullable) counts as the default station, as intake does.
        $defaultId = KitchenStation::where('is_default', true)->value('id');
        $items = $this->forStation(clone $base, $stationId, $defaultId)->get($columns);

        $products = Product::whereIn('id', $items->pluck('product_id')->unique())
            ->get(['id', 'title', 'prep_target_minutes'])
            ->keyBy('id');
        $stationOf = fn ($item) => $item->kitchen_station_id ?? $defaultId;
        // withTrashed: a deleted station keeps its name in past periods; a rename shows the current name.
        $stations = KitchenStation::withTrashed()
            ->whereIn('id', $items->map($stationOf)->filter()->unique())
            ->get(['id', 'name', 'deleted_at'])
            ->keyBy('id');

        $rows = $items->groupBy(fn ($item) => $item->product_id.'-'.$stationOf($item))
            ->map(fn (Collection $group) => $this->row($products[$group->first()->product_id], $stations->get($stationOf($group->first())), $group))
            ->sort(fn ($a, $b) => strnatcasecmp($a['title'], $b['title']) ?: strnatcasecmp((string) $a['station'], (string) $b['station']))
            ->values()
            ->all();
        // The handover card is order level, so a station filter does not narrow it: its orders come from every station.
        $served = $stationId === null ? $items : (clone $base)->get($columns);
        $orders = $this->orders($served->where('status', KitchenItem::STATUS_SERVED)->pluck('transaction_id')->unique());

        return [
            'rows' => $rows,
            'orders' => $orders,
            'empty' => $rows === [] && ($stationId !== null || $orders['orders'] === 0),
            'completeness' => $this->completeness($scoped, $stationId, $defaultId),
        ];
    }

    /** An item without a station counts as the default station, as intake does. */
    private function forStation(Builder $query, ?int $stationId, ?int $defaultId): Builder
    {
        return $query->when($stationId !== null, fn (Builder $q) => $q->where(fn (Builder $group) => $group
            ->where('kitchen_station_id', $stationId)
            ->when($stationId === (int) $defaultId, fn (Builder $default) => $default->orWhereNull('kitchen_station_id'))));
    }

    /**
     * BR-1/BR-3: every live item of the report's filters; complete when both the start and the ready time exist.
     * Still-open items stay in the denominator (REQ-003's formula), cancelled and not-collected items do not.
     *
     * @return array{items: int, complete: int, percent: float|null, below_target: bool, target: int}
     */
    private function completeness(Builder $scoped, ?int $stationId, ?int $defaultId): array
    {
        $row = $this->forStation(clone $scoped, $stationId, $defaultId)
            ->whereNotIn('status', [KitchenItem::STATUS_CANCELLED, KitchenItem::STATUS_NOT_COLLECTED])
            ->selectRaw('count(*) as items, sum(case when preparing_at is not null and ready_at is not null then 1 else 0 end) as complete')
            ->first();
        $items = (int) ($row->items ?? 0);
        $complete = (int) ($row->complete ?? 0);

        return [
            'items' => $items,
            'complete' => $complete,
            'percent' => $items > 0 ? round(100 * $complete / $items, 1) : null,
            'below_target' => $items > 0 && $complete * 100 < $items * self::COMPLETENESS_TARGET,
            'target' => self::COMPLETENESS_TARGET,
        ];
    }

    private function row(Product $product, ?KitchenStation $station, Collection $group): array
    {
        $make = $group->whereNotNull('preparing_at')->whereNotNull('ready_at')
            ->map(fn ($item) => $this->minutes($item->preparing_at, $item->ready_at))->values();
        $waitMake = $group->whereNotNull('ready_at')->map(fn ($item) => $this->minutes($item->accepted_at, $item->ready_at))->values();
        $target = $product->prep_target_minutes;

        return [
            'product_id' => $product->id,
            'title' => $product->title,
            'station_id' => $station?->id,
            'station' => $station?->name,
            'station_deleted' => $station?->trashed() ?? false,
            'count_rows' => $group->count(),
            'count_portions' => (int) $group->sum('qty'),
            'make_median' => $this->median($make),
            'make_avg' => $this->average($make),
            'wait_make_median' => $this->median($waitMake),
            'wait_make_avg' => $this->average($waitMake),
            'target' => $target,
            // The target is compared to preparing -> ready; an item without that time cannot be judged.
            'within_target_pct' => $target === null || $make->isEmpty()
                ? null
                : round($make->filter(fn ($m) => $m <= $target)->count() / $make->count() * 100, 1),
        ];
    }

    /**
     * Fully served orders only: every live (non-cancelled) item is served, so an order with a not collected or
     * still open item is left out. Duration = last served - first accepted, which is the payment time (intake runs
     * right after the payment commits).
     *
     * @param  Collection<int, int>  $transactionIds
     */
    private function orders(Collection $transactionIds): array
    {
        // Chunked: a long period can hold more orders than a prepared statement has placeholders (65,535 on MySQL).
        $durations = $transactionIds->unique()->chunk(5000)
            ->flatMap(fn (Collection $ids) => KitchenItem::whereIn('transaction_id', $ids)
                ->where('status', '!=', 'cancelled')
                ->get(['transaction_id', 'status', 'accepted_at', 'served_at']))
            ->groupBy('transaction_id')
            ->filter(fn (Collection $order) => $order->every(fn ($item) => $item->status === KitchenItem::STATUS_SERVED))
            ->map(function (Collection $order) {
                $served = $order->max(fn ($item) => $item->served_at?->getTimestamp());
                $accepted = $order->min(fn ($item) => $item->accepted_at?->getTimestamp());

                return $served === null || $accepted === null ? null : ($served - $accepted) / 60;
            })
            ->filter(fn ($minutes) => $minutes !== null);

        return ['orders' => $durations->count(), 'avg_minutes' => $this->average($durations->values())];
    }

    private function minutes(CarbonInterface $start, CarbonInterface $end): float
    {
        return ($end->getTimestamp() - $start->getTimestamp()) / 60;
    }

    /** The middle value, or the mean of the two middle values; computed here because SQLite has no percentile. */
    private function median(Collection $minutes): ?float
    {
        if ($minutes->isEmpty()) {
            return null;
        }
        $sorted = $minutes->sort()->values();
        $middle = intdiv($sorted->count(), 2);

        return round($sorted->count() % 2 ? $sorted[$middle] : ($sorted[$middle - 1] + $sorted[$middle]) / 2, 1);
    }

    private function average(Collection $minutes): ?float
    {
        return $minutes->isEmpty() ? null : round($minutes->avg(), 1);
    }
}
