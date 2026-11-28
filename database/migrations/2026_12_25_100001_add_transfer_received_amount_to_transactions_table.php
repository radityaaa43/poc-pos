<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The amount a bank transfer actually delivered, entered on confirm (#507): what the recap counts and the excess is
    // measured against. Null for old or non-transfer sales, which keep the inference.
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('transfer_received_amount')->nullable()->after('overpaid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('transfer_received_amount');
        });
    }
};
