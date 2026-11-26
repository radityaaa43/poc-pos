<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_cancellation_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_print_device_id')->constrained('kitchen_print_devices')->cascadeOnDelete();
            $table->foreignId('kitchen_cancellation_id')->constrained('kitchen_cancellations')->cascadeOnDelete();
            $table->timestamp('printed_at')->useCurrent();

            // Once per device and cancellation: the retry guard behind insertOrIgnore.
            $table->unique(['kitchen_print_device_id', 'kitchen_cancellation_id'], 'kitchen_cancel_prints_device_cancel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_cancellation_prints');
    }
};
