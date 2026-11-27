<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'needs_preparation',
        'product_batch_id',
        'pricing_rule_id',
        'pricing_rule_name',
        'pricing_rule_kind',
        'pricing_group_key',
        'pricing_group_label',
        'note',
        'modifier_total',
    ];

    protected $casts = [
        'qty' => 'integer',
        'base_unit_price' => 'integer',
        'unit_price' => 'integer',
        'price' => 'integer',
        'discount_total' => 'integer',
        'cost_total' => 'integer',
        'needs_preparation' => 'boolean',
        'pricing_rule_id' => 'integer',
        'pricing_rule_kind' => 'string',
        'conversion_factor' => 'decimal:4',
        'modifier_total' => 'integer',
    ];

    /**
     * Whether this line is made in the kitchen: the flag frozen at sale time, else (a line written before the
     * snapshot existed) the product's flag now. The one rule for intake, self-heal and the BR-1 serve check.
     */
    public function goesToKitchen(): bool
    {
        return $this->needs_preparation ?? (bool) $this->product?->needs_preparation;
    }

    /**
     * SQL twin of goesToKitchen().
     *
     * @param  Builder<TransactionDetail>  $query
     * @return Builder<TransactionDetail>
     */
    public function scopeGoesToKitchen(Builder $query): Builder
    {
        return $query->where(fn (Builder $line) => $line
            ->where('transaction_details.needs_preparation', true)
            ->orWhere(fn (Builder $old) => $old
                ->whereNull('transaction_details.needs_preparation')
                ->whereHas('product', fn ($product) => $product->where('needs_preparation', true))));
    }

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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
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

    /** @return BelongsTo<PricingRule, $this> */
    public function pricingRule(): BelongsTo
    {
        return $this->belongsTo(PricingRule::class);
    }

    /** @return HasMany<TransactionDetailModifier, $this> */
    public function modifiers(): HasMany
    {
        return $this->hasMany(TransactionDetailModifier::class)->orderBy('id');
    }

    /**
     * What a receipt prints under the item line: each option with its extra price, then the note. Read from the
     * sale's own rows, never from the master (REQ-008 AC-12, AC-14).
     *
     * @return list<string>
     */
    public function receiptExtras(): array
    {
        $lines = $this->modifiers->map(fn (TransactionDetailModifier $row) => '+ '.$row->option_name
            .($row->price_delta ? ' '.number_format($row->price_delta, 0, ',', '.') : ''))->values()->all();

        return $this->note === null ? $lines : [...$lines, __('messages.modifiers.note_line', ['note' => $this->note])];
    }

    /** @return HasMany<SalesReturnItem, $this> */
    public function salesReturnItems(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class);
    }

    /** @return HasMany<TransactionDetailBatchAllocation, $this> */
    public function batchAllocations(): HasMany
    {
        return $this->hasMany(TransactionDetailBatchAllocation::class);
    }
}
