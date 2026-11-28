<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #576: the PPN a sale's tax-inclusive prices already carried. `tax_total` holds the whole PPN (added on top plus
 * included in prices); this column is the included part, so a bill can be restored without charging it again.
 * A sale from before the fix reads 0, which keeps its restore exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('tax_included')->default(0)->after('tax_total');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('tax_included');
        });
    }
};
