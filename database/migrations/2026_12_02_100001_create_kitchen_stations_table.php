<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            // 0 while the station is live, its own id once soft deleted: the unique index below then blocks two live
            // stations with one name but lets a deleted name be reused. unique(name, deleted_at) would not: NULLs are distinct.
            $table->unsignedBigInteger('deleted_key')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['name', 'deleted_key']);
        });

        // Release default: every shop starts with one station, and a product with no station means this one.
        DB::table('kitchen_stations')->insert(['name' => 'Dapur', 'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('kitchen_stations');
    }
};
