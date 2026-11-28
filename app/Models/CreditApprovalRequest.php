<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/** A refused credit sale parked as a held cart until an approver decides (REQ-007 AC-06). */
class CreditApprovalRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DENIED = 'denied';

    protected $fillable = [
        'customer_id', 'cashier_id', 'cashier_shift_id', 'warehouse_id', 'hold_id', 'amount', 'reason_code',
        'remaining_limit', 'payload', 'status', 'decided_by', 'decided_at', 'decision_reason', 'transaction_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'amount' => 'integer',
        'remaining_limit' => 'integer',
        'decided_at' => 'datetime',
    ];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @throws ValidationException while an approver is still deciding on this held basket */
    public static function assertHoldIsFree(?string $holdId): void
    {
        if ($holdId && static::where('hold_id', $holdId)->where('status', self::PENDING)->exists()) {
            throw ValidationException::withMessages(['hold' => __('messages.credit.awaiting')]);
        }
    }
}
