<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('may_buy_on_credit')->default(false);
        });

        // Customers who have already had a receivable keep buying on credit after release.
        DB::table('customers')
            ->whereIn('id', DB::table('receivables')->whereNotNull('customer_id')->select('customer_id'))
            ->update(['may_buy_on_credit' => true]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('may_buy_on_credit');
        });
    }
};
