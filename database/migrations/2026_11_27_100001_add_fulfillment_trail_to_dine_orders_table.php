<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Fulfillment (accepted, preparing, ready, served, completed) lives in dine_orders.status, apart from payment (the transaction's payment_status).
    // Every step also records when and who, so the kitchen's speed per menu item can be analysed later.
    public function up(): void
    {
        Schema::table('dine_orders', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable()->after('submitted_at');
            $table->foreignId('accepted_by')->nullable()->after('accepted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('preparing_at')->nullable()->after('accepted_by');
            $table->foreignId('preparing_by')->nullable()->after('preparing_at')->constrained('users')->nullOnDelete();
            $table->timestamp('ready_at')->nullable()->after('preparing_by');
            $table->foreignId('ready_by')->nullable()->after('ready_at')->constrained('users')->nullOnDelete();
            $table->timestamp('served_at')->nullable()->after('ready_by');
            $table->foreignId('served_by')->nullable()->after('served_at')->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable()->after('served_by');
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
            foreach (['accepted_by', 'preparing_by', 'ready_by', 'served_by'] as $actor) {
                $table->dropConstrainedForeignId($actor);
            }
            $table->dropColumn(['accepted_at', 'preparing_at', 'ready_at', 'served_at', 'completed_at']);
        });
    }
};
