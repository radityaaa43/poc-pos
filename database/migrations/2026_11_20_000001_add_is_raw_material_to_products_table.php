<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Raw materials (beans, milk, cups) are stocked, purchased and counted, but never sold on the POS.
            $table->boolean('is_raw_material')->default(false)->after('is_composite');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_raw_material');
        });
    }
};
