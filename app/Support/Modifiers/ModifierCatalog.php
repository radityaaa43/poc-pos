<?php

namespace App\Support\Modifiers;

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** The one read path for what a menu offers (REQ-008): POS, checkout, offline cache and QR menu all use it. */
class ModifierCatalog
{
    /**
     * Four queries whatever the number of products. Products without groups are absent. Groups come in the menu's
     * display order, options in the group's order; deleted ones and options switched off at $outlet are left out
     * (null outlet: nothing is switched off). A group whose options are all off stays, with an empty list, so a
     * required one can block the menu. Stock availability is not checked here.
     *
     * Each option is {id, name, price_delta, is_default, components: [{product_id, qty}]}.
     *
     * @param  Collection<int, int>  $productIds
     * @return Collection<int, array<int, array{id: int, name: string, is_required: bool, min_select: int,
     *     max_select: int, options: array<int, mixed>}>> keyed by product id
     */
    public static function forOutlet(?Outlet $outlet, Collection $productIds): Collection
    {
        return Product::query()
            ->whereKey($productIds->all())
            ->whereHas('modifierGroups')
            ->with([
                'modifierGroups.options' => fn (HasMany $options) => $options->when($outlet, fn ($query) => $query
                    ->whereDoesntHave('disabledOutlets', fn ($disabled) => $disabled->whereKey($outlet->getKey()))),
                // Only the id and the pivot quantity: callers must not get the ingredient's prices.
                'modifierGroups.options.components' => fn (BelongsToMany $components) => $components->select('products.id'),
            ])
            ->get(['id'])
            ->mapWithKeys(fn (Product $product) => [
                $product->id => $product->modifierGroups->map(fn (ModifierGroup $group) => [
                    ...$group->only(['id', 'name', 'is_required', 'min_select', 'max_select']),
                    'options' => $group->options->map(fn (ModifierOption $option) => [
                        ...$option->only(['id', 'name', 'price_delta', 'is_default']),
                        'components' => $option->componentLines(),
                    ])->values()->all(),
                ])->values()->all(),
            ]);
    }
}
