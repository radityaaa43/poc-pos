<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A pack row's sell_price is the price of one pack. Rows saved before #40 carry
     * the per-piece price; reprice only those (sell_price at or below the base
     * price). Rows already above the base price (e.g. a discounted pack) stay.
     */
    public function up(): void
    {
        DB::transaction(fn () => DB::table('product_units')
            ->join('products', 'products.id', '=', 'product_units.product_id')
            ->where('product_units.is_base', false)
            ->where('product_units.conversion_factor', '>', 1)
            ->whereColumn('product_units.sell_price', '<=', 'products.sell_price')
            ->select('product_units.id', 'product_units.conversion_factor', 'products.sell_price as base_price')
            ->get()
            ->each(fn ($row) => DB::table('product_units')
                ->where('id', $row->id)
                ->update(['sell_price' => (int) round($row->base_price * $row->conversion_factor)])));
    }

    public function down(): void
    {
        // No-op: the old per-piece prices on pack rows were wrong and cannot be told apart afterwards.
    }
};
