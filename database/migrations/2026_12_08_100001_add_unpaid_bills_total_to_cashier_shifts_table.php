<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            // REQ-003 AC-13: unpaid bills a forced close booked as a shortfall; 0 = none booked.
            $table->unsignedBigInteger('unpaid_bills_total')->default(0)->after('cash_difference');
        });
    }

    public function down(): void
    {
        Schema::table('cashier_shifts', function (Blueprint $table) {
            $table->dropColumn('unpaid_bills_total');
        });
    }
};
