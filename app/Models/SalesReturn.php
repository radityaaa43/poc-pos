<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SalesReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'transaction_id',
        'warehouse_id',
        'customer_id',
        'cashier_id',
        'cashier_shift_id',
        'status',
        'return_type',
        'refund_amount',
        'credited_amount',
        'service_refunded',
        'tax_refunded',
        'total_return_amount',
        'notes',
        'completed_at',
    ];

    protected $casts = [
        'transaction_id' => 'integer',
        'customer_id' => 'integer',
        'cashier_id' => 'integer',
        'cashier_shift_id' => 'integer',
        'refund_amount' => 'integer',
        'credited_amount' => 'integer',
        'service_refunded' => 'integer',
        'tax_refunded' => 'integer',
        'total_return_amount' => 'integer',
        'completed_at' => 'datetime',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function cashierShift()
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items()
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function customerCredits()
    {
        return $this->hasMany(CustomerCredit::class);
    }

    /** Completed without handing anything back: the sale was not paid yet, so it owes that much less. */
    public function scopeNothingRefunded($query)
    {
        return $query->where('status', 'completed')->where('refund_amount', 0)->where('credited_amount', 0);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
