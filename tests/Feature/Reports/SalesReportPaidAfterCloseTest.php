<?php

namespace Tests\Feature\Reports;

use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * REQ-005 AC-08 (#327): the sales report gives gateway sales that were paid after their shift closed an own line
 * (count and total), by the sale's own date, inside the period's totals. The API twin carries the same keys.
 */
class SalesReportPaidAfterCloseTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true]);
        $this->warehouse = $this->warehouseOf($outlet, 'WH-1');
    }

    private function warehouseOf(Outlet $outlet, string $code): Warehouse
    {
        return Warehouse::create(['code' => $code, 'name' => $code, 'status' => 'active', 'outlet_id' => $outlet->id]);
    }

    private function superAdmin(): User
    {
        Permission::firstOrCreate(['name' => 'reports-access', 'guard_name' => 'web']);
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->assignRole(Role::findOrCreate('super-admin', 'web'));
        $user->givePermissionTo('reports-access');

        return $user;
    }

    /** A sale of $total; $afterClose marks it paid after its shift closed. */
    private function sale(int $total, bool $afterClose, string $status = 'paid', ?Warehouse $warehouse = null, $createdAt = null): Transaction
    {
        $sale = Transaction::create([
            'cashier_id' => User::factory()->create()->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'invoice' => 'INV-PAC-'.++$this->seq,
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => $total,
            'payment_method' => 'midtrans',
            'payment_status' => $status,
            'paid_after_close_at' => $afterClose ? now() : null,
        ]);

        if ($createdAt) {
            $sale->forceFill(['created_at' => $createdAt])->save();
        }

        return $sale;
    }

    public function test_the_summary_counts_the_sales_paid_after_their_shift_closed(): void
    {
        $this->sale(20000, true);
        $this->sale(30000, true);
        $this->sale(10000, false);
        // Never booked as such: a flag on a bill that is not paid does not make it part of the line.
        $this->sale(5000, true, 'pending');

        $this->actingAs($this->superAdmin())->get(route('reports.sales.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Reports/Sales')
                ->where('summary.paid_after_close_count', 2)
                ->where('summary.paid_after_close_total', 50000));
    }

    public function test_the_line_is_zero_when_nothing_was_paid_after_a_close(): void
    {
        $this->sale(10000, false);

        $this->actingAs($this->superAdmin())->get(route('reports.sales.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paid_after_close_count', 0)
                ->where('summary.paid_after_close_total', 0));
    }

    public function test_the_row_of_such_a_sale_carries_its_flag(): void
    {
        $flagged = $this->sale(20000, true);
        $this->sale(10000, false);

        $this->actingAs($this->superAdmin())->get(route('reports.sales.index'))
            ->assertInertia(function (Assert $page) use ($flagged) {
                $rows = collect($page->toArray()['props']['transactions']['data']);

                $this->assertNotNull($rows->firstWhere('id', $flagged->id)['paid_after_close_at']);
                $this->assertSame(1, $rows->whereNotNull('paid_after_close_at')->count());
            });
    }

    public function test_the_period_is_the_date_of_the_sale(): void
    {
        $this->sale(20000, true, 'paid', null, now()->subDays(5));
        $this->sale(30000, true);

        $this->actingAs($this->superAdmin())
            ->get(route('reports.sales.index', ['start_date' => now()->subDay()->toDateString(), 'end_date' => now()->toDateString()]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paid_after_close_count', 1)
                ->where('summary.paid_after_close_total', 30000));
    }

    public function test_it_is_scoped_to_the_outlets_of_the_user(): void
    {
        Permission::firstOrCreate(['name' => 'reports-access', 'guard_name' => 'web']);
        $mine = Outlet::first();
        $other = Outlet::create(['code' => 'OUT-2', 'name' => 'Outlet 2', 'is_active' => true]);
        $this->sale(20000, true);
        $this->sale(70000, true, 'paid', $this->warehouseOf($other, 'WH-2'));

        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->givePermissionTo('reports-access');
        $user->outlets()->attach($mine->id, ['is_default' => true]);

        $this->actingAs($user)->get(route('reports.sales.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paid_after_close_count', 1)
                ->where('summary.paid_after_close_total', 20000));
    }

    public function test_the_api_report_carries_the_same_keys(): void
    {
        $this->sale(20000, true);
        $this->sale(30000, true);
        Sanctum::actingAs($this->superAdmin(), ['*']);

        $this->getJson('/api/v1/reports/sales')
            ->assertOk()
            ->assertJsonPath('data.summary.paid_after_close_count', 2)
            ->assertJsonPath('data.summary.paid_after_close_total', 50000);
    }
}
