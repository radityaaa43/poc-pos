<?php

namespace Tests\Feature\Inventory;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\OutletAccessService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();
        $this->actingAs($this->admin);
        $this->withSession($this->recentlyConfirmedSession());

        // Seed default warehouse
        $pusat = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        // Create sample product + pivot
        $category = Category::create(['image' => 'test.png', 'name' => 'Test', 'description' => 'Test']);
        $product = Product::create([
            'category_id' => $category->id,
            'image' => 'product.png',
            'barcode' => 'TEST001',
            'title' => 'Test Product',
            'description' => 'Test',
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 100,
        ]);
        $pusat->products()->attach($product->id, ['stock' => 100]);
    }

    public function test_index_warehouses_requires_warehouses_access_permission()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('warehouses-access');

        $this->actingAs($user)
            ->get(route('settings.warehouses.index'))
            ->assertOk();
    }

    public function test_index_warehouses_denies_without_permission()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.warehouses.index'))
            ->assertForbidden();
    }

    public function test_unit_and_warehouse_have_no_create_or_edit_routes()
    {
        // The forms are modals on the index page; the routes had no controller method (500).
        foreach (['units', 'warehouses'] as $resource) {
            $this->assertFalse(Route::has("settings.{$resource}.create"));
            $this->assertFalse(Route::has("settings.{$resource}.edit"));
        }
    }

    public function test_index_lists_inactive_warehouses_with_all_their_fields()
    {
        Warehouse::create([
            'code' => 'CAB-OFF', 'name' => 'Cabang Off', 'type' => 'branch', 'address' => 'Jl. Mawar 1',
            'phone' => '021-5550123', 'is_active' => false, 'sort_order' => 3,
        ]);

        $this->get(route('settings.warehouses.index'))->assertInertia(fn ($page) => $page
            ->component('Dashboard/Settings/Warehouses')
            ->has('warehouses', 2)
            ->where('warehouses.1.code', 'CAB-OFF')
            ->where('warehouses.1.is_active', false)
            ->where('warehouses.1.type', 'branch')
            ->where('warehouses.1.address', 'Jl. Mawar 1')
            ->where('warehouses.1.phone', '021-5550123')
            ->where('warehouses.1.sort_order', 3));
    }

    public function test_admin_can_create_warehouse()
    {
        $response = $this->from(route('settings.warehouses.index'))
            ->post(route('settings.warehouses.store'), [
                'code' => 'CABANG-A',
                'name' => 'Cabang A',
                'type' => 'branch',
                'address' => 'Jl. Merdeka No. 1',
                'phone' => '021-123456',
                'is_active' => true,
                'sort_order' => 1,
            ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('warehouses', ['code' => 'CABANG-A']);
    }

    public function test_create_warehouse_syncs_all_products()
    {
        $productCount = Product::count();

        $this->from(route('settings.warehouses.index'))
            ->post(route('settings.warehouses.store'), [
                'code' => 'CABANG-B',
                'name' => 'Cabang B',
                'type' => 'branch',
                'sort_order' => 2,
            ]);

        $warehouse = Warehouse::where('code', 'CABANG-B')->first();
        $this->assertNotNull($warehouse);
        $this->assertEquals($productCount, $warehouse->products()->count());
    }

    public function test_admin_can_update_warehouse()
    {
        $warehouse = Warehouse::factory()->create(['code' => 'GUDANG-1', 'name' => 'Gudang 1']);

        $this->from(route('settings.warehouses.index'))
            ->put(route('settings.warehouses.update', $warehouse->id), [
                'code' => 'GUDANG-1',
                'name' => 'Gudang 1 Updated',
                'type' => 'warehouse',
                'sort_order' => 0,
                'is_active' => true,
            ])->assertSessionHas('success');

        $this->assertDatabaseHas('warehouses', ['name' => 'Gudang 1 Updated']);
    }

    public function test_admin_can_reactivate_and_delete_an_inactive_warehouse()
    {
        $warehouse = Warehouse::factory()->create(['code' => 'OFF-1', 'type' => 'branch', 'is_active' => false]);

        $this->from(route('settings.warehouses.index'))
            ->put(route('settings.warehouses.update', $warehouse->id), [
                'code' => 'OFF-1',
                'name' => 'Back on',
                'type' => 'branch',
                'sort_order' => 0,
                'is_active' => true,
            ])->assertSessionHas('success');
        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'name' => 'Back on', 'is_active' => true]);

        $warehouse->update(['is_active' => false]);

        $this->from(route('settings.warehouses.index'))
            ->delete(route('settings.warehouses.destroy', $warehouse->id))
            ->assertSessionHas('success');
        $this->assertDatabaseMissing('warehouses', ['id' => $warehouse->id]);
    }

    public function test_inactive_warehouse_can_be_managed_but_not_used()
    {
        $inactive = Warehouse::factory()->create(['is_active' => false]);
        $service = app(OutletAccessService::class);

        $this->assertTrue($service->canManageWarehouse($this->admin, $inactive));
        $this->assertFalse($service->canUseWarehouse($this->admin, $inactive));
    }

    public function test_user_without_the_outlet_cannot_manage_its_inactive_warehouse()
    {
        $mine = Outlet::create(['code' => 'OUT-A', 'name' => 'Outlet A', 'is_active' => true]);
        $other = Outlet::create(['code' => 'OUT-B', 'name' => 'Outlet B', 'is_active' => true]);
        $inactive = Warehouse::factory()->create(['outlet_id' => $other->id, 'is_active' => false]);
        $user = User::factory()->create();
        $user->outlets()->attach($mine->id);

        $this->assertFalse(app(OutletAccessService::class)->canManageWarehouse($user, $inactive));
    }

    public function test_cannot_delete_main_warehouse()
    {
        $main = Warehouse::where('type', 'main')->first();

        $this->from(route('settings.warehouses.index'))
            ->delete(route('settings.warehouses.destroy', $main->id))
            ->assertSessionHas('error');
    }

    public function test_cannot_delete_warehouse_with_stock()
    {
        $warehouse = Warehouse::factory()->create(['code' => 'HAS-STOK', 'type' => 'branch']);
        $product = Product::first();
        $warehouse->products()->attach($product->id, ['stock' => 10]);

        $this->from(route('settings.warehouses.index'))
            ->delete(route('settings.warehouses.destroy', $warehouse->id))
            ->assertSessionHas('error');
    }

    public function test_can_delete_empty_warehouse()
    {
        $warehouse = Warehouse::factory()->create(['code' => 'KOSONG', 'type' => 'branch']);

        $this->from(route('settings.warehouses.index'))
            ->delete(route('settings.warehouses.destroy', $warehouse->id))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('warehouses', ['id' => $warehouse->id]);
    }

    public function test_cannot_delete_warehouse_with_operational_history(): void
    {
        $warehouse = Warehouse::factory()->create(['code' => 'USED-WH', 'type' => 'branch']);

        $shift = app(CashierShiftService::class)->openShift($this->admin, $this->admin, 0, null, $warehouse->id);
        $shift->update(['status' => 'closed']);

        $this->delete(route('settings.warehouses.destroy', $warehouse->id))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id]);
    }

    public function test_cannot_deactivate_warehouse_with_open_shift(): void
    {
        $warehouse = Warehouse::factory()->create(['code' => 'OPEN-WH', 'type' => 'branch']);

        app(CashierShiftService::class)->openShift($this->admin, $this->admin, 0, null, $warehouse->id);

        $this->put(route('settings.warehouses.update', $warehouse->id), [
            'code' => $warehouse->code,
            'name' => $warehouse->name,
            'type' => $warehouse->type,
            'is_active' => false,
            'sort_order' => 0,
        ])->assertSessionHas('error');

        $this->assertDatabaseHas('warehouses', ['id' => $warehouse->id, 'is_active' => true]);
    }

    public function test_default_warehouse_is_created_after_seed()
    {
        $this->assertDatabaseHas('warehouses', ['code' => 'PUSAT', 'type' => 'main']);
    }

    public function test_product_has_stock_total_helper()
    {
        $warehouse = Warehouse::where('code', 'PUSAT')->first();
        $product = Product::first();
        $product->warehouses()->syncWithPivotValues([$warehouse->id], ['stock' => 50]);

        $this->assertEquals(50, $product->stockTotal());
    }

    public function test_code_must_be_unique()
    {
        Warehouse::factory()->create(['code' => 'DUPLICATE']);

        $this->post(route('settings.warehouses.store'), [
            'code' => 'DUPLICATE',
            'name' => 'Duplicate',
            'type' => 'branch',
            'sort_order' => 0,
        ])->assertInvalid(['code']);
    }
}
