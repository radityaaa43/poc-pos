<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * The one way an unpaid sale is cancelled: stock goes back and the sale is marked failed.
 * Used by `transactions:expire` and by the shift close (REQ-003 AC-20).
 */
class UnpaidSaleCancellationService
{
    public function __construct(private readonly StockMutationService $stockMutationService) {}

    /**
     * The caller owns the DB::transaction, holds the row lock on `$sale` and has checked it may still be cancelled.
     */
    public function cancel(Transaction $sale, string $mutationNote, string $saleNote): void
    {
        $sale->loadMissing(['details.product.components', 'details.batchAllocations']);
        $this->lockStockRows($sale);

        foreach ($sale->details as $detail) {
            $product = $detail->product;

            if (! $product) {
                continue;
            }

            $warehouseId = $sale->warehouse_id;

            // Stock effect is in base units: selling qty x conversion factor.
            $baseQty = $detail->baseQuantity();

            $this->restock($product, $warehouseId, $baseQty);

            foreach ($detail->batchAllocations as $allocation) {
                DB::table('product_batches')
                    ->where('id', $allocation->product_batch_id)
                    ->increment('stock', $allocation->qty);
            }

            $this->stockMutationService->recordMutation(
                product: $product,
                warehouseId: $warehouseId,
                // Also the "this sale was cancelled" marker (Transaction::wasRestocked): every canceller must write it.
                referenceType: 'transaction_expire',
                referenceId: $sale->id,
                mutationType: 'in',
                qty: $baseQty,
                stockBefore: $this->stockAfterBefore($product, $warehouseId, $baseQty),
                stockAfter: $this->stockAfterBefore($product, $warehouseId, 0),
                notes: $mutationNote,
                userId: null,
            );
        }

        $sale->update([
            'payment_status' => 'failed',
            'note' => trim(($sale->note ? $sale->note.' | ' : '').$saleNote),
        ]);
    }

    /**
     * Locks the stock rows the restock writes (warehouse rows then products, checkout's order, by product id), so
     * each mutation records the before and after this restock really made.
     */
    private function lockStockRows(Transaction $sale): void
    {
        $ids = $sale->details
            ->flatMap(fn ($detail) => [$detail->product_id, ...($detail->product ? $detail->product->components->modelKeys() : [])])
            ->unique()->sort()->values();

        DB::table('product_warehouse')->where('warehouse_id', $sale->warehouse_id)->whereIn('product_id', $ids)
            ->orderBy('product_id')->lockForUpdate()->get();
        DB::table('products')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    private function restock($product, ?int $warehouseId, int $qty): void
    {
        if ($product->is_composite) {
            foreach ($product->components as $component) {
                $componentQty = (int) round((float) $component->pivot->qty * $qty);
                $this->restock($component, $warehouseId, $componentQty);
            }

            return;
        }

        if ($warehouseId) {
            DB::table('product_warehouse')
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->increment('stock', $qty);
        }

        $product->increment('stock', $qty);
    }

    // ponytail: re-reads stock per detail; fine for an hourly batch of few rows
    private function stockAfterBefore($product, ?int $warehouseId, int $offset): int
    {
        $current = (int) ($warehouseId
            ? DB::table('product_warehouse')
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->value('stock')
            : $product->fresh()->stock);

        return $current - $offset;
    }
}
