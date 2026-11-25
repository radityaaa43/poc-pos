<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // payment_attempt counts the payment attempts of one sale (0 = the original one). A retry bumps it, and the
    // gateway order id becomes "{invoice}-r{n}", so a late callback of an older attempt can be told apart.
    // late_superseded_payment_at marks a sale whose old attempt was paid after a successful retry (manual refund).
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_attempt')->default(0)->after('payment_status');
            $table->timestamp('late_superseded_payment_at')->nullable()->after('payment_attempt');
            $table->index('late_superseded_payment_at');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['late_superseded_payment_at']);
            $table->dropColumn(['payment_attempt', 'late_superseded_payment_at']);
        });
    }
};
