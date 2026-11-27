<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// #366: the device that prints kitchen tickets is a "print device"; "station" is REQ-006's kitchen station.
// Same data, new names (the two migrations that created the tables are merged and stay as they are).
return new class extends Migration
{
    public function up(): void
    {
        $this->swap('station', 'device');
    }

    public function down(): void
    {
        $this->swap('device', 'station');
    }

    /** Renames the table and the ticket log's column, dropping and re-adding the keys so their names follow. */
    private function swap(string $from, string $to): void
    {
        $oldTable = "kitchen_print_{$from}s";
        $newTable = "kitchen_print_{$to}s";
        $oldColumn = "kitchen_print_{$from}_id";
        $newColumn = "kitchen_print_{$to}_id";

        // The foreign keys go first: MySQL will not drop the unique index that backs outlet_id while the key exists.
        Schema::table('kitchen_ticket_prints', function (Blueprint $table) use ($oldColumn, $from) {
            $table->dropForeign([$oldColumn]);
            $table->dropUnique("kitchen_ticket_prints_{$from}_item_unique");
        });
        Schema::table($oldTable, function (Blueprint $table) {
            $table->dropForeign(['outlet_id']);
            $table->dropUnique(['outlet_id', 'name']);
        });

        Schema::rename($oldTable, $newTable);
        Schema::table('kitchen_ticket_prints', fn (Blueprint $table) => $table->renameColumn($oldColumn, $newColumn));

        Schema::table($newTable, function (Blueprint $table) {
            $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            $table->unique(['outlet_id', 'name']);
        });
        Schema::table('kitchen_ticket_prints', function (Blueprint $table) use ($newColumn, $newTable, $to) {
            $table->foreign($newColumn)->references('id')->on($newTable)->cascadeOnDelete();
            $table->unique([$newColumn, 'kitchen_item_id'], "kitchen_ticket_prints_{$to}_item_unique");
        });
    }
};
