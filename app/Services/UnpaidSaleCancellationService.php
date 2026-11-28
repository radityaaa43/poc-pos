<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one way an unpaid sale is cancelled: stock goes back and the sale is marked failed.
 * Used by `transactions:expire` and by the shift close (REQ-003 AC-20). Sales returns restock through
 * `lockStockRows` and `restock` (SalesReturnRestockService), so recipes go back the same way (#486).
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
        $this->lockStockRows($sale->details, $sale->warehouse_id);

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
     * Locks the stock rows a restock of these sale or return lines writes (warehouse rows then products, checkout's
     * order, by product id), so each mutation records the before and after the restock really made.
     */
    public function lockStockRows(Collection $lines, ?int $warehouseId): void
    {
        $ids = $lines
            ->flatMap(fn ($line) => [$line->product_id, ...($line->product ? $line->product->components->modelKeys() : [])])
            ->unique()->sort()->values();

        DB::table('product_warehouse')->where('warehouse_id', $warehouseId)->whereIn('product_id', $ids)
            ->orderBy('product_id')->lockForUpdate()->get();
        DB::table('products')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
    }

    /**
     * Adds `$qty` base units back to both stock tables; a recipe gets its components back instead. Lock the rows first.
     *
     * @return list<array{product: Product, qty: int}> the products whose stock moved
     */
    public function restock($product, ?int $warehouseId, int $qty): array
    {
        if ($product->is_composite) {
            $moved = [];

            foreach ($product->components as $component) {
                $componentQty = (int) round((float) $component->pivot->qty * $qty);
                array_push($moved, ...$this->restock($component, $warehouseId, $componentQty));
            }

            return $moved;
        }

        if ($warehouseId) {
            DB::table('product_warehouse')
                ->where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->increment('stock', $qty);
        }

        $product->increment('stock', $qty);

        return [['product' => $product, 'qty' => $qty]];
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
