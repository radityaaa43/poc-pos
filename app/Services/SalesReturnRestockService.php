<?php

namespace App\Services;

use App\Models\ProductWarehouse;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;

/** Puts a completed return's ticked lines back: the menu, then its options' ingredients as sold (REQ-008 AC-14). */
class SalesReturnRestockService
{
    public function __construct(
        private readonly ComponentStockService $componentStock,
        private readonly StockMutationService $stockMutationService,
    ) {}

    /** Call inside the return's DB::transaction, after the sale is locked and before any other stock row is touched. */
    public function restock(SalesReturn $salesReturn, ?int $userId): void
    {
        $salesReturn->loadMissing(['items.product', 'items.transactionDetail.modifiers']);
        $items = $salesReturn->items->where('restock_to_inventory', true);
        $warehouseId = $salesReturn->transaction->warehouse_id;

        $this->componentStock->lockRows($items->pluck('product_id')->merge(
            $items->flatMap(fn (SalesReturnItem $item) => $item->transactionDetail->modifiers->flatMap->components->pluck('product_id'))
        ), $warehouseId);

        foreach ($items as $item) {
            $this->restockLine($salesReturn, $item, $warehouseId, $userId);

            foreach ($this->componentStock->restockOptions($item->transactionDetail, (int) $item->qty_return, $warehouseId) as $row) {
                $this->stockMutationService->recordSalesReturnRestock(
                    product: $row['product'],
                    salesReturn: $salesReturn,
                    stockBefore: $row['stock_before'],
                    stockAfter: $row['stock_before'] + $row['qty'],
                    reason: $item->return_reason,
                    userId: $userId,
                );
            }
        }
    }

    private function restockLine(SalesReturn $salesReturn, SalesReturnItem $item, ?int $warehouseId, ?int $userId): void
    {
        $product = $item->product ? $item->product()->lockForUpdate()->first() : null;

        if (! $product) {
            return;
        }

        // Stock effect is in base units: selling qty x conversion factor.
        $baseQty = (int) round((int) $item->qty_return * (float) ($item->transactionDetail->conversion_factor ?? 1));
        $stockBefore = (int) $product->stock;
        $stockAfter = $stockBefore + $baseQty;

        $product->update(['stock' => $stockAfter]);

        // Restock to transaction warehouse
        if ($warehouseId) {
            ProductWarehouse::where([
                'product_id' => $product->id,
                'warehouse_id' => $warehouseId,
            ])->increment('stock', $baseQty);
        }

        $this->stockMutationService->recordSalesReturnRestock(
            product: $product,
            salesReturn: $salesReturn,
            stockBefore: $stockBefore,
            stockAfter: $stockAfter,
            reason: $item->return_reason,
            userId: $userId,
        );
    }
}
