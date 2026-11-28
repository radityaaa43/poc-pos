<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockWasteLine extends Model
{
    protected $fillable = ['stock_waste_id', 'product_id', 'qty'];

    protected $casts = ['product_id' => 'integer', 'qty' => 'integer'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
