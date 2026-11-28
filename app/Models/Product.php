<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Product extends Model
{
    use HasFactory;

    protected $casts = [
        'id' => 'integer',
        'category_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'buy_price' => 'integer',
        'sell_price' => 'integer',
        'stock' => 'integer',
        'tax_rate' => 'decimal:2',
        'min_stock' => 'integer',
        'max_stock' => 'integer',
        'is_composite' => 'boolean',
        'is_raw_material' => 'boolean',
        'needs_preparation' => 'boolean',
        'prep_target_minutes' => 'integer',
        'kitchen_station_id' => 'integer',
    ];

    protected $fillable = [
        'image',
        'barcode',
        'sku',
        'title',
        'description',
        'buy_price',
        'sell_price',
        'category_id',
        'stock',
        'tax_type',
        'tax_rate',
        'min_stock',
        'max_stock',
        'is_composite',
        'is_raw_material',
        'needs_preparation',
        'prep_target_minutes',
        'kitchen_station_id',
        'purchased_by',
    ];

    protected static function booted(): void
    {
        // A raw material never keeps modifier groups, whichever screen or API flagged it (REQ-008 AC-03).
        static::saved(function (Product $product) {
            if ($product->is_raw_material && $product->wasChanged('is_raw_material')) {
                $product->modifierGroups()->detach();
            }
        });
        // A new product is skipped: a null id would match the rows of deleted ingredients.
        static::saving(function (Product $product) {
            if ($product->exists && $product->is_composite && $product->isDirty('is_composite')) {
                $product->refuseModifierIngredient('components');
            }
        });
        static::deleting(fn (Product $product) => $product->refuseModifierIngredient('delete'));
        static::deleting(fn (Product $product) => $product->refuseWasteHistory());
    }

    /** A wasted product, typed or taken as an ingredient, stays: the trail points at it (REQ-011). */
    public function refuseWasteHistory(): void
    {
        if (StockWasteLine::where('product_id', $this->id)->exists() || StockWasteMove::where('product_id', $this->id)->exists()) {
            throw ValidationException::withMessages(['delete' => __('messages.waste.product_in_use')]);
        }
    }

    /** A live option's ingredient can be neither deleted nor made a recipe: nothing could deduct it (REQ-008). */
    public function refuseModifierIngredient(string $field): void
    {
        $option = $this->belongsToMany(ModifierOption::class, 'modifier_option_components', 'component_product_id')->first();

        if ($option) {
            throw ValidationException::withMessages([$field => __('messages.modifiers.ingredient_in_use', [
                'option' => $option->name,
                'group' => ModifierGroup::whereKey($option->modifier_group_id)->value('name'),
            ])]);
        }
    }

    /** Adds unit_symbol, the base unit's: one subquery, no N+1; null when the product has no units. */
    public function scopeWithUnitSymbol(Builder $query): void
    {
        $query->addSelect(['unit_symbol' => DB::table('product_units')
            ->join('units', 'units.id', '=', 'product_units.unit_id')
            ->select('units.symbol')
            ->whereColumn('product_units.product_id', 'products.id')
            ->where('product_units.is_base', true)
            ->limit(1)]);
    }

    /** What a recipe can deduct: stock-tracked products, raw materials first, each with its base-unit symbol. */
    public function scopeRecipeComponents(Builder $query): void
    {
        $this->scopeWithUnitSymbol($query);
        $query->where('is_composite', false)
            ->orderByDesc('is_raw_material')
            ->orderBy('title');
    }

    /** @return BelongsToMany<ModifierGroup, $this> */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'product_modifier_group')
            ->withPivot('sort')
            ->orderByPivot('sort');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Null means the default station (Dapur).
     *
     * @return BelongsTo<KitchenStation, $this>
     */
    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class);
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class)
            ->withPivot(['stock', 'avg_cost'])
            ->using(ProductWarehouse::class)
            ->withTimestamps();
    }

    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'product_units')
            ->withPivot(['is_base', 'is_purchase_default', 'conversion_factor', 'buy_price', 'sell_price', 'barcode', 'sku_suffix'])
            ->using(ProductUnit::class)
            ->withTimestamps();
    }

    public function baseUnit(): ?Unit
    {
        return $this->units()->wherePivot('is_base', true)->first();
    }

    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'composite_product_items', 'composite_product_id', 'component_product_id')
            ->withPivot('qty')
            ->withTimestamps();
    }

    public function compositeOf(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'composite_product_items', 'component_product_id', 'composite_product_id');
    }

    public function compositeStock(): int
    {
        if (! $this->is_composite) {
            return $this->stockTotal();
        }
        $minStock = null;
        foreach ($this->components as $component) {
            $available = (int) floor($component->stockTotal() / max(1, (float) $component->pivot->qty));
            $minStock = $minStock === null ? $available : min($minStock, $available);
        }

        return $minStock ?? 0;
    }

    /**
     * Selling price of a composite (recipe) product: its own sell_price when set,
     * otherwise the sum of its components' prices (bundle/kit behaviour).
     */
    public function compositeSellPrice(): int
    {
        if ((int) $this->sell_price > 0) {
            return (int) $this->sell_price;
        }

        return (int) $this->components->sum(fn (Product $c) => (int) $c->sell_price * (float) $c->pivot->qty);
    }

    /** Stock sellable from one warehouse; for composites, the portions its components allow. */
    public function stockIn(?int $warehouseId): int
    {
        if (! $this->is_composite) {
            return $this->pivotStockIn($warehouseId);
        }

        $components = $this->components;
        if ($components->isEmpty()) {
            return 0;
        }

        return (int) $components
            ->map(fn (Product $c) => (int) floor($c->pivotStockIn($warehouseId) / max(0.0001, (float) $c->pivot->qty)))
            ->min();
    }

    private function pivotStockIn(?int $warehouseId): int
    {
        if (! $warehouseId) {
            return (int) $this->stock;
        }

        $warehouse = $this->relationLoaded('warehouses')
            ? $this->warehouses->firstWhere('id', $warehouseId)
            : $this->warehouses()->where('warehouse_id', $warehouseId)->first();

        return (int) ($warehouse?->pivot->stock ?? 0);
    }

    /**
     * Products the POS may offer: never raw materials; stocked items in the
     * warehouse, plus composites (callers drop those whose components ran out).
     */
    public function scopeForPos($query, ?int $warehouseId)
    {
        return $query->where('is_raw_material', false)
            ->where(function ($q) use ($warehouseId) {
                $q->where('is_composite', true)
                    ->orWhere(fn ($q) => $warehouseId
                        ? $q->whereHas('warehouses', fn ($w) => $w->where('product_warehouse.warehouse_id', $warehouseId)
                            ->where('product_warehouse.stock', '>', 0))
                        : $q->where('stock', '>', 0));
            });
    }

    public function stockOpnameItems()
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    public function stockMutations()
    {
        return $this->hasMany(StockMutation::class);
    }

    public function salesReturnItems()
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function pricingRules()
    {
        return $this->hasMany(PricingRule::class);
    }

    public function stockTotal(): int
    {
        return (int) $this->warehouses()->sum('product_warehouse.stock');
    }

    public function isLowStock(?int $warehouseId = null): bool
    {
        if ($this->min_stock <= 0) {
            return false;
        }
        $stock = $warehouseId
            ? (int) ($this->warehouses()->where('warehouse_id', $warehouseId)->first()?->pivot->stock ?? 0)
            : $this->stockTotal();

        return $stock <= $this->min_stock;
    }

    public function suggestedOrderQty(?int $warehouseId = null): int
    {
        if ($this->max_stock <= 0 || $this->min_stock <= 0) {
            return 0;
        }

        $stock = $warehouseId
            ? (int) ($this->warehouses()->where('warehouse_id', $warehouseId)->first()?->pivot->stock ?? 0)
            : $this->stockTotal();

        return max(0, $this->max_stock - $stock);
    }

    protected function image(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ? asset('/storage/products/'.$value) : null,
        );
    }
}
