<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The options of a sold line as they were then (REQ-008 decision 11): receipts never read the master.
    public function up(): void
    {
        Schema::create('transaction_detail_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_detail_id')->constrained()->cascadeOnDelete();
            // For the recipe a return restocks and the per-option report; the names and price below are the record.
            $table->foreignId('modifier_option_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_name', 100);
            $table->string('option_name', 100);
            $table->unsignedInteger('price_delta')->default(0);
            $table->timestamps();

            $table->index('modifier_option_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_detail_modifiers');
    }
};
