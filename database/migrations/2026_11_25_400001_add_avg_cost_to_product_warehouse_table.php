<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rupiah per stock unit in that warehouse (weighted average, REQ-002 §6.5).
        Schema::table('product_warehouse', function (Blueprint $table) {
            $table->decimal('avg_cost', 15, 4)->nullable()->after('stock');
        });

        // Correlated subquery (not UPDATE ... JOIN) so it runs on SQLite as well as MySQL.
        DB::statement('UPDATE product_warehouse SET avg_cost = (SELECT buy_price FROM products WHERE products.id = product_warehouse.product_id)');
    }

    public function down(): void
    {
        Schema::table('product_warehouse', function (Blueprint $table) {
            $table->dropColumn('avg_cost');
        });
    }
};
