<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TransactionDetail extends Model
{
    use HasFactory;

    /**
     * fillable
     *
     * @var array
     */
    protected $fillable = [
        'transaction_id',
        'product_id',
        'unit_id',
        'conversion_factor',
        'qty',
        'base_unit_price',
        'unit_price',
        'price',
        'discount_total',
        'cost_total',
        'product_batch_id',
        'pricing_rule_id',
        'pricing_rule_name',
        'pricing_rule_kind',
        'pricing_group_key',
        'pricing_group_label',
    ];

    protected $casts = [
        'qty' => 'integer',
        'base_unit_price' => 'integer',
        'unit_price' => 'integer',
        'price' => 'integer',
        'discount_total' => 'integer',
        'cost_total' => 'integer',
        'pricing_rule_id' => 'integer',
        'pricing_rule_kind' => 'string',
        'conversion_factor' => 'decimal:4',
    ];

    /**
     * Base-unit quantity consumed/returned by this line: selling qty x conversion factor.
     */
    public function baseQuantity(): int
    {
        return (int) round($this->qty * (float) ($this->conversion_factor ?? 1));
    }

    /**
     * Refund value of returning $qtyReturn units after $qtyReturnedBefore were already returned.
     * `price` is the line total, so each unit is a share of it; rounding the running share (not
     * each return) keeps the returns of a line from adding up to more than the line total.
     */
    public function returnAmount(int $qtyReturnedBefore, int $qtyReturn): int
    {
        $qty = max(1, (int) $this->qty);
        $share = fn (int $units) => (int) round((int) $this->price * $units / $qty);

        return $share($qtyReturnedBefore + $qtyReturn) - $share($qtyReturnedBefore);
    }

    /**
     * Profit to reverse when $qtyReturned units of this line come back:
     * (unit sell price - cost_total / qty) x qty returned, rounded once.
     */
    public function returnMargin(int $qtyReturned): int
    {
        $qty = max(1, (int) $this->qty);

        // Legacy lines have no frozen cost_total: use the product's current buy_price, as before.
        $costTotal = $this->cost_total
            ?? (int) round((int) ($this->product?->buy_price ?? 0) * (float) ($this->conversion_factor ?? 1)) * $qty;

        return (int) round(((int) $this->price - $costTotal) * $qtyReturned / $qty);
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function batch()
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function pricingRule()
    {
        return $this->belongsTo(PricingRule::class);
    }

    public function salesReturnItems()
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    public function batchAllocations()
    {
        return $this->hasMany(TransactionDetailBatchAllocation::class);
    }
}
