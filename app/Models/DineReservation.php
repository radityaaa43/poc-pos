<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @property Carbon $reserved_at
 */
class DineReservation extends Model
{
    /** A table shows Dipesan from this long before a booking (REQ-009: fixed, no setting). */
    public const BOOKED_SOON_MINUTES = 30;

    /** Two bookings of one table closer than this clash; exactly this far apart is allowed. */
    public const MIN_GAP_MINUTES = 120;

    /** What staff may mark a booked reservation as; there is no way back. */
    public const MARKS = ['arrived', 'cancelled', 'no_show'];

    protected $fillable = [
        'outlet_id',
        'dine_table_id',
        'guest_name',
        'phone',
        'party_size',
        'reserved_at',
        'note',
        'status',
    ];

    // Hidden so no payload that serialises a reservation can leak it; the manage page adds it by name.
    protected $hidden = ['phone'];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'dine_table_id' => 'integer',
            'party_size' => 'integer',
            'reserved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Outlet, $this> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    /** @return BelongsTo<DiningTable, $this> */
    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dine_table_id');
    }

    /** Bookings that make their table Dipesan. No upper bound: an unmarked one stays until staff mark it. */
    public function scopeBookedSoon(Builder $query, ?CarbonInterface $at = null): Builder
    {
        return $query->where('status', 'booked')
            ->where('reserved_at', '<=', ($at ?? now())->copy()->addMinutes(self::BOOKED_SOON_MINUTES));
    }

    /**
     * The earliest Dipesan booking of each table, in one query, keyed by table id.
     *
     * @param  iterable<int>  $tableIds
     * @return Collection<int, self>
     */
    public static function bookedSoonByTable(iterable $tableIds, ?CarbonInterface $at = null): Collection
    {
        return self::query()->bookedSoon($at)
            ->whereIn('dine_table_id', collect($tableIds))
            ->orderBy('reserved_at')
            ->get()
            ->unique('dine_table_id')
            ->keyBy('dine_table_id');
    }
}
