<?php

namespace Tests\Feature\Reports;

use App\Models\Outlet;
use App\Models\Profit;
use App\Models\StockWaste;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockWasteReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

// REQ-011 AC-10: waste cost on the profit report, in its own period, cancellations subtracted.
class ProfitReportWasteTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-05-10';

    private Outlet $outlet;

    private Warehouse $warehouse;

    private User $user;

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
    }

    public function test_recorded_waste_is_counted_in_its_period(): void
    {
        $this->waste(2000, StockWaste::STATUS_RECORDED, self::DAY);

        $this->assertSame(2000, $this->cost(self::DAY, self::DAY));
        $this->assertSame(0, $this->cost('2026-05-11', '2026-05-11'));
    }

    public function test_cancelled_in_the_same_period_nets_to_zero(): void
    {
        $this->waste(2000, StockWaste::STATUS_CANCELLED, self::DAY, self::DAY);

        $this->assertSame(0, $this->cost(self::DAY, self::DAY));
    }

    public function test_cancellation_subtracts_in_its_own_period(): void
    {
        $this->waste(2000, StockWaste::STATUS_CANCELLED, '2026-05-09', self::DAY);

        $this->assertSame(2000, $this->cost('2026-05-09', '2026-05-09'));
        $this->assertSame(-2000, $this->cost(self::DAY, self::DAY));
    }

    public function test_pending_and_rejected_are_never_counted(): void
    {
        $this->waste(5000, StockWaste::STATUS_PENDING, self::DAY);
        $this->waste(5000, StockWaste::STATUS_REJECTED, self::DAY);

        $this->assertSame(0, $this->cost(self::DAY, self::DAY));
    }

    public function test_without_dates_the_whole_history_counts(): void
    {
        $this->waste(2000, StockWaste::STATUS_RECORDED, '2025-01-01');

        $this->assertSame(2000, $this->cost(null, null));
    }

    public function test_no_waste_leaves_profit_unchanged(): void
    {
        $this->sale(15000, 40000, self::DAY);

        $summary = $this->page()['summary'];

        $this->assertSame(0, $summary['waste_cost_total']);
        $this->assertSame(15000, $summary['profit_total']);
        $this->assertSame(15000, $summary['profit_after_waste']);
    }

    public function test_profit_after_waste_can_be_negative(): void
    {
        $this->sale(1000, 40000, self::DAY);
        $this->waste(3000, StockWaste::STATUS_RECORDED, self::DAY);

        $summary = $this->page()['summary'];

        $this->assertSame(3000, $summary['waste_cost_total']);
        $this->assertSame(-2000, $summary['profit_after_waste']);
    }

    public function test_waste_does_not_change_sales_profit_or_margin(): void
    {
        $this->sale(18000, 35000, self::DAY);
        $before = $this->page()['summary'];
        $this->waste(3000, StockWaste::STATUS_RECORDED, self::DAY);
        $after = $this->page()['summary'];

        $this->assertSame($before['profit_total'], $after['profit_total']);
        $this->assertSame($before['margin'], $after['margin']);
        $this->assertSame(3000, $after['waste_cost_total']);
        $this->assertSame(15000, $after['profit_after_waste']);
    }

    /** @return array<string, array{array<string, string>}> */
    public static function hiddenFilters(): array
    {
        return [
            'invoice' => [['invoice' => 'INV-1']],
            'cashier' => [['cashier_id' => '1']],
            'customer' => [['customer_id' => '1']],
        ];
    }

    #[DataProvider('hiddenFilters')]
    public function test_waste_line_is_hidden_when_invoice_cashier_or_customer_filter_is_set(array $query): void
    {
        $this->sale(15000, 40000, self::DAY);
        $this->waste(2000, StockWaste::STATUS_RECORDED, self::DAY);

        $summary = $this->page($query)['summary'];

        $this->assertNull($summary['waste_cost_total']);
        $this->assertNull($summary['profit_after_waste']);
    }

    public function test_waste_is_limited_to_the_users_outlet_and_warehouse_filter(): void
    {
        $other = Outlet::create(['code' => 'OUT-B', 'name' => 'Outlet B', 'is_active' => true]);
        $otherWarehouse = $this->warehouse($other, 'WH-B1');
        $this->user->outlets()->attach($other->id);
        $this->waste(2000, StockWaste::STATUS_RECORDED, self::DAY);
        $this->waste(5000, StockWaste::STATUS_RECORDED, self::DAY, null, $otherWarehouse->id);

        $service = $this->service();
        $this->assertSame(2000, $service->costTotal([$this->warehouse->id], null, self::DAY, self::DAY));
        $this->assertSame(5000, $service->costTotal([$otherWarehouse->id], null, self::DAY, self::DAY));
        $this->assertSame(5000, $service->costTotal([$this->warehouse->id, $otherWarehouse->id], $otherWarehouse->id, self::DAY, self::DAY));
        // The page follows its active outlet (the default one), so the other outlet's waste stays out.
        $this->assertSame(2000, $this->page()['summary']['waste_cost_total']);
    }

    private function service(): StockWasteReportService
    {
        return app(StockWasteReportService::class);
    }

    private function cost(?string $start, ?string $end): int
    {
        return $this->service()->costTotal([$this->warehouse->id], null, $start, $end);
    }

    /** The report as the page receives it. */
    private function page(array $query = []): array
    {
        $query += ['start_date' => self::DAY, 'end_date' => self::DAY];
        $props = [];
        $this->actingAs($this->user)
            ->get(route('reports.profits.index', $query))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    private function sale(int $profit, int $revenue, string $at): Transaction
    {
        $transaction = Transaction::create([
            'cashier_id' => $this->user->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => 'INV-'.uniqid(),
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => $revenue,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);
        $transaction->forceFill(['created_at' => $at.' 10:00:00'])->save();
        Profit::create(['transaction_id' => $transaction->id, 'total' => $profit]);

        return $transaction;
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
}
