<?php

namespace Tests\Feature\CashierShifts;

use App\Models\AuditLog;
use App\Models\CashierShift;
use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\ForceCloseSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Feature\DineIn\Concerns\MakesDineBills;
use Tests\TestCase;

/** REQ-003 AC-13 (#564): the monthly count of shifts force-closed over a hold, per outlet. */
class ForceCloseSummaryTest extends TestCase
{
    use MakesDineBills;
    use RefreshDatabase;

    private Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->setUpDineBill();
        foreach (['cashier-shifts-access', 'cashier-shifts-force-close'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $this->cashier->givePermissionTo('cashier-shifts-access');
        $this->month = Carbon::create(2026, 5, 1, 0, 0, 0, config('app.timezone'));
    }

    public function test_empty_month_has_zero_counts(): void
    {
        $summary = $this->summary();

        $this->assertSame('2026-05', $summary['month']);
        $this->assertSame(0, $summary['closed']);
        $this->assertSame(0, $summary['force_closed']);
        $this->assertSame(0, $summary['held']);
        $this->assertSame([], $summary['held_shifts']);
    }

    public function test_month_boundary_follows_the_app_timezone(): void
    {
        $timezone = config('app.timezone');
        $this->closedShift(['closed_at' => Carbon::create(2026, 4, 30, 23, 59, 59, $timezone)]);
        $this->closedShift(['closed_at' => Carbon::create(2026, 5, 1, 0, 0, 0, $timezone)]);

        $summary = $this->summary();

        $this->assertSame(1, $summary['closed']);
        $this->assertSame(0, $summary['held']);
    }

    public function test_a_shift_counts_in_the_month_it_closed(): void
    {
        // Opened in April, closed in May: the close date decides the month.
        $this->closedShift([
            'opened_at' => Carbon::create(2026, 4, 29, 9, 0, 0, config('app.timezone')),
            'closed_at' => Carbon::create(2026, 5, 2, 21, 0, 0, config('app.timezone')),
        ]);

        $this->assertSame(1, $this->summary()['closed']);
    }

    public function test_open_shift_is_not_counted(): void
    {
        $this->closedShift(['status' => CashierShift::STATUS_OPEN, 'closed_at' => null]);

        $summary = $this->summary();

        $this->assertSame(0, $summary['closed']);
        $this->assertSame(0, $summary['force_closed']);
    }

    public function test_plain_close_counts_as_closed_only(): void
    {
        $this->closedShift();

        $summary = $this->summary();

        $this->assertSame(1, $summary['closed']);
        $this->assertSame(0, $summary['force_closed']);
        $this->assertSame(0, $summary['held']);
        $this->assertSame([], $summary['held_shifts']);
    }

    public function test_force_close_without_hold_is_not_over_a_hold(): void
    {
        $this->closedShift(['status' => CashierShift::STATUS_FORCE_CLOSED, 'close_notes' => 'Tutup paksa']);

        $summary = $this->summary();

        $this->assertSame(1, $summary['closed']);
        $this->assertSame(1, $summary['force_closed']);
        $this->assertSame(0, $summary['held']);
        $this->assertSame([], $summary['held_shifts']);
    }

    public function test_force_close_over_a_hold_is_listed(): void
    {
        $shift = $this->closedShift([
            'status' => CashierShift::STATUS_FORCE_CLOSED,
            'close_notes' => 'Tamu sudah pulang',
        ]);
        $this->audit($shift);

        $summary = $this->summary();

        $this->assertSame(1, $summary['closed']);
        $this->assertSame(1, $summary['force_closed']);
        $this->assertSame(1, $summary['held']);
        $this->assertSame([[
            'id' => $shift->id,
            'cashier' => $this->cashier->name,
            'closed_at' => $shift->closed_at->toISOString(),
            'close_notes' => 'Tamu sudah pulang',
        ]], $summary['held_shifts']);
    }

    public function test_over_a_hold_ignores_the_audit_meta(): void
    {
        $shift = $this->closedShift(['status' => CashierShift::STATUS_FORCE_CLOSED, 'close_notes' => 'Tamu pulang']);
        $this->audit($shift, ['note' => 'Tamu pulang', 'pending_discounts' => [['id' => 9]]]);

        $this->assertSame(1, $this->summary()['held']);
    }

    public function test_shift_without_a_warehouse_is_in_no_outlet(): void
    {
        $this->closedShift(['warehouse_id' => null]);

        $summary = $this->summary();

        $this->assertSame(0, $summary['closed']);
        $this->assertSame(0, $summary['held']);
    }

    public function test_a_forced_close_through_the_service_is_counted_over_a_hold(): void
    {
        $shift = app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);
        $this->unpaidBill($shift);
        $this->travelTo($this->month->copy()->addDays(2));

        app(CashierShiftService::class)->closeShift($shift, $this->cashier, 100000, 'Tamu pulang', forceClose: true, overrideHold: true);

        $summary = $this->summary();

        $this->assertSame(1, $summary['closed']);
        $this->assertSame(1, $summary['force_closed']);
        $this->assertSame(1, $summary['held']);
        $this->assertSame($shift->id, $summary['held_shifts'][0]['id']);
    }

    public function test_outlet_the_user_cannot_access_is_refused(): void
    {
        [$outletA, $outletB] = $this->twoOutlets();
        $manager = $this->managerFor([$outletA]);

        $this->actingAs($manager)
            ->get(route('cashier-shifts.index', ['summary_outlet_id' => $outletB->id, 'summary_month' => '2026-05']))
            ->assertSessionHasErrors('summary_outlet_id');
    }

    public function test_a_summary_month_taken_on_the_last_day_keeps_its_own_month(): void
    {
        // On the 31st a bare 'Y-m' parse kept day 31, overflowing February into March (#564).
        $this->travelTo(Carbon::create(2026, 3, 31, 10, 0, 0, config('app.timezone')));
        $this->closedShift(['closed_at' => Carbon::create(2026, 2, 15, 12, 0, 0, config('app.timezone'))]);

        $this->actingAs($this->cashier)
            ->get(route('cashier-shifts.index', ['summary_month' => '2026-02']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('forceCloseSummary.month', '2026-02')
                ->where('forceCloseSummary.closed', 1));
    }

    public function test_bad_summary_month_is_refused(): void
    {
        $this->actingAs($this->cashier)
            ->get(route('cashier-shifts.index', ['summary_month' => '2026-13']))
            ->assertSessionHasErrors('summary_month');
    }

    public function test_other_outlet_is_never_counted(): void
    {
        [$outletA, $outletB] = $this->twoOutlets();
        $warehouseA = $this->warehouseIn($outletA);
        $warehouseB = $this->warehouseIn($outletB);
        $manager = $this->managerFor([$outletA]);
        $this->closedShift(['user_id' => $this->cashier->id, 'warehouse_id' => $warehouseA->id]);
        $this->closedShift(['user_id' => $manager->id, 'warehouse_id' => $warehouseA->id]);
        $this->closedShift(['user_id' => $manager->id, 'warehouse_id' => $warehouseB->id]);

        $this->actingAs($manager)
            ->get(route('cashier-shifts.index', ['summary_outlet_id' => $outletA->id, 'summary_month' => '2026-05']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('forceCloseSummary.closed', 2)
                ->where('summaryFilters.outlet_id', $outletA->id));
    }

    public function test_a_cashier_counts_only_their_own_shifts(): void
    {
        [$outletA] = $this->twoOutlets();
        $warehouseA = $this->warehouseIn($outletA);
        $other = $this->cashierWithOutlets([$outletA], ['cashier-shifts-access']);
        $this->cashier->outlets()->attach($outletA->id, ['is_default' => true]);
        $this->closedShift(['user_id' => $this->cashier->id, 'warehouse_id' => $warehouseA->id]);
        $this->closedShift(['user_id' => $other->id, 'warehouse_id' => $warehouseA->id]);

        $this->actingAs($this->cashier)
            ->get(route('cashier-shifts.index', ['summary_outlet_id' => $outletA->id, 'summary_month' => '2026-05']))
            ->assertInertia(fn (Assert $page) => $page->where('forceCloseSummary.closed', 1));
    }

    public function test_manager_sees_the_chosen_outlet(): void
    {
        [$outletA, $outletB] = $this->twoOutlets();
        $manager = $this->managerFor([$outletA, $outletB]);
        $warehouseA = $this->warehouseIn($outletA);
        $warehouseB = $this->warehouseIn($outletB);
        $this->closedShift(['user_id' => $manager->id, 'warehouse_id' => $warehouseA->id]);
        $this->closedShift(['user_id' => $manager->id, 'warehouse_id' => $warehouseB->id]);
        $this->closedShift(['user_id' => $manager->id, 'warehouse_id' => $warehouseB->id]);

        $this->actingAs($manager)
            ->get(route('cashier-shifts.index', ['summary_outlet_id' => $outletA->id, 'summary_month' => '2026-05']))
            ->assertInertia(fn (Assert $page) => $page->where('forceCloseSummary.closed', 1));
    }

    /** @param  array<string, mixed>  $attributes */
    private function closedShift(array $attributes = []): CashierShift
    {
        return CashierShift::create([
            'user_id' => $this->cashier->id,
            'opened_by' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'opened_at' => $this->month->copy()->subDay(),
            'closed_at' => $this->month->copy()->addDays(5),
            'status' => CashierShift::STATUS_CLOSED,
            'opening_cash' => 100000,
            ...$attributes,
        ]);
    }

    /** @param  array<string, mixed>  $meta */
    private function audit(CashierShift $shift, array $meta = []): void
    {
        AuditLog::create([
            'event' => 'cashier_shift.hold_overridden',
            'module' => 'cashier_shifts',
            'auditable_type' => $shift->getMorphClass(),
            'auditable_id' => $shift->id,
            'description' => 'Shift ditutup paksa dengan item belum diserahkan.',
            'meta' => $meta,
        ]);
    }

    private function unpaidBill(CashierShift $shift): void
    {
        $sale = Transaction::create([
            'cashier_id' => $shift->user_id, 'cashier_shift_id' => $shift->id, 'warehouse_id' => $this->warehouse->id,
            'invoice' => 'TRX-'.Str::upper(Str::random(8)), 'cash' => 0, 'change' => 0, 'discount' => 0,
            'grand_total' => 10000, 'payment_method' => 'pay_later', 'payment_status' => 'unpaid', 'is_pay_later' => true,
        ]);
        $sale->details()->create(['product_id' => $this->flagged->id, 'qty' => 1, 'price' => 10000, 'needs_preparation' => false]);
    }

    /** @return array<int, mixed> */
    private function summary(): array
    {
        return app(ForceCloseSummaryService::class)->monthSummary(
            CashierShift::query(),
            [$this->warehouse->id],
            $this->month,
        );
    }

    /** @return array{Outlet, Outlet} */
    private function twoOutlets(): array
    {
        return [
            Outlet::create(['code' => 'AAA', 'name' => 'Outlet A', 'is_active' => true, 'is_sales_enabled' => true]),
            Outlet::create(['code' => 'BBB', 'name' => 'Outlet B', 'is_active' => true, 'is_sales_enabled' => true]),
        ];
    }

    private function warehouseIn(Outlet $outlet): Warehouse
    {
        return Warehouse::create([
            'outlet_id' => $outlet->id,
            'code' => 'WH-'.$outlet->code,
            'name' => 'Gudang '.$outlet->name,
            'type' => 'branch',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    /** @param  array<int, Outlet>  $outlets */
    private function managerFor(array $outlets): User
    {
        return $this->cashierWithOutlets($outlets, ['cashier-shifts-access', 'cashier-shifts-force-close']);
    }

    /** @param  array<int, Outlet>  $outlets */
    private function cashierWithOutlets(array $outlets, array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $user->outlets()->attach(collect($outlets)->mapWithKeys(fn (Outlet $outlet) => [$outlet->id => ['is_default' => true]])->all());

        return $user;
    }
}
