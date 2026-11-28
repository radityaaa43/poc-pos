<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockWaste;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What recorded waste cost (REQ-011), read only from the stored waste rows. A record recorded in the
 * period adds its stored total_value; one cancelled in the period subtracts it, so a cancellation in
 * a later period reduces that period. Pending and rejected records never count: the stock never left.
 */
class StockWasteReportService
{
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
