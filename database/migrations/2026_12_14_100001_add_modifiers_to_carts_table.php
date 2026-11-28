<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // REQ-008: a cart line keeps its chosen options and note; line_key ('' = neither) keeps different ones apart.
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            // Sorted option ids; null on a line of a menu that had no modifier groups when it was added.
            $table->json('modifier_option_ids')->nullable();
            // Extra price of those options per unit; `price` already includes it.
            $table->unsignedBigInteger('modifier_total')->default(0);
            $table->string('note', 100)->nullable();
            $table->string('line_key', 40)->default('');
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn(['modifier_option_ids', 'modifier_total', 'note', 'line_key']);
        });
    }
};
