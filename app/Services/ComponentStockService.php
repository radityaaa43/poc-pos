<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use Illuminate\Validation\ValidationException;

/** Stock taken by composite and option recipes (REQ-008 BR-3). Call inside the sale's DB::transaction. */
class ComponentStockService
{
    public function __construct(private readonly StockMutationService $stockMutationService) {}

    /** Call before the first deduction: locking in product_id order keeps sales sharing an ingredient deadlock-free. */
    public function lock(iterable $productIds, ?int $warehouseId): void
    {
        if (! $warehouseId) {
            return;
        }

        foreach (collect($productIds)->unique()->sort() as $productId) {
            ProductWarehouse::where(['product_id' => $productId, 'warehouse_id' => $warehouseId])->lockForUpdate()->first();
        }
    }

    /** @param  iterable<Product>  $components  each with pivot->qty per portion; takes $qty units' worth or refuses */
    public function deduct(Transaction $sale, iterable $components, float $perUnitQty, int $qty, ?int $warehouseId, string $label, int $userId, ?string $refusal = null): void
    {
        foreach (collect($components)->sortBy('id') as $component) {
            $componentQty = (int) round((float) $component->getRelationValue('pivot')?->qty * $perUnitQty * $qty);
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
                    ($refusal ? 'modifiers' : 'stock') => $refusal ?? "Stok komponen {$component->title} tidak mencukupi. Tersedia: {$available}.",
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

    /** @return list<array{product: Product, qty: int, stock_before: int}> from the sale's recipe; caller records mutations */
    public function restockOptions(TransactionDetail $detail, int $qty, ?int $warehouseId): array
    {
        $taken = $detail->modifiers->flatMap->components->groupBy('product_id')
            ->map(fn ($lines) => (int) $lines->sum(fn (array $line) => round($line['qty_per_unit'] * $qty)))
            ->sortKeys();
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
