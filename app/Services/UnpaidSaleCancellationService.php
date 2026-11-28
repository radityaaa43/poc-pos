<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\TransactionDetailModifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one way an unpaid sale is cancelled: stock goes back and the sale is marked failed.
 * Used by `transactions:expire` and by the shift close (REQ-003 AC-20). Sales returns restock through
 * `lockStockRows` and `restock` (SalesReturnRestockService), so recipes go back the same way (#486).
 */
class UnpaidSaleCancellationService
{
    public function __construct(
        private readonly StockMutationService $stockMutationService,
        private readonly ComponentStockService $componentStock,
    ) {}

    /**
     * The caller owns the DB::transaction, holds the row lock on `$sale` and has checked it may still be cancelled.
     */
    public function cancel(Transaction $sale, string $mutationNote, string $saleNote): void
    {
        $sale->loadMissing(['details.product.components', 'details.modifiers', 'details.batchAllocations']);
        $this->lockStockRows($sale->details, $sale->warehouse_id);

        // Completed, restock-ticked returns already put part of this sale back; expiry must not put it back twice.
        $returned = $this->returnedBaseQtyByDetail($sale);

        foreach ($sale->details as $detail) {
            $product = $detail->product;

            if (! $product) {
                continue;
            }

            $warehouseId = $sale->warehouse_id;

            // A batch's stock is never put back by a return, so it always goes back in full.
            foreach ($detail->batchAllocations as $allocation) {
                DB::table('product_batches')
                    ->where('id', $allocation->product_batch_id)
                    ->increment('stock', $allocation->qty);
            }

            // Stock effect is in base units: selling qty x conversion factor, less what a completed return already restocked.
            // Clamped at 0 (a fully returned line) and never skipped: a qty-0 mutation still writes the cancelled marker.
            $baseQty = max(0, $detail->baseQuantity() - ($returned[$detail->id] ?? 0));

            $this->restock($product, $warehouseId, $baseQty);

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

            foreach ($this->componentStock->restockOptions($detail, (int) $detail->qty, $warehouseId) as $row) {
                $this->stockMutationService->recordMutation(
                    product: $row['product'],
                    warehouseId: $warehouseId,
                    referenceType: 'transaction_expire',
                    referenceId: $sale->id,
                    mutationType: 'in',
                    qty: $row['qty'],
                    stockBefore: $row['stock_before'],
                    stockAfter: $row['stock_before'] + $row['qty'],
                    notes: "{$mutationNote} (opsi)",
                    userId: null,
                );
            }
        }

        $sale->update([
            'payment_status' => 'failed',
            'note' => trim(($sale->note ? $sale->note.' | ' : '').$saleNote),
        ]);
    }

    /**
     * Base units of this sale a completed, restock-ticked return already put back, per transaction_detail_id.
     * A locking read after the sale lock so a return completing meanwhile is not missed (REPEATABLE READ, #504).
     *
     * @return array<int, int>
     */
    private function returnedBaseQtyByDetail(Transaction $sale): array
    {
        // Two steps: lock only the completed returns first, so a return still being drafted is not locked, then their items.
        $returnIds = DB::table('sales_returns')
            ->where('transaction_id', $sale->id)
            ->where('status', 'completed')
            ->lockForUpdate()
            ->pluck('id');

        if ($returnIds->isEmpty()) {
            return [];
        }

        $rows = DB::table('sales_return_items as i')
            ->join('transaction_details as d', 'd.id', '=', 'i.transaction_detail_id')
            ->whereIn('i.sales_return_id', $returnIds)
            ->where('i.restock_to_inventory', true)
            ->select('i.transaction_detail_id', 'i.qty_return', 'd.conversion_factor')
            ->lockForUpdate()
            ->get();

        // Round each row before summing, so a fractional recipe qty never restocks one unit more than was sold.
        return $rows->groupBy('transaction_detail_id')
            ->map(fn (Collection $items) => (int) $items->sum(
                fn ($row) => (int) round((int) $row->qty_return * (float) $row->conversion_factor),
            ))
            ->all();
    }

    /**
     * Locks the stock rows a restock of these sale or return lines writes (warehouse rows then products, checkout's
     * order, by product id), so each mutation records the before and after the restock really made.
     */
    public function lockStockRows(Collection $lines, ?int $warehouseId): void
    {
        $ids = $lines
            ->flatMap(fn ($line) => [$line->product_id, ...($line->product ? $line->product->components->modelKeys() : [])])
            ->merge($lines
                ->filter(fn ($line) => $line instanceof TransactionDetail)
                ->flatMap(fn ($line) => $line->modifiers->flatMap(
                    fn (TransactionDetailModifier $modifier) => collect($modifier->components)->pluck('product_id'),
                )))
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
