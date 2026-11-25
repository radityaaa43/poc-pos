<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use Illuminate\Support\Collection;

class ReorderService
{
    public function getLowStockProducts(?int $warehouseId = null): Collection
    {
        $query = Product::where('min_stock', '>', 0);

        if ($warehouseId) {
            $query->whereHas('warehouses', function ($q) use ($warehouseId) {
                $q->where('product_warehouse.warehouse_id', $warehouseId)
                    ->whereColumn('product_warehouse.stock', '<=', 'products.min_stock');
            });

            // Raw materials are bought by central or by the outlet; other products have no purchased_by.
            $purchasedBy = Warehouse::with('outlet')->find($warehouseId)?->isCentral() ? 'central' : 'outlet';
            $query->where(fn ($q) => $q->whereNull('purchased_by')->orWhere('purchased_by', $purchasedBy));
        } else {
            $query->whereColumn('stock', '<=', 'min_stock');
        }

        return $query->orderBy('stock')->limit(20)->get();
    }

    public function createDraftPurchaseOrder(Collection $products, int $userId, ?int $warehouseId = null): ?PurchaseOrder
    {
        $items = $products->filter(fn ($p) => $p->suggestedOrderQty($warehouseId) > 0)
            ->map(fn ($p) => $this->orderLine($p, $p->suggestedOrderQty($warehouseId)))
            ->values()->toArray();

        if (empty($items)) {
            return null;
        }

        $data = ['notes' => 'Auto-generated from restock suggestion'];
        if ($warehouseId) {
            $data['warehouse_id'] = $warehouseId;
        }

        return app(PurchaseOrderService::class)->createOrder($data, $items, $userId);
    }

    /**
     * Raw materials are ordered in their default purchase unit (rounded up, at that unit's price);
     * without one, or for non-raw products, the line stays in stock units.
     */
    private function orderLine(Product $product, int $stockQty): array
    {
        $line = ['product_id' => $product->id, 'qty_ordered' => $stockQty, 'unit_price' => $product->buy_price];

        $unit = $product->purchased_by
            ? $product->units()->wherePivot('is_purchase_default', true)->first()
            : null;

        if (! $unit) {
            return $line;
        }

        $factor = (float) $unit->pivot->conversion_factor;

        return [
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'qty_ordered' => (int) ceil(round($stockQty / $factor, 6)),
            'unit_price' => $unit->pivot->buy_price ?: (int) round($product->buy_price * $factor),
        ];
    }
}
