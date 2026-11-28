<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockWaste;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockWasteReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
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
        $this->line($this->waste(1000, StockWaste::STATUS_RECORDED, self::DAY.' 23:30:00'), $beras, 5, [[$beras, 5, 1000]]);
        $this->line($this->waste(2000, StockWaste::STATUS_RECORDED, '2026-05-11 00:10:00'), $beras, 5, [[$beras, 5, 2000]]);

        $this->assertSame(1000, $this->page()['total_value']);
        $this->assertSame(2000, $this->page(['start_date' => '2026-05-11', 'end_date' => '2026-05-11'])['total_value']);
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

    private function waste(int $value, string $status, string $recordedAt, ?string $cancelledAt = null, ?int $warehouseId = null): StockWaste
    {
        return StockWaste::create([
            'warehouse_id' => $warehouseId ?? $this->warehouse->id,
            'status' => $status,
            'reason' => 'expired',
            'total_value' => $value,
            'recorded_at' => $recordedAt,
            'cancelled_at' => $cancelledAt,
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
