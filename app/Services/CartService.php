<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Outlet;
use App\Models\Product;
use App\Services\Modifiers\ModifierSelection;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function __construct(private readonly UnitConversionService $unitConversion) {}

    /** Adds validated cart input to the active cart. A line merges only with the same unit, options and note. */
    public function add(int $cashierId, ?int $warehouseId, ?Outlet $outlet, array $data): void
    {
        $product = Product::find($data['product_id']);
        $inCart = Cart::baseQtyInCart($cashierId, (int) $data['product_id']);

        if (! $product) {
            $this->refuse('Product not found.');
        }

        if ($product->is_raw_material) {
            $this->refuse('Bahan baku tidak dapat dijual.');
        }

        // ponytail: composite branch skips unit logic; null unit_id is fine for composite carts
        $unitId = null;
        $conversionFactor = 1;

        // Composite: check component stock
        if ($product->is_composite) {
            $product->load('components');
            foreach ($product->components->whereInstanceOf(Product::class) as $component) {
                $needed = (float) $component->getRelationValue('pivot')?->qty * ($data['qty'] + $inCart);
                $whProduct = $component->warehouses()->where('warehouse_id', $warehouseId)->first();
                $avail = $whProduct?->pivot->stock ?? 0;
                if ($avail < $needed) {
                    $this->refuse("Stok {$component->title} tidak mencukupi.");
                }
            }
            $sellPrice = $product->compositeSellPrice();
        } else {
            $unitId = (int) (($data['unit_id'] ?? null) ?: $product->baseUnit()?->id ?: 1);

            // Reject an explicitly provided unit that does not belong to this product
            if (! empty($data['unit_id'] ?? null) && ! $product->units()->whereKey($unitId)->exists()) {
                $this->refuse('Satuan tidak valid untuk produk ini.');
            }

            $baseQty = $this->unitConversion->toBaseUnit($product, $unitId, $data['qty']);

            $availableStock = $warehouseId
                ? (int) ($product->warehouses()->where('warehouse_id', $warehouseId)->first()?->pivot->stock ?? 0)
                : (int) $product->stock;

            if ($availableStock < $baseQty + $inCart) {
                $this->refuse('Stok tidak mencukupi. Tersedia: '.$availableStock);
            }

            $sellPrice = $this->unitConversion->getSellPrice($product, $unitId);
            $conversionFactor = $product->units()->where('unit_id', $unitId)->first()?->pivot->conversion_factor ?? 1;
        }

        $selection = ModifierSelection::resolve($product, $data['modifier_option_ids'] ?? [], $outlet, $warehouseId);
        $optionIds = collect($selection['options'])->pluck('id')->sort()->values()->all();
        $note = $data['note'] ?? null;
        $lineKey = $optionIds === [] && $note === null ? '' : sha1(json_encode([$optionIds, $note]));

        $cart = Cart::active()
            ->where('cashier_id', $cashierId)
            ->where('product_id', $product->id)
            ->where('unit_id', $unitId)
            ->where('line_key', $lineKey)
            ->first();
        $qty = ($cart->qty ?? 0) + $data['qty'];

        // shortcut: counts this line only, not other lines sharing an ingredient; checkout re-checks under lock.
        ModifierSelection::assertStock($product, $selection['options'], (int) ceil($qty), $warehouseId);

        $cart ??= new Cart([
            'cashier_id' => $cashierId,
            'warehouse_id' => $warehouseId,
            'product_id' => $product->id,
            'unit_id' => $unitId,
            'conversion_factor' => $conversionFactor,
            'note' => $note,
            'line_key' => $lineKey,
        ]);
        $cart->fill([
            'qty' => $qty,
            'modifier_option_ids' => $selection['has_groups'] ? $optionIds : null,
            'modifier_total' => $selection['unit_total'],
            'price' => ($sellPrice + $selection['unit_total']) * $qty,
        ])->save();
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['message' => $message]);
    }
}
