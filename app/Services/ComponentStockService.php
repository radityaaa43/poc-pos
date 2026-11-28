<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Illuminate\Validation\ValidationException;

/**
 * Stock taken by recipes: a composite menu's components and each chosen option's (REQ-008 BR-3). Call inside the
 * sale's DB::transaction; `product_warehouse.stock` and `products.stock` change together.
 */
class ComponentStockService
{
    public function __construct(private readonly StockMutationService $stockMutationService) {}

    /**
     * Locks the warehouse stock rows a sale touches, one by one in product_id order, so two sales that share an
     * ingredient queue up instead of deadlocking. Call it before the first deduction.
     *
     * @param  iterable<int>  $productIds
     */
    public function lock(iterable $productIds, ?int $warehouseId): void
    {
        if (! $warehouseId) {
            return;
        }

        foreach (collect($productIds)->unique()->sort() as $productId) {
            ProductWarehouse::where(['product_id' => $productId, 'warehouse_id' => $warehouseId])->lockForUpdate()->first();
        }
    }

    /**
     * Takes what $qty sold units use of each component, or refuses the sale.
     *
     * @param  iterable<Product>  $components  each with pivot->qty, the amount one portion uses
     * @param  float  $perUnitQty  portions in one sold unit (1 for a recipe and for an option)
     */
    public function deduct(Transaction $sale, iterable $components, float $perUnitQty, int $qty, ?int $warehouseId, string $label, int $userId): void
    {
        foreach (collect($components)->sortBy('id') as $component) {
            $componentQty = (int) round((float) $component->pivot->qty * $perUnitQty * $qty);
            $pw = $warehouseId
                ? ProductWarehouse::where([
                    'product_id' => $component->id,
                    'warehouse_id' => $warehouseId,
                ])->lockForUpdate()->first()
                : null;
            // Without a warehouse row, read fresh: options sharing an ingredient hold separate copies of it.
            $available = (int) ($pw ? $pw->stock : Product::lockForUpdate()->findOrFail($component->id)->stock);
            if ($available < $componentQty) {
                throw ValidationException::withMessages([
                    'stock' => "Stok komponen {$component->title} tidak mencukupi. Tersedia: {$available}.",
                ]);
            }
            if ($pw) {
                $pw->decrement('stock', $componentQty);
            }
            $component->decrement('stock', $componentQty);

            $this->stockMutationService->recordMutation(
                product: $component,
                warehouseId: $warehouseId,
                referenceType: 'transaction',
                referenceId: $sale->id,
                mutationType: 'out',
                qty: $componentQty,
                stockBefore: $available,
                stockAfter: $available - $componentQty,
                notes: "Penjualan {$sale->invoice} ({$label})",
                userId: $userId,
            );
        }
    }

    /**
     * Gives back what the options of $qty sold units of a line took, in product_id order, from the recipe saved with
     * the sale. An ingredient deleted since is skipped. The caller records the stock mutations.
     *
     * @return list<array{product: Product, qty: int, stock_before: int}>
     */
    public function restockOptions(TransactionDetail $detail, int $qty, ?int $warehouseId): array
    {
        $taken = [];

        foreach ($detail->modifiers->flatMap->components as $line) {
            $componentQty = (int) round($line['qty_per_unit'] * $qty);
            $taken[$line['product_id']] = ($taken[$line['product_id']] ?? 0) + $componentQty;
        }

        ksort($taken);
        $restocked = [];

        foreach ($taken as $productId => $componentQty) {
            $pw = $warehouseId
                ? ProductWarehouse::where(['product_id' => $productId, 'warehouse_id' => $warehouseId])->lockForUpdate()->first()
                : null;
            $product = Product::lockForUpdate()->find($productId);

            if (! $product) {
                continue;
            }

            $restocked[] = ['product' => $product, 'qty' => $componentQty, 'stock_before' => (int) ($pw->stock ?? $product->stock)];
            $pw?->increment('stock', $componentQty);
            $product->increment('stock', $componentQty);
        }

        return $restocked;
    }
}
