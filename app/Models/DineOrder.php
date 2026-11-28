<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class DineOrder extends Model
{
    use HasFactory;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY = 'ready';

    public const STATUS_SERVED = 'served';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const PAY_AT_COUNTER = 'pay_at_counter';

    public const PAY_LATER = 'pay_later';

    /** Accepted but not completed: the bill can still be open and the order is being fulfilled. */
    public const IN_PROGRESS = [self::STATUS_ACCEPTED, self::STATUS_PREPARING, self::STATUS_READY, self::STATUS_SERVED];

    protected $fillable = [
        'dine_table_id',
        'customer_id',
        'access_token',
        'status',
        'notes',
        'payment_option',
        'payment_method',
        'payment_status',
        'payment_reference',
        'payment_url',
        'cashier_id',
        'transaction_id',
        'add_on_to_dine_order_id',
        'subtotal',
        'item_count',
        'submitted_at',
        'accepted_at',
        'accepted_by',
        'preparing_at',
        'preparing_by',
        'ready_at',
        'ready_by',
        'served_at',
        'served_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'integer',
            'item_count' => 'integer',
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'served_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (DineOrder $order) {
            if (empty($order->access_token)) {
                $order->access_token = (string) Str::uuid();
            }
        });
    }

    /** @return BelongsTo<DiningTable, $this> */
    public function table(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dine_table_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * The order whose status page this one was ordered from (#251).
     *
     * @return BelongsTo<DineOrder, $this>
     */
    public function addOnTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'add_on_to_dine_order_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DineOrderItem::class, 'dine_order_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_SUBMITTED);
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_SUBMITTED, ...self::IN_PROGRESS]);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isPaid(): bool
    {
        return $this->transaction?->payment_status === 'paid';
    }

    /** Safety net: completed once the order is served and paid. Payment comes first (the kitchen waits for it), so serving normally completes it. */
    public function completeIfSettled(): void
    {
        // Locking reads, the order then its bill (#251): a hand-over and a payment can commit side by side, and neither
        // this model, its loaded bill nor the transaction's snapshot shows the other one's write.
        if (static::whereKey($this->id)->lockForUpdate()->value('status') === self::STATUS_SERVED
            && Transaction::whereKey($this->transaction_id)->lockForUpdate()->value('payment_status') === 'paid') {
            $this->update(['status' => self::STATUS_COMPLETED, 'completed_at' => now()]);
        }
    }
}
