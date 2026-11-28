<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REQ-010 AC-10: a return records the service charge and tax it gave back. Returns completed before this stay 0.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->bigInteger('service_refunded')->default(0)->after('total_return_amount');
            $table->bigInteger('tax_refunded')->default(0)->after('service_refunded');
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropColumn(['service_refunded', 'tax_refunded']);
        });
    }
};
