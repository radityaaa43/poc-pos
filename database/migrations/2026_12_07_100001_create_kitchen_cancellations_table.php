<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per kitchen line a completed return cancelled (#332): what a DIBATALKAN ticket is built from.
        // shortcut: rows are never pruned (one per returned kitchen line), revisit with the reprint log.
        Schema::create('kitchen_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_item_id')->constrained('kitchen_items')->cascadeOnDelete();
            $table->foreignId('sales_return_id')->constrained('sales_returns')->cascadeOnDelete();
            $table->unsignedInteger('qty_cancelled'); // what this return cancelled, not what the item had
            $table->timestamp('created_at')->useCurrent(); // immutable: no updated_at

            // The ticket poll reads the last 12 hours of events every few seconds per device.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_cancellations');
    }
};
