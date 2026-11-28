<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A row means the option is switched OFF at that outlet; no row means offered, so a new outlet needs no backfill.
    public function up(): void
    {
        Schema::create('modifier_option_outlet', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();

            $table->unique(['modifier_option_id', 'outlet_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_option_outlet');
    }
};
