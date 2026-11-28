<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // An option's own recipe, the same shape as composite_product_items.
    public function up(): void
    {
        Schema::create('modifier_option_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->decimal('qty', 15, 4)->default(1);
            $table->timestamps();

            $table->unique(['modifier_option_id', 'component_product_id'], 'modifier_components_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_option_components');
    }
};
