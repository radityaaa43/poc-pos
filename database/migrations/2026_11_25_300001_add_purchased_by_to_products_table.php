<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // central / outlet; required for raw materials in the app, null keeps today's reorder behaviour.
        Schema::table('products', function (Blueprint $table) {
            $table->string('purchased_by')->nullable()->after('is_raw_material');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('purchased_by');
        });
    }
};
