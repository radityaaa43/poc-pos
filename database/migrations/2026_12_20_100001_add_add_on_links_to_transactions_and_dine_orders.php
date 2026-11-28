<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// #251: add-on orders. Null means a normal order, so nothing is backfilled.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Always the root order, never another add-on: the order group is COALESCE(parent_transaction_id, id).
            $table->foreignId('parent_transaction_id')->nullable()->after('cashier_shift_id')->constrained('transactions')->nullOnDelete();
        });

        Schema::table('dine_orders', function (Blueprint $table) {
            // The QR order whose status page this one was ordered from; resolved to a sale link when it is accepted.
            $table->foreignId('add_on_to_dine_order_id')->nullable()->after('transaction_id')->constrained('dine_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dine_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('add_on_to_dine_order_id');
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_transaction_id');
        });
    }
};
