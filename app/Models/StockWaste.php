<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockWaste extends Model
{
    public const STATUS_RECORDED = 'recorded';

    public const STATUS_PENDING = 'pending';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    public const REASONS = ['expired', 'damaged', 'wrong_made', 'not_collected', 'other'];

    protected $fillable = [
        'warehouse_id', 'status', 'reason', 'note', 'total_value', 'client_key', 'created_by', 'recorded_at',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejected_reason',
        'cancelled_by', 'cancelled_at', 'cancelled_reason',
    ];

    protected function casts(): array
    {
        return [
            'total_value' => 'integer',
            'recorded_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockWasteLine::class);
    }

    public function moves(): HasMany
    {
        return $this->hasMany(StockWasteMove::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
