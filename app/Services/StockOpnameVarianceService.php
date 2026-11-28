<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StockWaste;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * REQ-011 success metric: the average absolute stock-opname variance per outlet, month by month,
 * before and after the outlet started recording waste. Read-only.
 *
 * A variance is valued at the unit cost stored on the finalize audit entry (the average cost at the
 * moment the opname was finalized). An opname finalized before that cost was stored has no snapshot,
 * so it falls back to today's average cost and is flagged estimated.
 * shortcut: the snapshot lives in audit_logs.meta (JSON) because no opname cost column exists and
 * this ticket adds no migration; move it to a column when one is added.
 */
class StockOpnameVarianceService
{
    public function __construct(private readonly InventoryCostService $inventoryCostService) {}

    /** Unit cost per product id, snapshotted into the finalize audit entry. */
    public function snapshotUnitCosts(StockOpname $opname): array
    {
        return $this->inventoryCostService->unitCosts(
            Product::whereIn('id', StockOpnameItem::where('stock_opname_id', $opname->id)->pluck('product_id'))->get(['id', 'buy_price']),
            $opname->warehouse_id
        );
    }

    /**
     * @param  array<int, int>  $warehouseIds
     * @return array{months: list<array{month: string, opnames: int, avg_value: int|null, avg_items_off: float|null, phase: string, estimated: bool}>, waste_started_month: string|null}
     */
    public function byMonth(array $warehouseIds, CarbonInterface $now): array
    {
        $tz = config('app.timezone');
        $start = $now->copy()->setTimezone($tz)->startOfMonth()->subMonths(11);

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $months[] = $start->copy()->addMonths($i)->format('Y-m');
        }

        $opnames = DB::table('stock_opnames')
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('status', 'finalized')
            ->whereNotNull('finalized_at')
            ->where('finalized_at', '>=', $start->format('Y-m-d H:i:s'))
            ->get(['id', 'warehouse_id', 'finalized_at']);

        $opnameIds = $opnames->pluck('id')->all();
        $items = $opnameIds === [] ? collect() : DB::table('stock_opname_items')
            ->whereIn('stock_opname_id', $opnameIds)
            ->whereNotNull('difference')
            ->where('difference', '!=', 0)
            ->get(['stock_opname_id', 'product_id', 'difference']);
        $itemsByOpname = $items->groupBy('stock_opname_id');

        $products = Product::whereIn('id', $items->pluck('product_id')->unique()->values()->all())
            ->get(['id', 'buy_price']);
        $fallbackCosts = [];
        $fallback = function (int $warehouseId) use (&$fallbackCosts, $products): array {
            return $fallbackCosts[$warehouseId] ??= $this->inventoryCostService->unitCosts($products, $warehouseId);
        };

        $storedCosts = $this->storedUnitCosts($opnameIds);

        $byMonth = [];
        foreach ($opnames as $opname) {
            $month = Carbon::parse($opname->finalized_at, $tz)->format('Y-m');
            $value = 0;
            $count = 0;
            $estimated = false;
            foreach ($itemsByOpname[$opname->id] ?? [] as $item) {
                $cost = $storedCosts[$opname->id][$item->product_id] ?? null;
                if ($cost === null) {
                    $cost = $fallback((int) $opname->warehouse_id)[$item->product_id] ?? 0.0;
                    $estimated = true;
                }
                $value += abs((int) $item->difference) * $cost;
                $count++;
            }

            $byMonth[$month] ??= ['opnames' => 0, 'value' => 0, 'items' => 0, 'estimated' => false];
            $byMonth[$month]['opnames']++;
            $byMonth[$month]['value'] += (int) round($value);
            $byMonth[$month]['items'] += $count;
            $byMonth[$month]['estimated'] = $byMonth[$month]['estimated'] || $estimated;
        }

        $wasteStartedMonth = $this->wasteStartedMonth($warehouseIds, $tz);

        $rows = [];
        foreach ($months as $month) {
            $bucket = $byMonth[$month] ?? null;
            $opnamesCount = $bucket['opnames'] ?? 0;
            $rows[] = [
                'month' => $month,
                'opnames' => $opnamesCount,
                'avg_value' => $opnamesCount > 0 ? (int) round($bucket['value'] / $opnamesCount) : null,
                'avg_items_off' => $opnamesCount > 0 ? round($bucket['items'] / $opnamesCount, 1) : null,
                'phase' => $wasteStartedMonth !== null && $month >= $wasteStartedMonth ? 'after' : 'before',
                'estimated' => $opnamesCount > 0 && (bool) ($bucket['estimated'] ?? false),
            ];
        }

        return ['months' => $rows, 'waste_started_month' => $wasteStartedMonth];
    }

    /** @return array<int, array<int, float>> opname id => product id => stored unit cost */
    private function storedUnitCosts(array $opnameIds): array
    {
        if ($opnameIds === []) {
            return [];
        }

        $rows = DB::table('audit_logs')
            ->where('event', 'stock.opname.finalized')
            ->where('auditable_type', (new StockOpname)->getMorphClass())
            ->whereIn('auditable_id', $opnameIds)
            ->get(['auditable_id', 'meta']);

        $costs = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) $row->meta, true);
            foreach ($meta['items'] ?? [] as $item) {
                if (isset($item['product_id'], $item['unit_cost'])) {
                    $costs[(int) $row->auditable_id][(int) $item['product_id']] = (float) $item['unit_cost'];
                }
            }
        }

        return $costs;
    }

    private function wasteStartedMonth(array $warehouseIds, string $tz): ?string
    {
        $first = DB::table('stock_wastes')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('status', [StockWaste::STATUS_RECORDED, StockWaste::STATUS_CANCELLED])
            ->whereNotNull('recorded_at')
            ->min('recorded_at');

        return $first ? Carbon::parse($first, $tz)->format('Y-m') : null;
    }
}
