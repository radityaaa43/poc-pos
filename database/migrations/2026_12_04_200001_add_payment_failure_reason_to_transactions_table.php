<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Why the last payment attempt failed, for the guest status page: 'expired' (Kedaluwarsa) or 'gateway_error'
    // (the charge could not be created). Both gateways report an expiry as `failed`, so the status alone cannot say.
    // Null = not expired, no reason recorded.
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('payment_failure_reason', 20)->nullable()->after('payment_attempt');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('payment_failure_reason');
        });
    }
};
