<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KitchenItem extends Model
{
    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY = 'ready';

    public const STATUS_SERVED = 'served';

    /** Terminal: set by a completed sales return; its time is `updated_at`, never written again. */
    public const STATUS_CANCELLED = 'cancelled';

    /** How long the display keeps showing a cancelled item (Dibatalkan) before it leaves the queue. */
    public const CANCELLED_VISIBLE_MINUTES = 2;

    /** Terminal: Tidak diambil, set when a shift is force-closed over food not handed over (REQ-003 AC-13). */
    public const STATUS_NOT_COLLECTED = 'not_collected';

    /** None of served, cancelled or not collected: still waiting on the kitchen or the pass. */
    public const OPEN_STATUSES = [self::STATUS_ACCEPTED, self::STATUS_PREPARING, self::STATUS_READY];

    protected $fillable = [
        'transaction_id',
        'transaction_detail_id',
        'product_id',
        'warehouse_id',
        'kitchen_station_id',
        'qty',
        'note',
        'status',
        'accepted_at',
        'accepted_by',
        'preparing_at',
        'preparing_by',
        'ready_at',
        'ready_by',
        'served_at',
        'served_by',
    ];

    protected $casts = [
        'qty' => 'integer',
        'accepted_at' => 'datetime',
        'preparing_at' => 'datetime',
        'ready_at' => 'datetime',
        'served_at' => 'datetime',
    ];

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function detail(): BelongsTo
    {
        return $this->belongsTo(TransactionDetail::class, 'transaction_detail_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function preparingUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'preparing_by');
    }

    public function readyUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ready_by');
    }

    /**
     * withTrashed: an item keeps showing the name of a station deleted after it entered the kitchen.
     *
     * @return BelongsTo<KitchenStation, $this>
     */
    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class)->withTrashed();
    }

    public function servedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'served_by');
    }

    /** Not yet served: still waiting on the kitchen or the pass. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', self::OPEN_STATUSES);
    }

    /**
     * Items of the given kitchen stations. The Dapur display also shows work of a removed station (or none), so when the
     * default station is asked for, those items are included too (B1). One grouped where, so it can sit next to other filters.
     *
     * @param  Builder<KitchenItem>  $query
     * @param  array<int, int|string>  $stationIds
     */
    public function scopeInStations(Builder $query, array $stationIds): void
    {
        $ids = array_map('intval', $stationIds);

        $query->where(function ($stations) use ($ids) {
            $stations->whereIn('kitchen_station_id', $ids);

            if (in_array(KitchenStation::where('is_default', true)->value('id'), $ids, true)) {
                $stations->orWhereNull('kitchen_station_id')
                    ->orWhereNotIn('kitchen_station_id', KitchenStation::query()->select('id'));
            }
        });
    }

    /** Every warehouse of the outlet: a shift may sell from any of them, not only the first one. */
    public function scopeInOutlet(Builder $query, int $outletId): void
    {
        $query->whereIn('warehouse_id', Warehouse::where('outlet_id', $outletId)->select('id'));
    }

    public function ticketPrints(): HasMany
    {
        return $this->hasMany(KitchenTicketPrint::class);
    }

    /** What the kitchen display lists: the open items plus the recently cancelled ones. */
    public function scopeOnDisplay(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereIn('status', self::OPEN_STATUSES)->orWhere(fn ($q) => $q
            ->where('status', self::STATUS_CANCELLED)
            ->where('updated_at', '>=', now()->subMinutes(self::CANCELLED_VISIBLE_MINUTES))));
    }
}
