<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Outlets that already sold on pay later keep it after release; every other outlet starts off.
        // Per-outlet rows only: a global (outlet_id null) row would turn it on for every outlet.
        $outletIds = DB::table('transactions')
            ->join('warehouses', 'warehouses.id', '=', 'transactions.warehouse_id')
            ->where('transactions.payment_method', 'pay_later')
            ->whereNotNull('warehouses.outlet_id')
            ->distinct()
            ->pluck('warehouses.outlet_id');

        foreach ($outletIds as $outletId) {
            DB::table('settings')->updateOrInsert(
                ['key' => 'pay_later_enabled', 'outlet_id' => $outletId],
                ['value' => '1', 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'pay_later_enabled')->delete();
    }
};
