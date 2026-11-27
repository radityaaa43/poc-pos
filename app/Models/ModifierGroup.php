<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ModifierGroup extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'is_required', 'min_select', 'max_select'];

    protected $casts = [
        'is_required' => 'boolean',
        'min_select' => 'integer',
        'max_select' => 'integer',
    ];

    /** @return HasMany<ModifierOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(ModifierOption::class)->orderBy('sort')->orderBy('id');
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_modifier_group')->withPivot('sort');
    }

    /**
     * The group as the settings form edits it and the audit log records it.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $this->loadMissing('options.components', 'options.disabledOutlets');

        return [
            ...$this->only(['name', 'is_required', 'min_select', 'max_select']),
            'options' => $this->options->map(fn (ModifierOption $option) => [
                ...$option->only(['id', 'name', 'price_delta', 'is_default']),
                'components' => $option->componentLines(),
                'disabled_outlet_ids' => $option->disabledOutlets->pluck('id')->sort()->values()->all(),
            ])->all(),
        ];
    }
}
