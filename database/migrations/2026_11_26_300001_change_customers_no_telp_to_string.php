<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A phone number is an identifier, not a quantity: keep leading zeros, "+62" and spaces.
     * Digits already stored keep their value; zeros lost while the column was a bigint cannot be restored.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('no_telp', 30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->bigInteger('no_telp')->change();
        });
    }
};
