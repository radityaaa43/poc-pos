<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockWasteMove extends Model
{
    protected $fillable = ['stock_waste_id', 'line_id', 'product_id', 'qty', 'unit_cost', 'value'];

    protected $casts = ['line_id' => 'integer', 'product_id' => 'integer', 'qty' => 'integer', 'unit_cost' => 'float', 'value' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
