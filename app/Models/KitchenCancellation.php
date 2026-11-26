<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A completed sales return took qty_cancelled off a kitchen item: written only by KitchenItemService::cancelForReturn. */
class KitchenCancellation extends Model
{
    // Rows are immutable: created_at is filled by Eloquent or the column default, there is no updated_at.
    public const UPDATED_AT = null;

    protected $fillable = ['kitchen_item_id', 'sales_return_id', 'qty_cancelled'];

    protected $casts = ['qty_cancelled' => 'integer', 'created_at' => 'datetime'];

    /** @return BelongsTo<KitchenItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(KitchenItem::class, 'kitchen_item_id');
    }

    /** @return BelongsTo<SalesReturn, $this> */
    public function salesReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class);
    }

    /** @return HasMany<KitchenCancellationPrint, $this> */
    public function prints(): HasMany
    {
        return $this->hasMany(KitchenCancellationPrint::class);
    }
}
