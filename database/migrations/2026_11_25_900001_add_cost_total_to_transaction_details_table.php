<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // HPP of the line, frozen at sale time, whole rupiah (REQ-002 §6.6).
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->bigInteger('cost_total')->nullable()->after('discount_total');
        });

        // Estimate from the current reference prices, close to what Insights showed before.
        // Done in PHP chunks (not one UPDATE ... JOIN) so it runs on SQLite as well as MySQL.
        $recipes = DB::table('composite_product_items')->get()->groupBy('composite_product_id');

        DB::table('transaction_details')->whereNull('cost_total')->orderBy('id')->chunkById(500, function ($details) use ($recipes) {
            $buyPrice = DB::table('products')
                ->whereIn('id', $details->pluck('product_id')->merge($recipes->flatten(1)->pluck('component_product_id')))
                ->pluck('buy_price', 'id');

            foreach ($details as $detail) {
                $cost = isset($recipes[$detail->product_id])
                    ? $recipes[$detail->product_id]->sum(fn ($item) => ($buyPrice[$item->component_product_id] ?? 0) * (float) $item->qty) * $detail->qty
                    : ($buyPrice[$detail->product_id] ?? 0) * $detail->qty * (float) ($detail->conversion_factor ?? 1);

                DB::table('transaction_details')->where('id', $detail->id)->update(['cost_total' => (int) round($cost)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->dropColumn('cost_total');
        });
    }
};
