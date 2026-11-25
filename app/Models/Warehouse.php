<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Warehouse extends Model
{
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'type',
        'address',
        'phone',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)
            ->withPivot('stock')
            ->using(ProductWarehouse::class)
            ->withTimestamps();
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

    /** A warehouse is central when its outlet does not sell (a legacy warehouse without an outlet is not). */
    public function isCentral(): bool
    {
        return $this->outlet && ! $this->outlet->is_sales_enabled;
    }

    public function scopeCentral($query)
    {
        return $query->whereHas('outlet', fn ($q) => $q->where('is_sales_enabled', false));
    }
}
