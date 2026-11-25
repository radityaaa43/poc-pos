<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A recipe's cost is its components' cost (#73), so the manual buy price it carried since
        // upstream is dead. Zeroed so no report, export or POS payload can show it as the cost.
        DB::table('products')->where('is_composite', true)->update(['buy_price' => 0]);
    }

    public function down(): void
    {
        // The old manual values are not recoverable, and nothing reads them.
    }
};
