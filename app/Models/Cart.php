<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Cart extends Model
{
    use HasFactory;

    protected $fillable = [
        'cashier_id', 'warehouse_id', 'product_id', 'unit_id', 'conversion_factor', 'qty', 'price', 'hold_id', 'hold_label', 'held_at',
    ];

    protected $casts = [
        'held_at' => 'datetime',
        'conversion_factor' => 'decimal:4',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('hold_id');
    }

    /**
     * Pieces of a product already in the cashier's active cart, across every unit row. BOX and PCS
     * rows draw on the same stock, so a stock check must count them together.
     */
    public static function baseQtyInCart(int $cashierId, int $productId, ?int $exceptCartId = null): int
    {
        return (int) round((float) static::active()
            ->where('cashier_id', $cashierId)
            ->where('product_id', $productId)
            ->when($exceptCartId, fn ($query) => $query->whereKeyNot($exceptCartId))
            ->sum(DB::raw('qty * conversion_factor')));
    }

    public function scopeHeld($query)
    {
        return $query->whereNotNull('hold_id');
    }

    public function scopeForHold($query, $holdId)
    {
        return $query->where('hold_id', $holdId);
    }
}
