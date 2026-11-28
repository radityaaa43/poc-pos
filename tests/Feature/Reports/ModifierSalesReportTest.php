<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\Outlet;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Modifiers\ModifierSaleTestCase;

// REQ-008 AC-19, J5 (#420): quantity sold and option revenue per option, for one outlet and period.
class ModifierSalesReportTest extends ModifierSaleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier->givePermissionTo(['reports-access', 'sales-returns-access', 'sales-returns-create', 'sales-returns-complete']);
    }

    // BR-3: price x quantity before line discounts, here a Rp 4.000 manual discount on the sale.
    public function test_each_option_shows_quantity_and_revenue_before_discounts(): void
    {
        $this->sell(['Large', 'Boba'], 2, ['discount' => 4000]);
        $this->sell(['Large']);
        $this->sell(['Regular']);

        $this->assertSame([
            ['Size', 'Large', 3, 15000],
            ['Topping', 'Boba', 2, 6000],
            ['Size', 'Regular', 1, 0],
        ], $this->rows());
    }

    public function test_report_is_scoped_to_the_active_outlet(): void
    {
        $other = Outlet::create(['code' => 'O2', 'name' => 'Outlet 2', 'is_active' => true, 'is_sales_enabled' => true]);
        $warehouse = Warehouse::create(['code' => 'W2', 'name' => 'Gudang 2', 'status' => 'active', 'outlet_id' => $other->id]);
        $this->sell(['Large']);
        $this->sell(['Boba', 'Regular'])->update(['warehouse_id' => $warehouse->id]);

        // The cashier's open shift fixes the outlet; the owner picks it with the outlet switcher.
        $this->assertSame([['Size', 'Large', 1, 5000]], $this->rows());

        $owner = User::where('email', 'arya@gmail.com')->firstOrFail();
        $owner->markEmailAsVerified();
        $this->actingAs($owner)->withSession(['active_outlet_id' => $other->id]);

        $this->assertSame([['Topping', 'Boba', 1, 3000], ['Size', 'Regular', 1, 0]], $this->rows());
    }

    // BR-1: whole days in the app timezone, as the sales report filters them.
    public function test_period_covers_whole_days_of_the_app_timezone(): void
    {
        foreach (['2026-03-09 23:59:59', '2026-03-10 00:00:00', '2026-03-11 23:59:59', '2026-03-12 00:00:00'] as $soldAt) {
            $this->sell(['Large'])->forceFill(['created_at' => $soldAt])->save();
        }

        $this->assertSame([['Size', 'Large', 2, 10000]], $this->rows(['start_date' => '2026-03-10', 'end_date' => '2026-03-11']));
        $this->assertSame([], $this->rows(['start_date' => '2026-04-01']));
        $this->get(route('reports.modifiers.index', ['start_date' => 'kemarin']))->assertSessionHasErrors('start_date');
    }

    public function test_completed_returns_reduce_quantity_and_revenue(): void
    {
        $sale = $this->sell(['Large', 'Boba'], 3);
        $this->draftReturn($sale, 1);
        $this->post(route('sales-returns.complete', SalesReturn::sole()))->assertSessionHasNoErrors();
        // A draft is not a return yet.
        $this->draftReturn($sale, 1);

        $this->assertSame([['Size', 'Large', 2, 10000], ['Topping', 'Boba', 2, 6000]], $this->rows());
    }

    public function test_a_renamed_or_deleted_option_keeps_the_name_it_was_sold_under(): void
    {
        $this->sell(['Large']);
        $this->option('Large')->update(['name' => 'Jumbo', 'price_delta' => 9000]);
        $this->sell(['Jumbo']);
        $this->option('Jumbo')->delete();

        // One option, two rows: each name with what was sold under it.
        $this->assertSame([['Size', 'Jumbo', 1, 9000], ['Size', 'Large', 1, 5000]], $this->rows());
    }

    // BR-5: paid sales and sales on credit count from the moment they are made; other unpaid and cancelled ones don't.
    public function test_paid_and_credit_sales_count(): void
    {
        foreach (['pending', 'pending_approval', 'unpaid', 'failed', 'expired'] as $status) {
            $this->sell(['Large'])->update(['payment_status' => $status]);
        }

        $this->assertSame([], $this->rows());

        $customer = Customer::create(['name' => 'Budi', 'no_telp' => '62811', 'address' => 'Jl. Satu']);

        foreach (['unpaid', 'failed'] as $status) {
            $sale = $this->sell(['Regular']);
            $sale->update(['payment_status' => $status]);
            $sale->receivable()->create([
                'customer_id' => $customer->id, 'invoice' => $sale->invoice, 'total' => $sale->grand_total, 'paid' => 0,
                'status' => 'unpaid',
            ]);
        }

        $this->assertSame([['Size', 'Regular', 1, 0]], $this->rows());
    }

    public function test_report_needs_reports_access(): void
    {
        $this->cashier->revokePermissionTo('reports-access');

        $this->get(route('reports.modifiers.index'))->assertForbidden();
    }

    /** @param  list<string>  $options */
    private function sell(array $options, int $qty = 1, array $payload = []): Transaction
    {
        $this->add($options, $qty)->assertSessionHasNoErrors();
        $this->checkout($payload)->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    private function draftReturn(Transaction $sale, int $qty): void
    {
        $this->post(route('sales-returns.store', $sale), [
            'return_type' => 'refund_cash',
            'items' => [['transaction_detail_id' => $sale->details()->sole()->id, 'qty_return' => $qty, 'return_reason' => 'Salah pesan']],
        ])->assertSessionHasNoErrors();
    }

    /** @return list<array{0: string, 1: string, 2: int, 3: int}> group, option, quantity, revenue */
    private function rows(array $filters = []): array
    {
        $rows = [];
        $this->get(route('reports.modifiers.index', $filters))->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows) {
                $rows = collect($page->component('Dashboard/Reports/ModifierSales')->toArray()['props']['rows']['data'])
                    ->map(fn (array $row) => [$row['group_name'], $row['option_name'], $row['qty'], $row['revenue']])->all();
            });

        return $rows;
    }
}
