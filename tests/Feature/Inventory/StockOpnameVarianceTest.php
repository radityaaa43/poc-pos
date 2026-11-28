<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StockWaste;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\StockOpnameVarianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StockOpnameVarianceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        foreach (['stock-opnames-access', 'stock-opnames-finalize'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    public function test_empty_months_are_present_in_order(): void
    {
        $now = Carbon::parse('2026-06-15 12:00:00');
        $result = $this->service()->byMonth([], $now);

        $this->assertCount(12, $result['months']);
        $this->assertSame('2025-07', $result['months'][0]['month']);
        $this->assertSame('2026-06', $result['months'][11]['month']);
        foreach ($result['months'] as $row) {
            $this->assertSame(0, $row['opnames']);
            $this->assertNull($row['avg_value']);
            $this->assertNull($row['avg_items_off']);
            $this->assertSame('before', $row['phase']);
            $this->assertFalse($row['estimated']);
        }
        $this->assertNull($result['waste_started_month']);
    }

    public function test_draft_opnames_are_not_counted(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $product = $this->product();
        $this->attach($warehouse, $product, 1000);

        $this->finalizedOpname($warehouse, '2026-05-10 10:00:00', [[$product, -2, 1000]], status: 'draft');

        $row = $this->row($this->service()->byMonth([$warehouse->id], $this->clock()), '2026-05');
        $this->assertSame(0, $row['opnames']);
        $this->assertNull($row['avg_value']);
    }

    public function test_opname_without_difference_counts_with_zero(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $product = $this->product();

        $this->finalizedOpname($warehouse, '2026-05-10 10:00:00', [[$product, 0, null]]);

        $row = $this->row($this->service()->byMonth([$warehouse->id], $this->clock()), '2026-05');
        $this->assertSame(1, $row['opnames']);
        $this->assertSame(0, $row['avg_value']);
        $this->assertSame(0.0, $row['avg_items_off']);
    }

    public function test_gains_and_losses_both_add_to_the_absolute_variance(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $gain = $this->product();
        $loss = $this->product();
        $this->attach($warehouse, $gain, 1000);
        $this->attach($warehouse, $loss, 1000);

        // +3 (gain) and -2 (loss) valued at 1.000 each: 3.000 + 2.000, no netting.
        $this->finalizedOpname($warehouse, '2026-05-10 10:00:00', [[$gain, 3, 1000], [$loss, -2, 1000]]);

        $row = $this->row($this->service()->byMonth([$warehouse->id], $this->clock()), '2026-05');
        $this->assertSame(5000, $row['avg_value']);
        $this->assertSame(2.0, $row['avg_items_off']);
    }

    public function test_month_edge_uses_the_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $product = $this->product();
        $this->attach($warehouse, $product, 1000);

        $lastMinute = StockOpname::create([
            'code' => 'SO-'.Str::upper(Str::random(8)), 'warehouse_id' => $warehouse->id,
            'status' => 'finalized', 'finalized_at' => '2026-05-31 23:59:00',
        ]);
        StockOpnameItem::create([
            'stock_opname_id' => $lastMinute->id, 'product_id' => $product->id,
            'system_stock' => 10, 'physical_stock' => 8, 'difference' => -2,
        ]);
        $firstMinute = StockOpname::create([
            'code' => 'SO-'.Str::upper(Str::random(8)), 'warehouse_id' => $warehouse->id,
            'status' => 'finalized', 'finalized_at' => '2026-06-01 00:00:00',
        ]);
        StockOpnameItem::create([
            'stock_opname_id' => $firstMinute->id, 'product_id' => $product->id,
            'system_stock' => 10, 'physical_stock' => 9, 'difference' => -1,
        ]);

        $result = $this->service()->byMonth([$warehouse->id], Carbon::parse('2026-06-15 12:00:00', 'Asia/Jakarta'));

        $this->assertSame(1, $this->row($result, '2026-05')['opnames']);
        $this->assertSame(2000, $this->row($result, '2026-05')['avg_value']);
        $this->assertSame(1, $this->row($result, '2026-06')['opnames']);
        $this->assertSame(1000, $this->row($result, '2026-06')['avg_value']);
    }

    public function test_window_is_twelve_months(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $product = $this->product();
        $this->attach($warehouse, $product, 1000);

        // 13 months ago (outside) and 11 months ago (inside, the first row of the window).
        $this->finalizedOpname($warehouse, '2025-05-10 10:00:00', [[$product, -5, 1000]]);
        $this->finalizedOpname($warehouse, '2025-07-10 10:00:00', [[$product, -1, 1000]]);

        $result = $this->service()->byMonth([$warehouse->id], $this->clock());

        $this->assertSame(1, $this->row($result, '2025-07')['opnames']);
        $this->assertSame(1000, $this->row($result, '2025-07')['avg_value']);
        $this->assertSame([], array_filter($result['months'], fn ($row) => $row['month'] === '2025-05'));
    }

    public function test_no_waste_means_all_before(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $product = $this->product();
        $this->attach($warehouse, $product, 1000);
        $this->finalizedOpname($warehouse, '2026-05-10 10:00:00', [[$product, -1, 1000]]);

        $result = $this->service()->byMonth([$warehouse->id], $this->clock());

        $this->assertNull($result['waste_started_month']);
        foreach ($result['months'] as $row) {
            $this->assertSame('before', $row['phase']);
        }
    }

    public function test_pending_and_rejected_waste_do_not_start_the_period(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $this->waste($warehouse, StockWaste::STATUS_PENDING, '2026-03-10 10:00:00');
        $this->waste($warehouse, StockWaste::STATUS_REJECTED, '2026-04-10 10:00:00');

        $result = $this->service()->byMonth([$warehouse->id], $this->clock());

        $this->assertNull($result['waste_started_month']);
    }

    public function test_a_cancelled_first_record_still_starts_the_period(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $this->waste($warehouse, StockWaste::STATUS_CANCELLED, '2026-03-10 10:00:00', '2026-04-01 10:00:00');

        $result = $this->service()->byMonth([$warehouse->id], $this->clock());

        $this->assertSame('2026-03', $result['waste_started_month']);
        $this->assertSame('before', $this->row($result, '2026-02')['phase']);
        $this->assertSame('after', $this->row($result, '2026-03')['phase']);
    }

    public function test_start_before_the_window_marks_every_month_after(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $this->waste($warehouse, StockWaste::STATUS_RECORDED, '2024-01-10 10:00:00');

        $result = $this->service()->byMonth([$warehouse->id], $this->clock());

        $this->assertSame('2024-01', $result['waste_started_month']);
        foreach ($result['months'] as $row) {
            $this->assertSame('after', $row['phase']);
        }
    }

    public function test_other_outlets_data_is_never_included(): void
    {
        [$outletA, $warehouseA] = $this->outletWithWarehouse('OUT-A', 'WH-A');
        [$outletB, $warehouseB] = $this->outletWithWarehouse('OUT-B', 'WH-B');
        $productA = $this->product();
        $productB = $this->product();
        $this->attach($warehouseA, $productA, 1000);
        $this->attach($warehouseB, $productB, 1000);

        $this->finalizedOpname($warehouseA, '2026-05-10 10:00:00', [[$productA, -5, 1000]]);
        $this->finalizedOpname($warehouseB, '2026-05-10 10:00:00', [[$productB, -1, 1000]]);
        $this->waste($warehouseA, StockWaste::STATUS_RECORDED, '2026-04-10 10:00:00');

        $result = $this->service()->byMonth([$warehouseB->id], $this->clock());

        $this->assertSame(1, $this->row($result, '2026-05')['opnames']);
        $this->assertSame(1000, $this->row($result, '2026-05')['avg_value']);
        $this->assertNull($result['waste_started_month']);
    }

    public function test_variance_prop_requires_stock_opnames_access(): void
    {
        [$outlet] = $this->outletWithWarehouse();
        $user = User::factory()->create();
        $user->outlets()->attach($outlet->id, ['is_default' => true]);

        $this->actingAs($user)
            ->get(route('stock-opnames.index'))
            ->assertForbidden();
    }

    public function test_variance_is_shared_with_the_opname_index(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $user = $this->userWith(['stock-opnames-access'], $outlet);

        $this->actingAs($user)
            ->get(route('stock-opnames.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/StockOpnames/Index')
                ->has('variance.months', 12)
                ->has('variance.months.0.month')
                ->has('variance.waste_started_month'));
    }

    public function test_finalize_stores_the_unit_cost_in_the_audit_entry(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $product = $this->product(5000);
        $this->attach($warehouse, $product, 1000);
        $user = $this->userWith(['stock-opnames-finalize'], $outlet);
        $opname = $this->finalizedOpname($warehouse, null, [[$product, -3, null]], status: 'draft', physical: 7);

        $this->actingAs($user)
            ->post(route('stock-opnames.finalize', $opname))
            ->assertRedirect();

        $log = AuditLog::where('event', 'stock.opname.finalized')->where('auditable_id', $opname->id)->firstOrFail();
        $this->assertSame(1000.0, (float) $log->meta['items'][0]['unit_cost']);
    }

    public function test_value_uses_the_stored_cost_when_finalized(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $product = $this->product(5000);
        $this->attach($warehouse, $product, 1000);
        $user = $this->userWith(['stock-opnames-finalize'], $outlet);
        $opname = $this->finalizedOpname($warehouse, null, [[$product, -3, null]], status: 'draft', physical: 7);

        $this->actingAs($user)->post(route('stock-opnames.finalize', $opname))->assertRedirect();

        // The average cost changes after finalization; the variance keeps the cost stored at finalize.
        $warehouse->products()->updateExistingPivot($product->id, ['avg_cost' => 5000]);

        $row = $this->row($this->service()->byMonth([$warehouse->id], now()), now()->format('Y-m'));
        $this->assertSame(3000, $row['avg_value']);
        $this->assertFalse($row['estimated']);
    }

    public function test_value_falls_back_to_todays_average_cost_and_is_marked_estimated(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $product = $this->product(5000);
        $this->attach($warehouse, $product, 5000);

        // Finalized before finalize started storing a cost: no audit snapshot, so today's average is used.
        $this->finalizedOpname($warehouse, '2026-05-10 10:00:00', [[$product, -3, null]]);

        $row = $this->row($this->service()->byMonth([$warehouse->id], $this->clock()), '2026-05');
        $this->assertSame(15000, $row['avg_value']);
        $this->assertTrue($row['estimated']);
    }

    public function test_variance_fallback_uses_buy_price_when_no_average_cost(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        // No product_warehouse row and no central warehouse: InventoryCostService falls back to buy_price.
        $product = $this->product(2000);

        $this->finalizedOpname($warehouse, '2026-05-10 10:00:00', [[$product, -2, null]]);

        $row = $this->row($this->service()->byMonth([$warehouse->id], $this->clock()), '2026-05');
        $this->assertSame(4000, $row['avg_value']);
        $this->assertTrue($row['estimated']);
    }

    public function test_month_average_is_rounded_to_one_decimal(): void
    {
        [$outlet, $warehouse] = $this->outletWithWarehouse();
        $product = $this->product();
        $second = $this->product();
        $this->attach($warehouse, $product, 1000);
        $this->attach($warehouse, $second, 1000);

        // Two opnames: 1.000 and 2.000 -> 1.500; 1 and 2 items off -> 1.5.
        $this->finalizedOpname($warehouse, '2026-05-05 10:00:00', [[$product, -1, 1000]]);
        $this->finalizedOpname($warehouse, '2026-05-20 10:00:00', [[$product, -1, 1000], [$second, -1, 1000]]);

        $row = $this->row($this->service()->byMonth([$warehouse->id], $this->clock()), '2026-05');
        $this->assertSame(2, $row['opnames']);
        $this->assertSame(1500, $row['avg_value']);
        $this->assertSame(1.5, $row['avg_items_off']);
    }

    private function service(): StockOpnameVarianceService
    {
        return app(StockOpnameVarianceService::class);
    }

    private function clock(): Carbon
    {
        return Carbon::parse('2026-06-15 12:00:00');
    }

    private function row(array $variance, string $month): array
    {
        foreach ($variance['months'] as $row) {
            if ($row['month'] === $month) {
                return $row;
            }
        }

        $this->fail("Month {$month} is missing from the variance.");
    }

    private function outletWithWarehouse(string $outletCode = 'OUT-A', string $warehouseCode = 'WH-A'): array
    {
        $outlet = Outlet::create(['code' => $outletCode, 'name' => 'Outlet '.$outletCode, 'is_active' => true, 'is_sales_enabled' => true]);
        $warehouse = Warehouse::create([
            'outlet_id' => $outlet->id, 'code' => $warehouseCode, 'name' => 'Gudang '.$warehouseCode,
            'type' => 'branch', 'is_active' => true, 'sort_order' => 0,
        ]);

        return [$outlet, $warehouse];
    }

    private function userWith(array $permissions, Outlet $outlet): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $user->outlets()->attach($outlet->id, ['is_default' => true]);

        return $user;
    }

    private function product(int $buyPrice = 1000): Product
    {
        $category = Category::create([
            'name' => 'Kategori '.Str::upper(Str::random(5)),
            'description' => 'Kategori pengujian',
            'image' => 'category.png',
        ]);

        return Product::create([
            'category_id' => $category->id, 'image' => 'product.png',
            'barcode' => 'BRCD-'.Str::upper(Str::random(10)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => 'Produk Uji '.Str::upper(Str::random(4)),
            'description' => 'Deskripsi produk uji.',
            'buy_price' => $buyPrice, 'sell_price' => $buyPrice + 1000, 'stock' => 100, 'tax_rate' => 0,
        ]);
    }

    private function attach(Warehouse $warehouse, Product $product, ?int $avgCost): void
    {
        $warehouse->products()->attach($product->id, ['stock' => 100, 'avg_cost' => $avgCost]);
    }

    /**
     * A finalized opname. Each line is [Product, difference, ?stored unit cost]. A stored cost is written
     * to the finalize audit entry, the way StockOpnameController::finalize does it.
     *
     * @param  list<array{0: Product, 1: int, 2: int|null}>  $lines
     */
    private function finalizedOpname(Warehouse $warehouse, ?string $finalizedAt, array $lines, string $status = 'finalized', ?int $physical = null): StockOpname
    {
        $opname = StockOpname::create([
            'code' => 'SO-'.Str::upper(Str::random(10)),
            'warehouse_id' => $warehouse->id,
            'status' => $status,
            'finalized_at' => $finalizedAt,
        ]);

        $metaItems = [];
        foreach ($lines as $line) {
            [$product, $difference] = $line;
            $unitCost = $line[2] ?? null;
            StockOpnameItem::create([
                'stock_opname_id' => $opname->id,
                'product_id' => $product->id,
                'system_stock' => 10,
                'physical_stock' => $physical ?? 10 + $difference,
                'difference' => $difference,
                'adjustment_reason' => $difference !== 0 ? 'Barang rusak' : null,
            ]);

            if ($unitCost !== null) {
                $metaItems[] = ['product_id' => $product->id, 'unit_cost' => $unitCost];
            }
        }

        if ($metaItems !== []) {
            app(AuditLogService::class)->log(
                event: 'stock.opname.finalized',
                module: 'stock',
                auditable: $opname,
                description: 'Stock opname difinalisasi.',
                meta: ['items' => $metaItems],
            );
        }

        return $opname;
    }

    private function waste(Warehouse $warehouse, string $status, string $recordedAt, ?string $cancelledAt = null): StockWaste
    {
        return StockWaste::create([
            'warehouse_id' => $warehouse->id,
            'status' => $status,
            'reason' => 'expired',
            'total_value' => 0,
            'recorded_at' => $recordedAt,
            'cancelled_at' => $cancelledAt,
        ]);
    }
}
