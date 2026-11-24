<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Null unit_id means the stock unit with factor 1 (rows from before purchase units).
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('product_id')->constrained('units')->nullOnDelete();
            $table->decimal('conversion_factor', 15, 4)->default(1)->after('unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn('conversion_factor');
        });
    }
};
