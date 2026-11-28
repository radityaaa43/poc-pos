<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which kitchen stations a print device serves (#345, REQ-006 decision 9). No row means all stations, so a K1 shop is
 * unchanged. The table name is shortened on purpose: the default pivot name makes the FK longer than MySQL's 64 chars.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_print_device_station', function (Blueprint $table) {
            $table->foreignId('kitchen_print_device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('kitchen_station_id')->constrained();
            $table->primary(['kitchen_print_device_id', 'kitchen_station_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_print_device_station');
    }
};
