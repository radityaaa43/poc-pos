<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dine_reservations', function (Blueprint $table) {
            $table->id();
            // Its own outlet: a table without an area is shared by every outlet, the list is still per outlet.
            $table->foreignId('outlet_id')->constrained('outlets')->cascadeOnDelete();
            // Null once the table is deleted: marked reservations stay as history (a booked one blocks the delete).
            $table->foreignId('dine_table_id')->nullable()->constrained('dine_tables')->nullOnDelete();
            // guest_name, not name: the audit log would copy a `name` column as its target label.
            $table->string('guest_name', 100);
            $table->string('phone', 30); // personal data: never printed, never written to the audit log
            $table->unsignedSmallInteger('party_size');
            $table->dateTime('reserved_at'); // wall time in the app timezone
            $table->string('note', 500)->nullable();
            $table->string('status', 12)->default('booked'); // booked, arrived, cancelled, no_show
            $table->timestamps();

            $table->index(['dine_table_id', 'status', 'reserved_at']); // clash check and table status
            $table->index(['outlet_id', 'reserved_at']); // daily list
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dine_reservations');
    }
};
