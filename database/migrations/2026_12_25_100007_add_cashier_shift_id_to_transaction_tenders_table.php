<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_tenders', function (Blueprint $table) {
            // The shift that took a rest of a split sale (#530 BR-4). Null means the sale's own shift.
            $table->foreignId('cashier_shift_id')
                ->nullable()
                ->after('bank_account_id')
                ->index()
                ->constrained('cashier_shifts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transaction_tenders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cashier_shift_id');
        });
    }
};
