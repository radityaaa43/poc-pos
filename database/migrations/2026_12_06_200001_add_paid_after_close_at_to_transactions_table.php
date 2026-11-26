<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // When a gateway payment settled a bill whose owning shift was already closed ("Dibayar setelah shift ditutup").
    // The closed recap is a stored snapshot and is never rewritten, so this flag is the only trace of money that
    // arrived after it. Null = never. Set once, by the payment webhook.
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->timestamp('paid_after_close_at')->nullable()->after('late_superseded_payment_at');
            $table->index('paid_after_close_at');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['paid_after_close_at']);
            $table->dropColumn('paid_after_close_at');
        });
    }
};
