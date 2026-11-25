<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KitchenTicketPrint extends Model
{
    // printed_at is filled by the column default: rows are written with insertOrIgnore, which skips model timestamps.
    public $timestamps = false;

    protected $fillable = ['kitchen_print_station_id', 'kitchen_item_id'];

    protected $casts = ['printed_at' => 'datetime'];

    public function station(): BelongsTo
    {
        return $this->belongsTo(KitchenPrintStation::class, 'kitchen_print_station_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(KitchenItem::class, 'kitchen_item_id');
    }
}
