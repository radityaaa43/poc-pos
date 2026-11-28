<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_wastes', function (Blueprint $table) {
            $table->id();
            // Restrict, not cascade, here and on product_id below: a waste trail must outlive neither.
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->string('reason', 20);
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('total_value')->default(0);
            $table->string('client_key', 36)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejected_reason', 500)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['warehouse_id', 'status', 'recorded_at']);
            $table->index(['warehouse_id', 'cancelled_at']);
            $table->index(['status', 'created_at']);
        });

        // What the user typed.
        Schema::create('stock_waste_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_waste_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();
        });

        // What left the shelf: one row per line and stock product, so a menu appears as its ingredients.
        Schema::create('stock_waste_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_waste_id')->constrained()->cascadeOnDelete();
            $table->foreignId('line_id')->constrained('stock_waste_lines')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->decimal('unit_cost', 15, 4);
            $table->unsignedBigInteger('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_waste_moves');
        Schema::dropIfExists('stock_waste_lines');
        Schema::dropIfExists('stock_wastes');
    }
};
