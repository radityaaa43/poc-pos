<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifier_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            // Whole rupiah added to the menu price.
            $table->unsignedInteger('price_delta')->default(0);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['modifier_group_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_options');
    }
};
