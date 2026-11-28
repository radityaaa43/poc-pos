<?php

namespace App\Testing\E2e\Scenarios;

use App\Http\Controllers\TourController;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StockWaste;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Opname variance group (e2e folder 86); its own outlet, warehouse and user so no other spec sees it. */
class Opname
{
    /**
     * @return array<string, \Closure(array<string, string>): mixed>
     */
    public static function registry(): array
    {
        return [
            // --arg=email=<login>: an outlet, warehouse and opname viewer of its own, plus finalized opnames
            // 4, 3, 2 and 1 months ago (variance 2000, 3000, 5000, 0 at a 1000 unit cost) and one recorded
            // waste 2 months ago. Returns the email and the expected variance per month.
            'opname.variance' => function (array $options): array {
                return DB::transaction(function () use ($options) {
                    $suffix = Str::upper(Str::random(5));
                    $outlet = Outlet::create([
                        'code' => "OPN{$suffix}", 'name' => "Outlet Opname {$suffix}",
                        'is_active' => true, 'is_sales_enabled' => true,
                    ]);
                    $warehouse = Warehouse::create([
                        'code' => "WH-OPN{$suffix}", 'name' => "Gudang Opname {$suffix}",
                        'type' => 'branch', 'is_active' => true, 'sort_order' => 1, 'outlet_id' => $outlet->id,
                    ]);

                    $user = User::create([
                        'name' => "Opname {$suffix}",
                        'email' => $options['email'],
                        'password' => Hash::make(E2eSeeder::PASSWORD),
                        'completed_tours' => TourController::TOURS, // else a tour overlay blocks clicks
                    ]);
                    $user->markEmailAsVerified();
                    $user->syncRoles([]);
                    $user->syncPermissions(['stock-opnames-access']);
                    $user->outlets()->sync([$outlet->id => ['is_default' => true]]);

                    $product = Product::create([
                        'category_id' => Category::where('name', 'E2E Kategori')->firstOrFail()->id,
                        'barcode' => "E2E-OPN{$suffix}", 'sku' => "E2E-OPN{$suffix}",
                        'title' => "Bahan Opname {$suffix}", 'description' => 'Produk E2E',
                        'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 100,
                        'tax_type' => 'exclusive', 'tax_rate' => 0,
                    ]);
                    $warehouse->products()->attach($product->id, ['stock' => 100, 'avg_cost' => 1000]);

                    $now = now()->setTimezone(config('app.timezone'));
                    $at = fn (int $monthsAgo) => $now->copy()->startOfMonth()->subMonths($monthsAgo)->addDays(9)->setTime(10, 0, 0);

                    // [months ago, difference, expected value] — the average cost is 1000, both signs absolute.
                    foreach ([[4, 2, 2000], [3, -3, 3000], [2, 5, 5000], [1, 0, 0]] as [$monthsAgo, $difference, $value]) {
                        $opname = StockOpname::create([
                            'code' => 'SO-'.Str::upper(Str::random(10)),
                            'warehouse_id' => $warehouse->id,
                            'status' => 'finalized',
                            'finalized_at' => $at($monthsAgo),
                        ]);
                        StockOpnameItem::create([
                            'stock_opname_id' => $opname->id, 'product_id' => $product->id,
                            'system_stock' => 10, 'physical_stock' => 10 + $difference, 'difference' => $difference,
                            'adjustment_reason' => $difference !== 0 ? 'Barang rusak' : null,
                        ]);
                    }

                    StockWaste::create([
                        'warehouse_id' => $warehouse->id, 'status' => StockWaste::STATUS_RECORDED,
                        'reason' => 'expired', 'total_value' => 1000, 'recorded_at' => $at(2),
                    ]);

                    return [
                        'email' => $user->email,
                        'start_months_ago' => 2,
                        'values' => ['4' => 2000, '3' => 3000, '2' => 5000, '1' => 0],
                    ];
                });
            },
        ];
    }
}
