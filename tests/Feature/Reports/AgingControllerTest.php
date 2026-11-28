<?php

namespace Tests\Feature\Reports;

use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Payable;
use App\Models\PurchaseOrder;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OutletAccessService;
use App\Services\PayableAgingService;
use App\Services\ReceivableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AgingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'receivables-access', 'guard_name' => 'web']);
    }

    private function makeOutletWithWarehouse(string $code): array
    {
        $outlet = Outlet::create([
            'code' => $code,
            'name' => 'Outlet '.$code,
        ]);

        $warehouse = Warehouse::create([
            'code' => 'WH-'.$code,
            'name' => 'Gudang '.$code,
            'type' => 'branch',
            'is_active' => true,
            'sort_order' => 0,
            'outlet_id' => $outlet->id,
        ]);

        return [$outlet, $warehouse];
    }

    private function makeUserForOutlet(Outlet $outlet, array $permissions = []): User
    {
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->outlets()->attach($outlet->id, ['is_default' => true]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function makeReceivable(Warehouse $warehouse, Customer $customer, string $invoice, int $total): Receivable
    {
        $cashier = User::factory()->create();
        $transaction = Transaction::create([
            'invoice' => 'INV-'.$invoice,
            'cashier_id' => $cashier->id,
            'user_id' => $cashier->id,
            'warehouse_id' => $warehouse->id,
            'payment_method' => 'pay_later',
            'payment_status' => 'unpaid',
            'grand_total' => $total,
            'total' => $total,
            'discount' => 0,
            'cash' => 0,
            'change' => 0,
            'access_token' => Str::uuid()->toString(),
        ]);

        return Receivable::create([
            'transaction_id' => $transaction->id,
            'customer_id' => $customer->id,
            'invoice' => $invoice,
            'due_date' => now()->addDay(),
            'total' => $total,
            'paid' => 0,
            'status' => 'partial',
        ]);
    }

    private function makePayable(Warehouse $warehouse, Supplier $supplier, string $documentNumber, int $total): Payable
    {
        $purchaseOrder = PurchaseOrder::create([
            'warehouse_id' => $warehouse->id,
            'supplier_id' => $supplier->id,
            'document_number' => $documentNumber,
            'status' => 'completed',
            'order_date' => now()->subDays(10),
            'expected_date' => now()->subDays(5),
        ]);

        return Payable::create([
            'purchase_order_id' => $purchaseOrder->id,
            'supplier_id' => $supplier->id,
            'document_number' => $documentNumber,
            'due_date' => now()->addDay(),
            'total' => $total,
            'paid' => 0,
            'status' => 'partial',
        ]);
    }

    public function test_a_non_aging_page_shares_no_aging_summary(): void
    {
        [$outlet, $warehouse] = $this->makeOutletWithWarehouse('NAP');

        $customer = Customer::create(['name' => 'Cust NAP', 'no_telp' => '628111', 'address' => 'Jl. NAP']);
        $supplier = Supplier::create(['name' => 'Supplier NAP', 'phone' => '628112', 'address' => 'Jl. NAP']);

        $this->makeReceivable($warehouse, $customer, 'RCV-NAP', 10000);
        $this->makePayable($warehouse, $supplier, 'PO-NAP', 50000);

        $user = $this->makeUserForOutlet($outlet);

        $response = $this->actingAs($user)->get('/dashboard/access');
        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->missing('payableAgingSummary')
            ->missing('receivableAgingSummary')
            ->has('payableNotifications')
            ->has('receivableNotifications'));
    }

    public function test_a_non_aging_page_runs_no_aging_summary_query(): void
    {
        [$outlet, $warehouse] = $this->makeOutletWithWarehouse('NQR');

        $customer = Customer::create(['name' => 'Cust NQR', 'no_telp' => '628113', 'address' => 'Jl. NQR']);
        $supplier = Supplier::create(['name' => 'Supplier NQR', 'phone' => '628114', 'address' => 'Jl. NQR']);

        $this->makeReceivable($warehouse, $customer, 'RCV-NQR', 10000);
        $this->makePayable($warehouse, $supplier, 'PO-NQR', 50000);

        $user = $this->makeUserForOutlet($outlet);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->actingAs($user)->get('/dashboard/access')->assertOk();

        // getAgingSummary() loads every row with `select *`; the notification lists select explicit columns.
        // SQLite quotes identifiers with " and MySQL with `.
        $summaryQueries = collect($queries)->filter(
            fn (string $sql) => preg_match('/^select \* from [`"]?(receivables|payables)[`"]?/i', $sql) === 1
        );

        $this->assertCount(0, $summaryQueries, 'A plain page must not run the aging summary queries: '.$summaryQueries->implode(' | '));
    }

    public function test_the_aging_page_gets_both_summaries(): void
    {
        [$outlet, $warehouse] = $this->makeOutletWithWarehouse('AGP');

        $customer = Customer::create(['name' => 'Cust AGP', 'no_telp' => '628115', 'address' => 'Jl. AGP']);
        $supplier = Supplier::create(['name' => 'Supplier AGP', 'phone' => '628116', 'address' => 'Jl. AGP']);

        $this->makeReceivable($warehouse, $customer, 'RCV-AGP', 10000);
        $this->makePayable($warehouse, $supplier, 'PO-AGP', 50000);

        $user = $this->makeUserForOutlet($outlet, ['receivables-access']);
        $warehouseIds = app(OutletAccessService::class)->warehousesFor($user)->pluck('id');

        $expectedPayable = app(PayableAgingService::class)->getAgingSummary($warehouseIds)->toArray();
        $expectedReceivable = app(ReceivableService::class)->getAgingSummary($warehouseIds)->toArray();

        $response = $this->actingAs($user)->get('/dashboard/aging');
        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('payableAgingSummary', fn ($summary) => collect($summary)->toArray() == $expectedPayable)
            ->where('receivableAgingSummary', fn ($summary) => collect($summary)->toArray() == $expectedReceivable));
    }

    public function test_the_aging_summaries_are_scoped_to_the_users_warehouses(): void
    {
        [$outletA, $warehouseA] = $this->makeOutletWithWarehouse('AGA');
        [$outletB, $warehouseB] = $this->makeOutletWithWarehouse('AGB');

        $customer = Customer::create(['name' => 'Cust Scope', 'no_telp' => '628117', 'address' => 'Jl. Scope']);
        $supplier = Supplier::create(['name' => 'Supplier Scope', 'phone' => '628118', 'address' => 'Jl. Scope']);

        $this->makeReceivable($warehouseA, $customer, 'RCV-AGA', 10000);
        $this->makeReceivable($warehouseB, $customer, 'RCV-AGB', 20000);
        $this->makePayable($warehouseA, $supplier, 'PO-AGA', 50000);
        $this->makePayable($warehouseB, $supplier, 'PO-AGB', 70000);

        $user = $this->makeUserForOutlet($outletA, ['receivables-access']);

        $response = $this->actingAs($user)->get('/dashboard/aging');
        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->where('receivableAgingSummary', fn ($summary) => collect($summary)->contains(fn ($bucket) => $bucket['bucket'] === 'current'
                && $bucket['count'] === 1
                && $bucket['total'] === 10000))
            ->where('payableAgingSummary', fn ($summary) => collect($summary)->contains(fn ($bucket) => $bucket['bucket'] === 'current'
                && $bucket['count'] === 1
                && $bucket['total'] === 50000)));
    }
}
