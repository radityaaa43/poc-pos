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

    protected $fillable = [
        'transaction_id',
        'transaction_detail_id',
        'product_id',
        'warehouse_id',
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

    /** Not yet served: still waiting on the kitchen or the pass. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', [self::STATUS_ACCEPTED, self::STATUS_PREPARING, self::STATUS_READY]);
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
}
