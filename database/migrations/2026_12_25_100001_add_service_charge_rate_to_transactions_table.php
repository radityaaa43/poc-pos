<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REQ-010: the rate a sale's service charge was computed with (0 when not dine-in), which its amount cannot give
// back once a discount covers the bill. Earlier sales stay null.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->decimal('service_charge_rate', 5, 2)->nullable()->after('service_charge');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('service_charge_rate');
        });
    }
};
