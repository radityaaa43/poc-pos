<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Fulfillment (accepted, preparing, ready, served) lives in dine_orders.status, apart from payment (the transaction's payment_status). These are its audit timestamps.
    public function up(): void
    {
        Schema::table('dine_orders', function (Blueprint $table) {
            $table->timestamp('preparing_at')->nullable()->after('submitted_at');
            $table->timestamp('ready_at')->nullable()->after('preparing_at');
            $table->timestamp('served_at')->nullable()->after('ready_at');
        });

        // Accept used to write payment_method = cash on a bill nobody had paid yet; mark those still-open bills neutral.
        DB::table('transactions')
            ->where('payment_status', 'unpaid')
            ->where('payment_method', 'cash')
            ->whereIn('id', DB::table('dine_orders')->where('status', 'accepted')->whereNotNull('transaction_id')->select('transaction_id'))
            ->update(['payment_method' => 'open_bill']);
    }

    public function down(): void
    {
        DB::table('transactions')->where('payment_method', 'open_bill')->update(['payment_method' => 'cash']);

        Schema::table('dine_orders', function (Blueprint $table) {
            $table->dropColumn(['preparing_at', 'ready_at', 'served_at']);
        });
    }
};
