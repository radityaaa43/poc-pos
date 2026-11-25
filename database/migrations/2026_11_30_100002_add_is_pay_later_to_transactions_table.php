<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // A later gateway settle overwrites payment_method, so a pay-later bill is marked by this flag instead.
            $table->boolean('is_pay_later')->default(false)->after('payment_status');
        });

        // Pay-later sales made before release carry the flag too, so the open ones can be listed and settled.
        DB::table('transactions')->where('payment_method', 'pay_later')->update(['is_pay_later' => true]);
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('is_pay_later');
        });
    }
};
