<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DiningTable extends Model
{
    use HasFactory;

    protected $table = 'dine_tables';

    protected $fillable = [
        'dine_area_id',
        'name',
        'token',
        'capacity',
        'pos_x',
        'pos_y',
        'shape',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'pos_x' => 'integer',
            'pos_y' => 'integer',
            'capacity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DiningTable $table) {
            if (empty($table->token)) {
                $table->token = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<DineArea, $this> */
    public function area(): BelongsTo
    {
        return $this->belongsTo(DineArea::class, 'dine_area_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(DineOrder::class, 'dine_table_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /** The tables an outlet uses: its own areas', plus the shared ones (no area, or an area without an outlet). */
    public function scopeForOutlet($query, ?Outlet $outlet)
    {
        return $query->where(fn ($tables) => $tables->whereNull('dine_area_id')->orWhereHas('area', fn ($area) => $area
            ->where(fn ($owner) => $owner->whereNull('outlet_id')
                ->when($outlet, fn ($query) => $query->orWhere('outlet_id', $outlet->id)))));
    }
}
