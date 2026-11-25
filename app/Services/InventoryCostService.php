<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;

/**
 * Weighted-average cost per warehouse (REQ-002 §6.5). Rupiah per stock unit, kept in
 * product_warehouse.avg_cost. A product costs: the selling warehouse's average, else the central
 * warehouse's average (raw materials are bought by central or by the outlet), else the reference
 * price products.buy_price.
 */
class InventoryCostService
{
    public function unitCost(Product $product, ?int $warehouseId): float
    {
        return $this->unitCosts(new Collection([$product]), $warehouseId)[$product->id];
    }

    /**
     * unitCost() for many products in two queries.
     *
     * @return array<int, float> product id => rupiah per stock unit
     */
    public function unitCosts(Collection $products, ?int $warehouseId): array
    {
        $averages = fn (?int $id) => $id
            ? ProductWarehouse::where('warehouse_id', $id)->whereIn('product_id', $products->modelKeys())
                ->whereNotNull('avg_cost')->pluck('avg_cost', 'product_id')
            : collect();
        $own = $averages($warehouseId);
        $central = $warehouseId ? $averages($this->centralWarehouseId()) : collect();

        return $products->mapWithKeys(fn (Product $p) => [$p->id => (float) ($own[$p->id] ?? $central[$p->id] ?? $p->buy_price)])->all();
    }

    /**
     * HPP of a sale line, whole rupiah (REQ-002 §6.6): plain = unit cost x qty x factor;
     * recipe = sum of component unit costs x recipe qty x qty. Read it before the stock decrement.
     */
    public function lineCost(Product $product, ?int $warehouseId, float $qty, float $factor = 1.0): int
    {
        if ($product->is_composite) {
            $costs = $this->unitCosts($product->components, $warehouseId);

            return (int) round($product->components->sum(
                fn ($component) => $costs[$component->id] * (float) $component->pivot->qty
            ) * $qty);
        }

        return (int) round($this->unitCost($product, $warehouseId) * $qty * $factor);
    }

    /** The warehouse of an outlet that does not sell (ReorderService::isCentral); the lowest sort_order, then id, when several. */
    private function centralWarehouseId(): ?int
    {
        return Warehouse::central()->orderBy('sort_order')->orderBy('id')->value('id');
    }

    /**
     * Adds $qty stock units bought at $cost each to the warehouse and merges the cost into its average.
     * Call inside a DB::transaction; the pivot row is created when missing and locked here.
     */
    public function receive(Product $product, int $warehouseId, int $qty, float $cost): void
    {
        $key = ['product_id' => $product->id, 'warehouse_id' => $warehouseId];
        ProductWarehouse::firstOrCreate($key, ['stock' => 0]);
        $row = ProductWarehouse::where($key)->lockForUpdate()->firstOrFail();

        $avg = $row->stock <= 0 || $row->avg_cost === null
            ? $cost
            : ($row->stock * $row->avg_cost + $qty * $cost) / ($row->stock + $qty);

        $row->update(['stock' => $row->stock + $qty, 'avg_cost' => round($avg, 4)]);
    }
}
