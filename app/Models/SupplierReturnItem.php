<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_return_id',
        'goods_receiving_item_id',
        'product_id',
        'unit_id',
        'conversion_factor',
        'qty_returned',
        'unit_price',
        'reason',
        'notes',
    ];

    protected $casts = [
        'conversion_factor' => 'float',
        'qty_returned' => 'integer',
        'unit_price' => 'float',
    ];

    public function supplierReturn()
    {
        return $this->belongsTo(SupplierReturn::class);
    }

    public function goodsReceivingItem()
    {
        return $this->belongsTo(GoodsReceivingItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }
}
