<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_print_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // created_at is also the cut-off: a station prints only items that reached the kitchen at or after it.
            $table->timestamps();

            $table->unique(['outlet_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_print_stations');
    }
};
