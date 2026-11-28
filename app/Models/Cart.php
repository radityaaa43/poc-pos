<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection as SupportCollection;
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

    protected static function booted(): void
    {
        // Edits of one row by id (web and API cart) must not change a basket an approver is deciding on.
        $free = fn (Cart $cart) => CreditApprovalRequest::assertHoldIsFree($cart->getOriginal('hold_id'));
        static::updating($free);
        static::deleting($free);
    }

    /** @return HasOne<CreditApprovalRequest, $this> */
    public function creditRequest(): HasOne
    {
        return $this->hasOne(CreditApprovalRequest::class, 'hold_id', 'hold_id');
    }

    /**
     * Held rows as the POS list shows them, one per basket, with the state of a credit exception asked for it.
     *
     * @param  Collection<int, Cart>  $held
     */
    public static function heldList(Collection $held): SupportCollection
    {
        return $held->toBase()->groupBy('hold_id')
            ->map(fn (SupportCollection $rows, string $holdId) => [
                'hold_id' => $holdId,
                'label' => $rows->first()->hold_label,
                'held_at' => $rows->first()->held_at?->toISOString(),
                'items_count' => $rows->sum('qty'),
                'total' => $rows->sum('price'),
                'credit_status' => $rows->first()->creditRequest?->status,
                'credit_note' => $rows->first()->creditRequest?->decision_reason,
            ])
            ->values();
    }

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

    /**
     * The cart rows behind a sale queued offline: once the sale exists they must go, or the cashier
     * would see (and could sell again) the same basket. Only the named ids of this cashier's live cart.
     */
    public static function discardQueued(array $ids, int $cashierId): void
    {
        if ($ids !== []) {
            static::whereIn('id', $ids)->where('cashier_id', $cashierId)->active()->delete();
        }
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
