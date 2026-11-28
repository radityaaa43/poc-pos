<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->string('note', 100)->nullable();
            // Extra price of the chosen options per unit, at sale time.
            $table->unsignedBigInteger('modifier_total')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->dropColumn(['note', 'modifier_total']);
        });
    }
};
