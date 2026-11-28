<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dine_orders', function (Blueprint $table) {
            // `qr` or `pos` (#426). Every order made before this was a guest's, so the default is the backfill.
            $table->string('source', 8)->default('qr')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('dine_orders', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
