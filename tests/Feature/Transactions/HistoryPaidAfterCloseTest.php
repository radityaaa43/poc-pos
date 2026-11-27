<?php

namespace Tests\Feature\Transactions;

use App\Models\Outlet;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * REQ-005 AC-08 (#327): Transactions History carries paid_after_close_at on each row and filters to the sales that
 * were paid after their shift closed, next to the "Perlu refund" filter, within the same scope as the page.
 */
class HistoryPaidAfterCloseTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);
        $outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true]);
        $this->warehouse = Warehouse::create(['code' => 'WH-1', 'name' => 'WH-1', 'status' => 'active', 'outlet_id' => $outlet->id]);
    }

    private function user(bool $superAdmin = false): User
    {
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->givePermissionTo('transactions-access');
        if ($superAdmin) {
            $user->assignRole(Role::findOrCreate('super-admin', 'web'));
        }

        return $user;
    }

    private function sale(User $cashier, bool $afterClose, bool $late = false): Transaction
    {
        return Transaction::create([
            'cashier_id' => $cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice' => 'INV-HPC-'.++$this->seq,
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'shipping_cost' => 0,
            'grand_total' => 20000,
            'payment_method' => 'midtrans',
            'payment_status' => 'paid',
            'paid_after_close_at' => $afterClose ? now() : null,
            'late_superseded_payment_at' => $late ? now() : null,
        ]);
    }

    /** @return list<string> the invoices of the rows the page lists */
    private function listed($response): array
    {
        $invoices = [];
        $response->assertOk()->assertInertia(function (Assert $page) use (&$invoices) {
            $invoices = collect($page->toArray()['props']['transactions']['data'])->pluck('invoice')->sort()->values()->all();
        });

        return $invoices;
    }

    public function test_the_filter_lists_only_the_sales_paid_after_their_shift_closed(): void
    {
        $admin = $this->user(true);
        $flagged = $this->sale($admin, true);
        $this->sale($admin, false);

        $response = $this->actingAs($admin)->get(route('transactions.history', ['paid_after_close' => 1]));

        $this->assertSame([$flagged->invoice], $this->listed($response));
        $response->assertInertia(fn (Assert $page) => $page->where('filters.paid_after_close', true));
    }

    public function test_without_the_filter_every_sale_is_listed_and_the_row_carries_the_flag(): void
    {
        $admin = $this->user(true);
        $flagged = $this->sale($admin, true);
        $normal = $this->sale($admin, false);

        $response = $this->actingAs($admin)->get(route('transactions.history'));

        $this->assertSame([$flagged->invoice, $normal->invoice], $this->listed($response));
        $response->assertInertia(function (Assert $page) use ($flagged, $normal) {
            $rows = collect($page->toArray()['props']['transactions']['data']);
            $this->assertNotNull($rows->firstWhere('id', $flagged->id)['paid_after_close_at']);
            $this->assertNull($rows->firstWhere('id', $normal->id)['paid_after_close_at']);
            $page->where('filters.paid_after_close', false);
        });
    }

    public function test_the_two_filters_are_independent(): void
    {
        $admin = $this->user(true);
        $afterClose = $this->sale($admin, true);
        $late = $this->sale($admin, false, true);

        $this->assertSame([$late->invoice], $this->listed($this->actingAs($admin)->get(route('transactions.history', ['late_payment' => 1]))));
        $this->assertSame([$afterClose->invoice], $this->listed($this->actingAs($admin)->get(route('transactions.history', ['paid_after_close' => 1]))));
    }

    public function test_a_cashier_only_sees_her_own_flagged_sales(): void
    {
        $cashier = $this->user();
        $mine = $this->sale($cashier, true);
        $this->sale($this->user(), true);

        $response = $this->actingAs($cashier)->get(route('transactions.history', ['paid_after_close' => 1]));

        $this->assertSame([$mine->invoice], $this->listed($response));
    }
}
