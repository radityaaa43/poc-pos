<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductWarehouse;

/**
 * Weighted-average cost per warehouse (REQ-002 §6.5). Rupiah per stock unit, kept in
 * product_warehouse.avg_cost; products.buy_price is the reference price and the fallback.
 */
class InventoryCostService
{
    public function unitCost(Product $product, ?int $warehouseId): float
    {
        $avg = $warehouseId
            ? ProductWarehouse::where(['product_id' => $product->id, 'warehouse_id' => $warehouseId])->value('avg_cost')
            : null;

        return (float) ($avg ?? $product->buy_price);
    }

    /**
     * HPP of a sale line, whole rupiah (REQ-002 §6.6): plain = unit cost x qty x factor;
     * recipe = sum of component unit costs x recipe qty x qty. Read it before the stock decrement.
     */
    public function lineCost(Product $product, ?int $warehouseId, float $qty, float $factor = 1.0): int
    {
        if ($product->is_composite) {
            return (int) round($product->components->sum(
                fn ($component) => $this->unitCost($component, $warehouseId) * (float) $component->pivot->qty
            ) * $qty);
        }

        return (int) round($this->unitCost($product, $warehouseId) * $qty * $factor);
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
