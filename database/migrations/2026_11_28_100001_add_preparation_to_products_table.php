<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // REQ-003: products the kitchen makes to order (e.g. drinks, dishes) carry a prep flag and an optional target.
            $table->boolean('needs_preparation')->default(false)->after('is_raw_material');
            $table->unsignedSmallInteger('prep_target_minutes')->nullable()->after('needs_preparation');
        });

        // Composite (recipe) products start flagged on release; the flag is editable per product afterwards.
        DB::table('products')->where('is_composite', true)->update(['needs_preparation' => true]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['needs_preparation', 'prep_target_minutes']);
        });
    }
};
