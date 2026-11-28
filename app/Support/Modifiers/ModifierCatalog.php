<?php

namespace App\Support\Modifiers;

use App\Models\ModifierGroup;
use App\Models\ModifierOption;
use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/** The one read path for what a menu offers (REQ-008): POS, checkout, offline cache and QR menu all use it. */
class ModifierCatalog
{
    /**
     * Output shape and rules (the contract for the selling channels): docs/features/modifiers.md.
     *
     * @param  Collection<int, int>  $productIds
     * @return Collection<int, array{orderable: bool, groups: array<int, array{id: int, name: string, is_required: bool,
     *     min_select: int, max_select: int, options: array<int, mixed>}>}> keyed by product id
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
            ])
            ->map(fn (array $groups) => ['orderable' => self::shortGroup($groups) === null, 'groups' => $groups]);
    }

    public static function shortGroup(array $groups): ?array
    {
        return Arr::first($groups, fn (array $group) => count($group['options']) < $group['min_select']);
    }
}
