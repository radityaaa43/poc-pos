<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// REQ-010: a sale keeps the bill rules it was computed with. Earlier sales stay 0 / null and are never recomputed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->bigInteger('service_charge')->default(0)->after('tax_total');
            $table->string('tax_kind', 8)->nullable()->after('service_charge'); // null reads as PPN
            $table->unsignedBigInteger('tax_base')->nullable()->after('tax_kind'); // amount the tax was computed on
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn(['service_charge', 'tax_kind', 'tax_base']);
        });
    }
};
