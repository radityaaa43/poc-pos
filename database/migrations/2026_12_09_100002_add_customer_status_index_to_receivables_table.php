<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->index(['customer_id', 'status'], 'receivables_customer_status_index');
        });
    }

    public function down(): void
    {
        // MySQL silently drops the foreign key's own index once the composite one can serve it, and then refuses
        // to drop the composite one: give the foreign key an index back first.
        if (! Schema::hasIndex('receivables', ['customer_id'])) {
            Schema::table('receivables', function (Blueprint $table) {
                $table->index('customer_id', 'receivables_customer_id_foreign');
            });
        }

        Schema::table('receivables', function (Blueprint $table) {
            $table->dropIndex('receivables_customer_status_index');
        });
    }
};
