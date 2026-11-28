<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The guest's picked options until staff accept the order: the ids to re-check availability, and the shown
    // snapshot (name + price) so the accepted line keeps the price the guest was offered.
    public function up(): void
    {
        Schema::table('dine_order_items', function (Blueprint $table) {
            $table->json('modifier_option_ids')->nullable();
            $table->json('modifier_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dine_order_items', function (Blueprint $table) {
            $table->dropColumn(['modifier_option_ids', 'modifier_snapshot']);
        });
    }
};
