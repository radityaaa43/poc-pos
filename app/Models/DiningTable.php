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

    /** Tables an outlet may use: its own areas, areas without an outlet, and tables without an area. */
    public function scopeForOutlet($query, ?Outlet $outlet)
    {
        return $query->where(function ($query) use ($outlet) {
            $query->whereNull('dine_area_id')->orWhereHas('area', function ($query) use ($outlet) {
                $query->whereNull('outlet_id');
                if ($outlet) {
                    $query->orWhere('outlet_id', $outlet->id);
                }
            });
        });
    }

    /** @return HasMany<DineReservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(DineReservation::class, 'dine_table_id');
    }

    /** The earliest booked reservation of any date: what keeps this table from being removed (REQ-009 AC-23). */
    public function blockingReservation(): ?DineReservation
    {
        return $this->reservations()->where('status', 'booked')->orderBy('reserved_at')->first();
    }
}
