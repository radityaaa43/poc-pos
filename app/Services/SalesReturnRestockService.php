<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\SalesReturn;

/** Puts a completed return's ticked lines back; a recipe menu gives back its components, not itself (#486). */
class SalesReturnRestockService
{
    public function __construct(
        private readonly UnpaidSaleCancellationService $saleStock,
        private readonly StockMutationService $stockMutationService,
    ) {}

    /** Call inside the return's DB::transaction, after the sale is locked and before any other stock row is touched. */
    public function restock(SalesReturn $salesReturn, ?int $warehouseId, ?int $userId): void
    {
        // items() and a query, not the property: Larastan cannot see the untyped SalesReturn relations.
        $items = $salesReturn->items()->with(['product.components', 'transactionDetail'])
            ->where('restock_to_inventory', true)->orderBy('id')->get()
            ->filter(fn ($item) => $item->product);
        $this->saleStock->lockStockRows($items, $warehouseId);

        foreach ($items as $item) {
            // Stock effect is in base units: selling qty x conversion factor.
            $baseQty = (int) round((int) $item->qty_return * (float) ($item->transactionDetail->conversion_factor ?? 1));

            foreach ($this->saleStock->restock($item->product, $warehouseId, $baseQty) as ['product' => $product, 'qty' => $qty]) {
                // A component records its warehouse stock, as its sale did; a plain product its total. Locking reads: a
                // plain one can be older than the row lock (REPEATABLE READ).
                $inWarehouse = $item->product->is_composite
                    ? ProductWarehouse::where(['product_id' => $product->id, 'warehouse_id' => $warehouseId])->lockForUpdate()->value('stock')
                    : null;
                $stockAfter = (int) ($inWarehouse ?? Product::whereKey($product->id)->lockForUpdate()->value('stock'));

                $this->stockMutationService->recordSalesReturnRestock(
                    product: $product,
                    salesReturn: $salesReturn,
                    stockBefore: $stockAfter - $qty,
                    stockAfter: $stockAfter,
                    reason: $item->return_reason,
                    userId: $userId,
                );
            }
        }
    }
}
