<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KitchenCancellationPrint extends Model
{
    // printed_at is filled by the column default: rows are written with insertOrIgnore, which skips model timestamps.
    public $timestamps = false;

    protected $fillable = ['kitchen_print_device_id', 'kitchen_cancellation_id'];

    protected $casts = ['printed_at' => 'datetime'];

    /** @return BelongsTo<KitchenPrintDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(KitchenPrintDevice::class, 'kitchen_print_device_id');
    }

    /** @return BelongsTo<KitchenCancellation, $this> */
    public function cancellation(): BelongsTo
    {
        return $this->belongsTo(KitchenCancellation::class, 'kitchen_cancellation_id');
    }
}
