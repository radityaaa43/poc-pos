<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A return records the discount it took off its items and the points it moved (#545). Returns completed before this
// release stay 0, which is what they recorded: no discount deducted, no points moved.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->bigInteger('discount_deducted')->default(0)->after('total_return_amount');
            $table->unsignedInteger('points_restored')->default(0)->after('discount_deducted');
            $table->unsignedInteger('points_reversed')->default(0)->after('points_restored');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn(['discount_deducted', 'points_restored', 'points_reversed']);
        });
    }
};
