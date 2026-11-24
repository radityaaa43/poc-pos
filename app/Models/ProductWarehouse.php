<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class ProductWarehouse extends Pivot
{
    public $incrementing = true;

    protected $table = 'product_warehouse';

    protected static function booted(): void
    {
        // A row created without a cost starts at the product's reference price (REQ-002 §6.5).
        static::creating(function (self $row) {
            $row->avg_cost ??= Product::whereKey($row->product_id)->value('buy_price');
        });
    }

    protected function casts(): array
    {
        return [
            'stock' => 'integer',
            'avg_cost' => 'float',
        ];
    }
}
