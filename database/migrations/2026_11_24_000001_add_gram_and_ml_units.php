<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const UNITS = [
        ['code' => 'GRAM', 'name' => 'Gram', 'symbol' => 'g'],
        ['code' => 'ML', 'name' => 'Mililiter', 'symbol' => 'ml'],
    ];

    public function up(): void
    {
        foreach (self::UNITS as $unit) {
            if (DB::table('units')->where('code', $unit['code'])->exists()) {
                continue;
            }

            DB::table('units')->insert($unit + ['created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::UNITS as $unit) {
            $id = DB::table('units')->where('code', $unit['code'])->value('id');

            if ($id && ! DB::table('product_units')->where('unit_id', $id)->exists()) {
                DB::table('units')->where('id', $id)->delete();
            }
        }
    }
};
