<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockWaste;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What recorded waste cost (REQ-011), read only from the stored waste rows. A record recorded in the
 * period adds its stored total_value; one cancelled in the period subtracts it, so a cancellation in
 * a later period reduces that period. Pending and rejected records never count: the stock never left.
 */
class StockWasteReportService
{
    public function __construct(private readonly StockWasteService $stockWasteService) {}

    public function costTotal(array $warehouseIds, ?int $warehouseId, ?string $start, ?string $end): int
    {
        return (int) $this->recorded($warehouseIds, $warehouseId, $start, $end)->sum('total_value')
            - (int) $this->cancelled($warehouseIds, $warehouseId, $start, $end)->sum('total_value');
    }

    /**
     * @return array{items: list<array{product_id: int, title: string, is_composite: bool, qty: int, value: int}>, reasons: list<array{reason: string, records: int, value: int}>, total_value: int}
     */
    public function report(array $warehouseIds, ?string $start, ?string $end): array
    {
        $recorded = fn (): Builder => $this->recorded($warehouseIds, null, $start, $end);
        $cancelled = fn (): Builder => $this->cancelled($warehouseIds, null, $start, $end);

        $qty = $this->minus($this->quantities($recorded()), $this->quantities($cancelled()));
        $value = $this->minus($this->values($recorded()), $this->values($cancelled()));
        $products = Product::whereIn('id', array_keys($qty + $value))
            ->get(['id', 'title', 'is_composite'])->keyBy('id');

        $items = [];
        foreach (array_keys($qty + $value) as $id) {
            $productQty = $qty[$id] ?? 0;
            $productValue = $value[$id] ?? 0;
            if ($productQty === 0 && $productValue === 0) {
                continue;
            }
            $product = $products[$id];
            $items[] = [
                'product_id' => $id,
                'title' => $product->title,
                'is_composite' => (bool) $product->is_composite,
                'qty' => $productQty,
                'value' => $productValue,
            ];
        }
        usort($items, fn (array $a, array $b) => $b['value'] <=> $a['value']);

        $recordedReasons = $this->reasons($recorded());
        $cancelledReasons = $this->reasons($cancelled());
        $reasons = [];
        foreach (array_keys($recordedReasons + $cancelledReasons) as $reason) {
            $records = ($recordedReasons[$reason]['records'] ?? 0) - ($cancelledReasons[$reason]['records'] ?? 0);
            $reasonValue = ($recordedReasons[$reason]['value'] ?? 0) - ($cancelledReasons[$reason]['value'] ?? 0);
            if ($records === 0 && $reasonValue === 0) {
                continue;
            }
            $reasons[] = ['reason' => $reason, 'records' => $records, 'value' => $reasonValue];
        }
        usort($reasons, fn (array $a, array $b) => $b['value'] <=> $a['value']);

        return [
            'items' => $items,
            'reasons' => $reasons,
            'total_value' => $this->costTotal($warehouseIds, null, $start, $end),
        ];
    }

    /**
     * Waste per (recorded day, recorder) with a count of records at or near the outlet's approval limit,
     * so a run of small records under the limit stands next to the large one it replaced. A cancellation
     * subtracts on its own day for the original recorder, the same way reasons() nets it.
     *
     * @return list<array{date: string, user_id: ?int, user_name: ?string, records: int, value: int, near_limit: int}>
     */
    public function byRecorderAndDay(array $warehouseIds, ?string $start, ?string $end): array
    {
        // shortcut: aggregated in PHP, move to SQL beyond ~50k records a month
        $recorded = $this->recorded($warehouseIds, null, $start, $end)
            ->get(['id', 'created_by', 'warehouse_id', 'total_value', 'recorded_at']);
        $cancelled = $this->cancelled($warehouseIds, null, $start, $end)
            ->get(['id', 'created_by', 'warehouse_id', 'total_value', 'cancelled_at']);
        $limits = $this->limitsByWarehouse($warehouseIds);

        $rows = [];
        $row = function (string $date, ?int $userId) use (&$rows): string {
            $key = $date.'|'.($userId ?? '');
            $rows[$key] ??= ['date' => $date, 'user_id' => $userId, 'records' => 0, 'value' => 0, 'near_limit' => 0];

            return $key;
        };

        foreach ($recorded as $record) {
            $value = (int) $record->total_value;
            $key = $row(substr((string) $record->recorded_at, 0, 10), $record->created_by === null ? null : (int) $record->created_by);
            $rows[$key]['records']++;
            $rows[$key]['value'] += $value;
            $rows[$key]['near_limit'] += $this->isNearLimit($value, $limits[(int) $record->warehouse_id] ?? null) ? 1 : 0;
        }
        foreach ($cancelled as $record) {
            $value = (int) $record->total_value;
            $key = $row(substr((string) $record->cancelled_at, 0, 10), $record->created_by === null ? null : (int) $record->created_by);
            $rows[$key]['records']--;
            $rows[$key]['value'] -= $value;
            $rows[$key]['near_limit'] -= $this->isNearLimit($value, $limits[(int) $record->warehouse_id] ?? null) ? 1 : 0;
        }

        $rows = array_filter($rows, fn (array $row) => $row['records'] !== 0 || $row['value'] !== 0 || $row['near_limit'] !== 0);
        $names = User::whereIn('id', array_values(array_unique(array_filter(array_column($rows, 'user_id')))))
            ->pluck('name', 'id');

        $result = array_map(fn (array $row): array => [
            'date' => $row['date'],
            'user_id' => $row['user_id'],
            'user_name' => $row['user_id'] === null ? null : ($names[$row['user_id']] ?? null),
            'records' => $row['records'],
            'value' => $row['value'],
            'near_limit' => $row['near_limit'],
        ], array_values($rows));

        usort($result, fn (array $a, array $b) => strcmp($b['date'], $a['date']) ?: ($b['value'] <=> $a['value']));

        return $result;
    }

    /**
     * Sales cost of the period, the denominator of the waste percent: the frozen cost_total of the
     * outlet's sales, minus the cost of completed returns that restocked the goods (#551 BR-1: a
     * cooked-food return keeps its cost). A sale cancelled for good is left out: its stock came back.
     */
    public function salesCost(array $warehouseIds, ?string $start, ?string $end): int
    {
        $sales = Transaction::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->when($start, fn ($query) => $query->whereDate('created_at', '>=', $start))
            ->when($end, fn ($query) => $query->whereDate('created_at', '<=', $end))
            ->notRestocked();

        // SUM ignores null: a line without a frozen cost counts 0.
        $cost = (int) DB::table('transaction_details')
            ->whereIn('transaction_id', (clone $sales)->select('transactions.id'))
            ->sum('cost_total');

        // Per return item, rounded once; a line returned in parts can differ by 1 Rp from a running share.
        $returns = DB::table('sales_return_items as sri')
            ->join('sales_returns as sr', 'sr.id', '=', 'sri.sales_return_id')
            ->join('transaction_details as td', 'td.id', '=', 'sri.transaction_detail_id')
            ->whereIn('sr.transaction_id', (clone $sales)->select('transactions.id'))
            ->where('sr.status', 'completed')
            ->where('sri.restock_to_inventory', true)
            ->get(['sri.qty_return', 'td.qty', 'td.cost_total']);

        foreach ($returns as $item) {
            $cost -= (int) round((int) ($item->cost_total ?? 0) * (int) $item->qty_return / max(1, (int) $item->qty));
        }

        return $cost;
    }

    /** @return array<int, int> warehouse id => the outlet's current approval limit */
    private function limitsByWarehouse(array $warehouseIds): array
    {
        $outletIds = Warehouse::whereIn('id', $warehouseIds)->pluck('outlet_id', 'id');
        $outlets = Outlet::whereIn('id', $outletIds->unique()->values()->all())->get()->keyBy('id');

        return $outletIds
            ->mapWithKeys(fn ($outletId, $warehouseId) => [(int) $warehouseId => $this->stockWasteService->approvalLimit($outlets[$outletId] ?? null)])
            ->all();
    }

    /** A record at the outlet's limit or up to 10% below it, in whole rupiah. */
    private function isNearLimit(int $value, ?int $limit): bool
    {
        $limit ??= $this->stockWasteService->approvalLimit(null);

        return $value <= $limit && $value * 10 >= $limit * 9;
    }

    /** Records that were recorded (or recorded then cancelled) inside the period. */
    private function recorded(array $warehouseIds, ?int $warehouseId, ?string $start, ?string $end): Builder
    {
        return DB::table('stock_wastes')
            ->whereIn('warehouse_id', $warehouseIds)
            ->when($warehouseId !== null, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->whereIn('status', [StockWaste::STATUS_RECORDED, StockWaste::STATUS_CANCELLED])
            ->when($start, fn (Builder $query) => $query->whereDate('recorded_at', '>=', $start))
            ->when($end, fn (Builder $query) => $query->whereDate('recorded_at', '<=', $end));
    }

    /** Records cancelled inside the period, whichever period recorded them. */
    private function cancelled(array $warehouseIds, ?int $warehouseId, ?string $start, ?string $end): Builder
    {
        return DB::table('stock_wastes')
            ->whereIn('warehouse_id', $warehouseIds)
            ->when($warehouseId !== null, fn (Builder $query) => $query->where('warehouse_id', $warehouseId))
            ->where('status', StockWaste::STATUS_CANCELLED)
            ->when($start, fn (Builder $query) => $query->whereDate('cancelled_at', '>=', $start))
            ->when($end, fn (Builder $query) => $query->whereDate('cancelled_at', '<=', $end));
    }

    /** @return array<int, int> product id => quantity of the menus/products the user typed */
    private function quantities(Builder $query): array
    {
        return $query->join('stock_waste_lines as l', 'l.stock_waste_id', '=', 'stock_wastes.id')
            ->select('l.product_id', DB::raw('SUM(l.qty) as qty'))
            ->groupBy('l.product_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->product_id => (int) $row->qty])
            ->all();
    }

    /** @return array<int, int> product id => value of what left the shelf, a menu on its own line */
    private function values(Builder $query): array
    {
        return $query->join('stock_waste_moves as m', 'm.stock_waste_id', '=', 'stock_wastes.id')
            ->join('stock_waste_lines as l', 'l.id', '=', 'm.line_id')
            ->select('l.product_id', DB::raw('SUM(m.value) as value'))
            ->groupBy('l.product_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->product_id => (int) $row->value])
            ->all();
    }

    /** @return array<string, array{records: int, value: int}> */
    private function reasons(Builder $query): array
    {
        return $query->select('reason', DB::raw('COUNT(*) as records'), DB::raw('SUM(total_value) as value'))
            ->groupBy('reason')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->reason => ['records' => (int) $row->records, 'value' => (int) $row->value]])
            ->all();
    }

    /** @return array<int, int> */
    private function minus(array $recorded, array $cancelled): array
    {
        foreach ($cancelled as $key => $value) {
            $recorded[$key] = ($recorded[$key] ?? 0) - $value;
        }

        return $recorded;
    }
}
