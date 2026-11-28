<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The guest's picked options (sorted ids) until staff accept the order; accept resolves them again and snapshots them.
    public function up(): void
    {
        Schema::table('dine_order_items', function (Blueprint $table) {
            $table->json('modifier_option_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dine_order_items', function (Blueprint $table) {
            $table->dropColumn('modifier_option_ids');
        });
    }
};
