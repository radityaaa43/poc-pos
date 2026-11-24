<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rupiah per stock unit, the source warehouse's average at send time (REQ-002 §6.5). Null on transfers sent before this existed.
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 4)->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
