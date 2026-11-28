<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\Setting;
use App\Models\StockMutation;
use App\Models\StockWaste;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockWasteReportService;
use App\Services\StockWasteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// REQ-011 AC-11: waste per outlet and period, per item and per reason, cancellations subtracted.
class WasteReportTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-05-10';

    private Outlet $outlet;

    private Warehouse $warehouse;

    private User $user;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Permission::firstOrCreate(['name' => 'profits-access', 'guard_name' => 'web']);
        $this->outlet = Outlet::create(['code' => 'OUT-A', 'name' => 'Outlet A', 'is_active' => true]);
        $this->warehouse = $this->warehouse($this->outlet, 'WH-A1');
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('profits-access');
        $this->user->outlets()->attach($this->outlet->id, ['is_default' => true]);
        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
    }

    public function test_items_are_per_product_and_a_menu_is_the_menu(): void
    {
        $menu = $this->product('Menu', isComposite: true);
        $bean = $this->product('Beras');
        $cup = $this->product('Cup');
        $waste = $this->waste(6200, StockWaste::STATUS_RECORDED, self::DAY);
        // The user typed 2 menus; the value is the recipe ingredients' cost from the moves.
        $this->line($waste, $menu, 2, [[$bean, 36, 4500], [$cup, 2, 1700]]);

        $props = $this->page();

        $this->assertSame([
            'product_id' => $menu->id, 'title' => 'Menu', 'is_composite' => true, 'qty' => 2, 'value' => 6200,
        ], $props['items'][0]);
        $this->assertSame(6200, $props['total_value']);
    }

    public function test_reasons_count_and_value_subtract_cancellations(): void
    {
        $beras = $this->product('Beras');
        foreach ([5, 5] as $qty) {
            $this->line($this->waste(1000, StockWaste::STATUS_RECORDED, self::DAY), $beras, $qty, [[$beras, $qty, 1000]]);
        }
        // Recorded the day before, cancelled in the reported period: it subtracts here.
        $cancelled = $this->waste(400, StockWaste::STATUS_CANCELLED, '2026-05-09', self::DAY);
        $this->line($cancelled, $beras, 2, [[$beras, 2, 400]]);

        $props = $this->page();

        $this->assertSame([['reason' => 'expired', 'records' => 1, 'value' => 1600]], $props['reasons']);
        $this->assertSame(['product_id' => $beras->id, 'title' => 'Beras', 'is_composite' => false, 'qty' => 8, 'value' => 1600], $props['items'][0]);
        $this->assertSame($this->service()->costTotal([$this->warehouse->id], null, self::DAY, self::DAY), $props['total_value']);
        $this->assertSame(1600, $props['total_value']);
    }

    public function test_items_netting_to_zero_are_not_listed(): void
    {
        $beras = $this->product('Beras');
        $this->line($this->waste(1000, StockWaste::STATUS_RECORDED, self::DAY), $beras, 5, [[$beras, 5, 1000]]);
        $cancelled = $this->waste(1000, StockWaste::STATUS_CANCELLED, '2026-05-09', self::DAY);
        $this->line($cancelled, $beras, 5, [[$beras, 5, 1000]]);

        $props = $this->page();

        $this->assertSame([], $props['items']);
        $this->assertSame([], $props['reasons']);
        $this->assertSame(0, $props['total_value']);
    }

    public function test_period_boundary_uses_the_reported_dates(): void
    {
        $beras = $this->product('Beras');
        $this->line($this->waste(1000, StockWaste::STATUS_RECORDED, self::DAY.' 23:30:00', null, null, $this->user->id), $beras, 5, [[$beras, 5, 1000]]);
        $this->line($this->waste(2000, StockWaste::STATUS_RECORDED, '2026-05-11 00:10:00', null, null, $this->user->id), $beras, 5, [[$beras, 5, 2000]]);

        $first = $this->page();
        $second = $this->page(['start_date' => '2026-05-11', 'end_date' => '2026-05-11']);

        $this->assertSame(1000, $first['total_value']);
        $this->assertSame(2000, $second['total_value']);
        $this->assertSame([self::DAY], array_column($first['recorder_days'], 'date'));
        $this->assertSame(['2026-05-11'], array_column($second['recorder_days'], 'date'));
    }

    public function test_rows_are_per_recorder_and_per_day(): void
    {
        $other = User::factory()->create(['name' => 'Budi']);
        $this->waste(1000, StockWaste::STATUS_RECORDED, self::DAY, null, null, $this->user->id);
        $this->waste(2000, StockWaste::STATUS_RECORDED, self::DAY, null, null, $other->id);
        $this->waste(3000, StockWaste::STATUS_RECORDED, '2026-05-11', null, null, $this->user->id);

        $this->assertSame([
            ['date' => '2026-05-11', 'user_id' => $this->user->id, 'user_name' => $this->user->name, 'records' => 1, 'value' => 3000, 'near_limit' => 0],
            ['date' => self::DAY, 'user_id' => $other->id, 'user_name' => 'Budi', 'records' => 1, 'value' => 2000, 'near_limit' => 0],
            ['date' => self::DAY, 'user_id' => $this->user->id, 'user_name' => $this->user->name, 'records' => 1, 'value' => 1000, 'near_limit' => 0],
        ], $this->page()['recorder_days']);
    }

    public function test_a_recorder_splitting_waste_shows_one_row_per_day(): void
    {
        foreach ([95000, 95000, 50000] as $value) {
            $this->waste($value, StockWaste::STATUS_RECORDED, self::DAY, null, null, $this->user->id);
        }

        $rows = $this->page()['recorder_days'];

        $this->assertCount(1, $rows);
        $this->assertSame(3, $rows[0]['records']);
        $this->assertSame(240000, $rows[0]['value']);
        $this->assertSame(2, $rows[0]['near_limit']);
    }

    public function test_near_limit_is_inclusive_at_the_limit_and_at_ninety_percent(): void
    {
        foreach ([100000, 90000, 100001, 89999] as $value) {
            $this->waste($value, StockWaste::STATUS_RECORDED, self::DAY, null, null, $this->user->id);
        }

        $row = $this->page()['recorder_days'][0];

        $this->assertSame(4, $row['records']);
        $this->assertSame(380000, $row['value']);
        // At the limit and at 90% count; limit+1 and 90%-1 do not.
        $this->assertSame(2, $row['near_limit']);
    }

    public function test_near_limit_uses_the_current_outlet_limit(): void
    {
        foreach ([45000, 46000, 95000] as $value) {
            $this->waste($value, StockWaste::STATUS_RECORDED, self::DAY, null, null, $this->user->id);
        }
        Setting::setForOutlet(StockWasteService::LIMIT_KEY, 50000, $this->outlet);

        $props = $this->page();

        $this->assertSame(50000, $props['approval_limit']);
        // At the new 50.000 limit 45.000 and 46.000 are near and 95.000 is not; the old 100.000 limit
        // would have counted only 95.000.
        $this->assertSame(2, $props['recorder_days'][0]['near_limit']);
    }

    public function test_pending_and_rejected_never_appear(): void
    {
        $this->waste(1000, StockWaste::STATUS_PENDING, self::DAY, null, null, $this->user->id);
        $this->waste(2000, StockWaste::STATUS_REJECTED, self::DAY, null, null, $this->user->id);

        $this->assertSame([], $this->page()['recorder_days']);
    }

    public function test_a_cancellation_subtracts_on_its_own_day_for_the_original_recorder(): void
    {
        $this->waste(2000, StockWaste::STATUS_RECORDED, self::DAY, null, null, $this->user->id);
        // Recorded the day before, cancelled on the reported day: it subtracts here, under its recorder.
        $this->waste(1000, StockWaste::STATUS_CANCELLED, '2026-05-09', self::DAY, null, $this->user->id);

        $rows = $this->page()['recorder_days'];

        $this->assertCount(1, $rows);
        $this->assertSame(self::DAY, $rows[0]['date']);
        $this->assertSame(0, $rows[0]['records']);
        $this->assertSame(1000, $rows[0]['value']);

        // Its own day still shows it, because it was recorded then.
        $ownDay = $this->page(['start_date' => '2026-05-09', 'end_date' => '2026-05-09'])['recorder_days'][0];
        $this->assertSame('2026-05-09', $ownDay['date']);
        $this->assertSame(1, $ownDay['records']);
        $this->assertSame(1000, $ownDay['value']);
    }

    public function test_a_row_netting_to_zero_is_dropped(): void
    {
        $this->waste(1000, StockWaste::STATUS_CANCELLED, self::DAY, self::DAY, null, $this->user->id);

        $this->assertSame([], $this->page()['recorder_days']);
    }

    public function test_a_deleted_recorder_is_one_unnamed_row(): void
    {
        $this->waste(4000, StockWaste::STATUS_RECORDED, self::DAY);

        $this->assertSame([
            ['date' => self::DAY, 'user_id' => null, 'user_name' => null, 'records' => 1, 'value' => 4000, 'near_limit' => 0],
        ], $this->page()['recorder_days']);
    }

    public function test_recorder_days_and_sales_cost_never_cross_outlets(): void
    {
        $other = Outlet::create(['code' => 'OUT-B', 'name' => 'Outlet B', 'is_active' => true]);
        $otherWarehouse = $this->warehouse($other, 'WH-B1');
        $otherRecorder = User::factory()->create(['name' => 'Outlet B Staff']);
        $this->waste(9999, StockWaste::STATUS_RECORDED, self::DAY, null, $otherWarehouse->id, $otherRecorder->id);
        $this->sale(77777, self::DAY, 1, $otherWarehouse->id);
        $this->waste(5000, StockWaste::STATUS_RECORDED, self::DAY, null, null, $this->user->id);
        $this->sale(30000, self::DAY);

        $props = $this->page();

        $this->assertSame(30000, $props['sales_cost']);
        $this->assertSame([$this->user->name], array_column($props['recorder_days'], 'user_name'));
        $this->assertSame(5000, $props['recorder_days'][0]['value']);
        $this->assertSame(30000, $this->service()->salesCost([$this->warehouse->id], self::DAY, self::DAY));
    }

    public function test_waste_percent_is_null_without_sales(): void
    {
        $this->waste(5000, StockWaste::STATUS_RECORDED, self::DAY);

        $props = $this->page();

        $this->assertSame(0, $props['sales_cost']);
        $this->assertNull($props['waste_percent']);
    }

    /** @return array<string, array{int, int, float}> */
    public static function percentCases(): array
    {
        return [
            'over 100' => [150000, 100000, 150.0],
            'rounds down' => [10000, 30000, 33.3],
            'rounds up' => [20000, 30000, 66.7],
        ];
    }

    #[DataProvider('percentCases')]
    public function test_waste_percent_rounds_to_one_decimal(int $waste, int $salesCost, float $expected): void
    {
        $this->waste($waste, StockWaste::STATUS_RECORDED, self::DAY);
        $this->sale($salesCost, self::DAY);

        $props = $this->page();

        $this->assertSame($salesCost, $props['sales_cost']);
        // The page props are JSON: a whole result (150.0) comes back as an int, so compare as floats.
        $this->assertSame($expected, (float) $props['waste_percent']);
    }

    public function test_sales_cost_is_net_of_completed_returns(): void
    {
        $sale = $this->sale(100000, self::DAY, 2);
        $detail = $sale->details()->firstOrFail();
        $this->salesReturn($sale, $detail, 1, 'completed', restock: true);
        // A draft return is not reversed yet.
        $this->salesReturn($sale, $detail, 1, 'draft', restock: true);

        $this->assertSame(50000, $this->service()->salesCost([$this->warehouse->id], self::DAY, self::DAY));
    }

    public function test_sales_cost_keeps_the_cost_of_a_non_restocked_return(): void
    {
        // A cooked-food return keeps its cost (#551 BR-1): only restocked goods leave the cost.
        $sale = $this->sale(100000, self::DAY);
        $detail = $sale->details()->firstOrFail();
        $this->salesReturn($sale, $detail, 1, 'completed', restock: false);

        $this->assertSame(100000, $this->service()->salesCost([$this->warehouse->id], self::DAY, self::DAY));
    }

    public function test_sales_cost_excludes_a_sale_cancelled_for_good(): void
    {
        $this->sale(100000, self::DAY, 1, null, cancelledForGood: true);
        $this->sale(30000, self::DAY);

        $this->assertSame(30000, $this->service()->salesCost([$this->warehouse->id], self::DAY, self::DAY));
    }

    public function test_a_line_without_frozen_cost_counts_zero(): void
    {
        $this->sale(null, self::DAY);
        $this->sale(30000, self::DAY);

        $this->assertSame(30000, $this->service()->salesCost([$this->warehouse->id], self::DAY, self::DAY));
    }

    public function test_outlet_filter_covers_a_deactivated_warehouse(): void
    {
        $closed = $this->warehouse($this->outlet, 'WH-A2');
        $beras = $this->product('Beras');
        $this->line($this->waste(1000, StockWaste::STATUS_RECORDED, self::DAY), $beras, 5, [[$beras, 5, 1000]]);
        $this->line($this->waste(2000, StockWaste::STATUS_RECORDED, self::DAY, null, $closed->id), $beras, 5, [[$beras, 5, 2000]]);
        $closed->update(['is_active' => false]);

        $props = $this->page();

        $this->assertCount(1, $props['outlets']);
        $this->assertSame(3000, $props['total_value']);
    }

    public function test_default_period_is_the_current_month_in_the_app_timezone(): void
    {
        $this->useJakarta();
        // 20:00 UTC on 30 November is already 1 December 03:00 in Jakarta.
        Carbon::setTestNow(Carbon::parse('2026-11-30 20:00:00', 'UTC'));
        $beras = $this->product('Beras');
        $this->line($this->waste(900, StockWaste::STATUS_RECORDED, '2026-12-02 10:00:00'), $beras, 3, [[$beras, 3, 900]]);
        $this->line($this->waste(700, StockWaste::STATUS_RECORDED, '2026-11-20 10:00:00'), $beras, 3, [[$beras, 3, 700]]);

        $props = $this->fetch([]);

        $this->assertSame('2026-12-01', $props['filters']['start_date']);
        $this->assertSame('2026-12-31', $props['filters']['end_date']);
        $this->assertSame(900, $props['total_value']);
    }

    public function test_outlet_outside_the_users_outlets_is_refused(): void
    {
        $other = Outlet::create(['code' => 'OUT-B', 'name' => 'Outlet B', 'is_active' => true]);

        $this->actingAs($this->user)
            ->getJson(route('reports.waste.index', ['outlet_id' => $other->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('outlet_id');
    }

    public function test_an_end_date_before_the_start_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->getJson(route('reports.waste.index', ['start_date' => '2026-05-20', 'end_date' => '2026-05-10']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }

    public function test_waste_report_requires_profits_access(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('reports.waste.index'))
            ->assertForbidden();
    }

    /** Wall-clock times are stored in the app timezone: make both the config and the PHP default Jakarta (UTC+7). */
    private function useJakarta(): void
    {
        config(['app.timezone' => 'Asia/Jakarta']);
        date_default_timezone_set('Asia/Jakarta');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set('UTC');

        parent::tearDown();
    }

    private function service(): StockWasteReportService
    {
        return app(StockWasteReportService::class);
    }

    private function page(array $query = []): array
    {
        return $this->fetch($query + ['start_date' => self::DAY, 'end_date' => self::DAY]);
    }

    /** The report as the page receives it. */
    private function fetch(array $query): array
    {
        $props = [];
        $this->actingAs($this->user)
            ->get(route('reports.waste.index', $query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /** @param  array<int, array{0: Product, 1: int, 2: int}>  $moves  stock product, qty, value */
    private function line(StockWaste $waste, Product $product, int $qty, array $moves = []): void
    {
        $line = $waste->lines()->create(['product_id' => $product->id, 'qty' => $qty]);
        foreach ($moves as [$moveProduct, $moveQty, $value]) {
            $waste->moves()->create([
                'line_id' => $line->id,
                'product_id' => $moveProduct->id,
                'qty' => $moveQty,
                'unit_cost' => $value / max(1, $moveQty),
                'value' => $value,
            ]);
        }
    }

    private function waste(int $value, string $status, string $recordedAt, ?string $cancelledAt = null, ?int $warehouseId = null, ?int $createdBy = null): StockWaste
    {
        return StockWaste::create([
            'warehouse_id' => $warehouseId ?? $this->warehouse->id,
            'status' => $status,
            'reason' => 'expired',
            'total_value' => $value,
            'recorded_at' => $recordedAt,
            'cancelled_at' => $cancelledAt,
            'created_by' => $createdBy,
        ]);
    }

    /** A paid sale in the outlet's warehouse with one frozen-cost line; a null cost stands for an old line. */
    private function sale(?int $costTotal, string $at, int $qty = 1, ?int $warehouseId = null, bool $cancelledForGood = false): Transaction
    {
        $product = $this->product('Sale '.uniqid());
        $transaction = Transaction::create([
            'cashier_id' => $this->user->id, 'warehouse_id' => $warehouseId ?? $this->warehouse->id, 'invoice' => 'INV-'.uniqid(),
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => (int) $costTotal * 2,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);
        $transaction->forceFill(['created_at' => $at.' 10:00:00', 'updated_at' => $at.' 10:00:00'])->save();
        $transaction->details()->create([
            'product_id' => $product->id, 'conversion_factor' => 1, 'qty' => $qty,
            'base_unit_price' => (int) $costTotal, 'unit_price' => (int) $costTotal,
            'price' => (int) $costTotal * $qty, 'discount_total' => 0, 'cost_total' => $costTotal,
        ]);
        if ($cancelledForGood) {
            StockMutation::create([
                'product_id' => $product->id, 'warehouse_id' => $transaction->warehouse_id,
                'reference_type' => 'transaction_expire', 'reference_id' => $transaction->id,
                'mutation_type' => 'in', 'qty' => $qty, 'stock_before' => 0, 'stock_after' => $qty,
            ]);
        }

        return $transaction;
    }

    private function salesReturn(Transaction $sale, TransactionDetail $detail, int $qtyReturn, string $status, bool $restock): void
    {
        $return = SalesReturn::create([
            'code' => 'RET-'.uniqid(), 'transaction_id' => $sale->id, 'status' => $status, 'return_type' => 'refund_cash',
        ]);
        $return->items()->create([
            'transaction_detail_id' => $detail->id, 'product_id' => $detail->product_id,
            'qty_sold' => (int) $detail->qty, 'qty_return' => $qtyReturn,
            'unit_price' => (int) $detail->unit_price, 'subtotal' => (int) $detail->unit_price * $qtyReturn,
            'return_reason' => 'damaged', 'restock_to_inventory' => $restock,
        ]);
    }

    private function warehouse(Outlet $outlet, string $code): Warehouse
    {
        return Warehouse::create([
            'outlet_id' => $outlet->id, 'code' => $code, 'name' => $code, 'type' => 'branch', 'is_active' => true, 'sort_order' => 0,
        ]);
    }

    private function product(string $title, bool $isComposite = false): Product
    {
        return Product::create([
            'title' => $title, 'sku' => 'SKU-'.uniqid(), 'barcode' => 'BC-'.uniqid(), 'buy_price' => 1000, 'sell_price' => 2000,
            'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $this->category->id, 'is_composite' => $isComposite,
        ]);
    }
}
