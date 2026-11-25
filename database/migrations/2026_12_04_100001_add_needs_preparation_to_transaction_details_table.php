<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Whether the product needed preparation when the line was sold, so a later flag change cannot change what the
        // kitchen gets. Null = a line written before this column: readers fall back to the product's flag. No backfill.
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->boolean('needs_preparation')->nullable()->after('cost_total');
        });
    }

    public function down(): void
    {
        Schema::table('transaction_details', function (Blueprint $table) {
            $table->dropColumn('needs_preparation');
        });
    }
};
