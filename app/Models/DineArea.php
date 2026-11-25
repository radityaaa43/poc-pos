<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $outlet_id added by a later migration than the create table Larastan reads
 */
class DineArea extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'outlet_id',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'outlet_id' => 'integer',
        ];
    }

    public function tables(): HasMany
    {
        return $this->hasMany(DiningTable::class, 'dine_area_id');
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
