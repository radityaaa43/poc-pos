<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Soft deleted: past sales keep their own snapshot and their link to the option stays valid.
class ModifierOption extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'price_delta', 'is_default', 'sort'];

    protected $casts = [
        'price_delta' => 'integer',
        'is_default' => 'boolean',
        'sort' => 'integer',
    ];

    /** @return BelongsTo<ModifierGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class, 'modifier_group_id');
    }

    /** @return BelongsToMany<Product, $this> */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'modifier_option_components', 'modifier_option_id', 'component_product_id')
            ->withPivot('qty')
            ->withTimestamps()
            ->orderBy('products.id');
    }

    /** @return BelongsToMany<Outlet, $this> */
    public function disabledOutlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'modifier_option_outlet');
    }

    /** @return list<array{product_id: int, qty: float}> the option's recipe */
    public function componentLines(): array
    {
        return $this->components->map(fn (Product $component) => [
            'product_id' => $component->id,
            'qty' => (float) $component->getRelationValue('pivot')?->qty,
        ])->values()->all();
    }
}
