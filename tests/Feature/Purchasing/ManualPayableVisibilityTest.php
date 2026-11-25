<?php

namespace Tests\Feature\Purchasing;

use App\Models\Outlet;
use App\Models\Payable;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

// A payable entered by hand has no purchase order, so no outlet. Like other records without an
// outlet_id (price lists, dine areas, ...) it is shared: everyone with payables-access sees it.
class ManualPayableVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Payable $manual;

    private Payable $otherOutlet;

    private Outlet $outletA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outletA = Outlet::create(['code' => 'MPA', 'name' => 'Outlet A']);
        $outletB = Outlet::create(['code' => 'MPB', 'name' => 'Outlet B']);
        $warehouseB = Warehouse::create([
            'code' => 'WH-MPB', 'name' => 'Gudang B', 'type' => 'branch',
            'is_active' => true, 'sort_order' => 0, 'outlet_id' => $outletB->id,
        ]);
        Warehouse::create([
            'code' => 'WH-MPA', 'name' => 'Gudang A', 'type' => 'branch',
            'is_active' => true, 'sort_order' => 0, 'outlet_id' => $this->outletA->id,
        ]);

        $supplier = Supplier::create(['name' => 'Pemasok']);
        $po = PurchaseOrder::create([
            'warehouse_id' => $warehouseB->id, 'supplier_id' => $supplier->id,
            'document_number' => 'PO-MP-B', 'status' => 'completed',
            'order_date' => now(), 'expected_date' => now(),
        ]);
        $this->otherOutlet = Payable::create([
            'supplier_id' => $supplier->id, 'purchase_order_id' => $po->id,
            'document_number' => 'INV-OTHER', 'total' => 1000, 'paid' => 0, 'status' => 'unpaid',
        ]);
        $this->manual = Payable::create([
            'supplier_id' => $supplier->id, 'document_number' => 'INV-MANUAL',
            'total' => 1000, 'paid' => 0, 'status' => 'unpaid',
        ]);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->outlets()->attach($this->outletA->id, ['is_default' => true]);
        $user->givePermissionTo(Permission::firstOrCreate(['name' => 'payables-access', 'guard_name' => 'web']));

        return $user;
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->assignRole(Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']));

        return $user;
    }

    public function test_manager_sees_and_opens_a_manual_payable_with_several_outlets(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->get(route('payables.index'))
            ->assertInertia(fn ($page) => $page
                ->has('payables.data', 1)
                ->where('payables.data.0.id', $this->manual->id));

        $this->actingAs($manager)->get(route('payables.show', $this->manual))->assertOk();
        $this->actingAs($manager)->get(route('pdf.payables.show', $this->manual))->assertOk();
        $this->actingAs($manager)->get(route('payables.show', $this->otherOutlet))->assertNotFound();
    }

    public function test_super_admin_sees_and_opens_a_manual_payable_with_several_outlets(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->get(route('payables.index'))
            ->assertInertia(fn ($page) => $page->has('payables.data', 2));

        $this->actingAs($admin)->get(route('payables.show', $this->manual))->assertOk();
        $this->actingAs($admin)->get(route('pdf.payables.show', $this->manual))->assertOk();
    }
}
