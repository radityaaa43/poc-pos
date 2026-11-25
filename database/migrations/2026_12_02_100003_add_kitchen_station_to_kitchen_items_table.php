<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable on purpose: additive on the frozen kitchen_items contract, so older writers keep working.
        Schema::table('kitchen_items', function (Blueprint $table) {
            $table->foreignId('kitchen_station_id')->nullable()->constrained('kitchen_stations');
        });

        // Release data: items already in the kitchen belong to the default station.
        DB::table('kitchen_items')->update([
            'kitchen_station_id' => DB::table('kitchen_stations')->where('is_default', true)->value('id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('kitchen_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('kitchen_station_id');
        });
    }
};
