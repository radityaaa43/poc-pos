<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // shortcut: rows are never pruned (a few hundred per outlet per day), revisit with the reprint log.
        Schema::create('kitchen_ticket_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_print_station_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kitchen_item_id')->constrained('kitchen_items')->cascadeOnDelete();
            $table->timestamp('printed_at')->useCurrent();

            // Once per station and item: the retry guard behind insertOrIgnore.
            $table->unique(['kitchen_print_station_id', 'kitchen_item_id'], 'kitchen_ticket_prints_station_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_ticket_prints');
    }
};
