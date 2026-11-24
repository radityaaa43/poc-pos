<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GoodsReceivingItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'goods_receiving_id',
        'purchase_order_item_id',
        'product_id',
        'unit_id',
        'conversion_factor',
        'qty_received',
        'notes',
    ];

    protected $casts = [
        'conversion_factor' => 'float',
        'qty_received' => 'integer',
    ];

    public function goodsReceiving()
    {
        return $this->belongsTo(GoodsReceiving::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    /** Lines of supplier returns that still count against this line (everything but cancelled). */
    public function activeReturnItems()
    {
        return $this->hasMany(SupplierReturnItem::class)
            ->whereHas('supplierReturn', fn ($q) => $q->where('status', '!=', 'cancelled'));
    }
}
