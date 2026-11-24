<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A pack row's buy_price is the price of one pack. Older data copied the
     * product's per-piece price onto the pack row; reprice only those rows
     * (buy_price at or below the base price). Rows already priced per pack stay.
     */
    public function up(): void
    {
        DB::table('product_units')
            ->join('products', 'products.id', '=', 'product_units.product_id')
            ->where('product_units.is_base', false)
            ->where('product_units.conversion_factor', '>', 1)
            ->whereColumn('product_units.buy_price', '<=', 'products.buy_price')
            ->select('product_units.id', 'product_units.conversion_factor', 'products.buy_price as base_price')
            ->get()
            ->each(fn ($row) => DB::table('product_units')
                ->where('id', $row->id)
                ->update(['buy_price' => (int) round($row->base_price * $row->conversion_factor)]));
    }

    public function down(): void
    {
        // No-op: the old per-piece prices on pack rows were wrong and cannot be told apart afterwards.
    }
};
