<?php

namespace App\Services\Modifiers;

use App\Models\Cart;
use App\Models\ModifierOption;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Support\Modifiers\ModifierCatalog;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The one rule set for the options picked on a menu (REQ-008), at add-to-cart and again at checkout. Only option
 * ids come from the client: names, prices and recipes are read from the catalog.
 */
class ModifierSelection
{
    /**
     * @param  array<int, int|string>  $optionIds
     * @return array{has_groups: bool, unit_total: int, options: list<array<string, mixed>>} options in display order
     *
     * @throws ValidationException naming the menu and the option, or the incomplete group
     */
    public static function resolve(Product $product, array $optionIds, ?Outlet $outlet, ?int $warehouseId): array
    {
        $groups = ModifierCatalog::forOutlet($outlet, collect([$product->id]))->get($product->id, []);
        $ids = collect($optionIds)->map(fn ($id) => (int) $id)->unique();

        // First, so a deleted or switched-off option is named instead of its group looking incomplete.
        $gone = $ids->diff(collect($groups)->flatMap(fn (array $group) => array_column($group['options'], 'id')))->first();

        if ($gone !== null) {
            self::refuse('option_unavailable', $product, [
                'option' => ModifierOption::withTrashed()->whereKey($gone)->value('name') ?? "#{$gone}",
            ]);
        }

        $options = [];

        foreach ($groups as $group) {
            $chosen = collect($group['options'])->whereIn('id', $ids->all());

            if ($chosen->count() < $group['min_select']) {
                self::refuse('group_min', $product, ['group' => $group['name'], 'min' => $group['min_select']]);
            }

            if ($chosen->count() > $group['max_select']) {
                self::refuse('group_max', $product, ['group' => $group['name'], 'max' => $group['max_select']]);
            }

            foreach ($chosen as $option) {
                $options[] = ['group_name' => $group['name']] + $option;
            }
        }

        self::assertStock($product, $options, 1, $warehouseId);

        return [
            'has_groups' => $groups !== [],
            'unit_total' => (int) array_sum(array_column($options, 'price_delta')),
            'options' => $options,
        ];
    }

    /** @param  list<array<string, mixed>>  $options  from resolve(); refuses one whose recipe cannot cover $qty portions */
    public static function assertStock(Product $product, array $options, int $qty, ?int $warehouseId): void
    {
        $stock = self::stockOf($options, $warehouseId);

        foreach ($options as $option) {
            if (! self::covers($stock, $option['components'], $qty)) {
                self::refuse('option_sold_out', $product, ['option' => $option['name']]);
            }
        }
    }

    /**
     * Every cart line that carries a selection, checked again with today's options, prices and stock.
     *
     * @param  Collection<int, Cart>  $carts
     * @return array<int, array{has_groups: bool, unit_total: int, options: list<array<string, mixed>>}> by cart id
     */
    public static function forCheckout(Collection $carts, ?Outlet $outlet, ?int $warehouseId): array
    {
        $selections = [];

        foreach ($carts->whereNotNull('modifier_option_ids') as $cart) {
            $selection = self::resolve($cart->product, $cart->modifier_option_ids, $outlet, $warehouseId);
            self::assertStock($cart->product, $selection['options'], (int) $cart->qty, $warehouseId);
            $selections[$cart->id] = $selection;
        }

        return $selections;
    }

    /** The sale line's own copy of its options, so later edits never change it (REQ-008 decision 11).
     *
     * @param  list<array<string, mixed>>  $options  from resolve()
     * @param  Collection<int, ModifierOption>  $recipes  by option id, the ones the sale deducts
     * @return list<array<string, mixed>>
     */
    public static function snapshot(array $options, Collection $recipes): array
    {
        return array_map(fn (array $option) => [
            'modifier_option_id' => $option['id'],
            'group_name' => $option['group_name'],
            'option_name' => $option['name'],
            'price_delta' => $option['price_delta'],
            'components' => array_map(
                fn (array $line) => ['product_id' => $line['product_id'], 'qty_per_unit' => $line['qty']],
                $recipes[$option['id']]->componentLines()
            ),
        ], $options);
    }

    /**
     * What each cart line shows and adds to its price, in one query. A deleted option still previews at its last
     * price, so the cart stays readable; checkout is what refuses it.
     *
     * @param  Collection<int, Cart>  $carts
     * @return Collection<int, array<int, array{name: string, price_delta: int}>> by cart id
     */
    public static function preview(Collection $carts): Collection
    {
        $ids = $carts->pluck('modifier_option_ids')->flatten()->filter()->unique();
        $options = $ids->isEmpty() ? collect() : ModifierOption::withTrashed()
            ->whereKey($ids->all())
            ->orderBy('modifier_group_id')->orderBy('sort')
            ->get(['id', 'name', 'price_delta']);

        return $carts->mapWithKeys(fn (Cart $cart) => [$cart->id => $options
            ->whereIn('id', $cart->modifier_option_ids ?? [])
            ->map(fn (ModifierOption $option) => $option->only(['name', 'price_delta']))
            ->values()->all()]);
    }

    /** The catalog as the POS panel draws it: an availability flag instead of the recipe.
     *
     * @param  Collection<int, int>  $productIds
     * @return Collection<int, array<int, array<string, mixed>>> by product id
     */
    public static function forPos(Collection $productIds, ?Outlet $outlet, ?int $warehouseId): Collection
    {
        $catalog = ModifierCatalog::forOutlet($outlet, $productIds);
        $stock = self::stockOf($catalog->flatten(1)->flatMap(fn (array $group) => $group['options'])->all(), $warehouseId);

        return $catalog->map(fn (array $groups) => array_map(fn (array $group) => [
            ...$group,
            'options' => array_map(fn (array $option) => [
                ...Arr::except($option, 'components'),
                'available' => self::covers($stock, $option['components'], 1),
            ], $group['options']),
        ], $groups));
    }

    /** @param  array<int, array<string, mixed>>  $groups  from forPos(); true when a group cannot reach its minimum */
    public static function isSoldOut(array $groups): bool
    {
        return collect($groups)->contains(
            fn (array $group) => collect($group['options'])->where('available', true)->count() < $group['min_select']
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $options  catalog options with `components`
     * @return Collection<int, int> product id => stock in the warehouse (all stock without one)
     */
    private static function stockOf(array $options, ?int $warehouseId): Collection
    {
        $ids = collect($options)->flatMap(fn (array $option) => array_column($option['components'], 'product_id'))
            ->unique()->values()->all();

        if ($ids === []) {
            return collect();
        }

        return $warehouseId
            ? ProductWarehouse::where('warehouse_id', $warehouseId)->whereIn('product_id', $ids)->pluck('stock', 'product_id')
            : Product::whereKey($ids)->pluck('stock', 'id');
    }

    /** @param  array<int, array{product_id: int, qty: float}>  $components */
    private static function covers(Collection $stock, array $components, int $qty): bool
    {
        return collect($components)->every(
            fn (array $line) => (int) ($stock[$line['product_id']] ?? 0) >= (int) round($line['qty'] * $qty)
        );
    }

    /** @param  array<string, mixed>  $replace */
    private static function refuse(string $key, Product $product, array $replace): never
    {
        throw ValidationException::withMessages([
            'modifiers' => __("messages.modifiers.{$key}", ['menu' => $product->title] + $replace),
        ]);
    }
}
