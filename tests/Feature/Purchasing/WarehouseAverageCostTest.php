<?php

namespace Tests\Feature\Purchasing;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\InventoryCostService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-002 AC-06, AC-07, AC-20, AC-21: weighted-average cost per warehouse.
 */
class WarehouseAverageCostTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $pusat;

    private Warehouse $outlet;

    private Unit $gram;

    private Unit $kg;

    private Product $beans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();

        $this->pusat = Warehouse::create(['code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);
        $this->outlet = Warehouse::create(['code' => 'OUT', 'name' => 'Gudang Outlet', 'type' => 'branch', 'is_active' => true, 'sort_order' => 1]);
        $this->gram = Unit::firstOrCreate(['code' => 'GRAM'], ['name' => 'Gram', 'symbol' => 'g']);
        $this->kg = Unit::firstOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'symbol' => 'kg']);

        // Coffee in grams, bought per kg; PUSAT holds 4,950 g at Rp 250/g, the outlet 1,000 g at Rp 100/g.
        $this->beans = Product::create([
            'title' => 'Biji Kopi', 'sku' => 'RM-1', 'barcode' => 'RM-1', 'buy_price' => 250, 'sell_price' => 0,
            'stock' => 5950, 'image' => 'x.jpg', 'description' => 'x', 'tax_rate' => 0, 'is_raw_material' => true,
            'category_id' => Category::create(['name' => 'Bahan Baku'])->id,
        ]);
        $this->beans->units()->attach($this->gram->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 250, 'sell_price' => 0]);
        $this->beans->units()->attach($this->kg->id, ['conversion_factor' => 1000, 'buy_price' => 250000, 'sell_price' => 0, 'is_purchase_default' => true]);
        $this->beans->warehouses()->attach($this->pusat->id, ['stock' => 4950, 'avg_cost' => 250]);
        $this->beans->warehouses()->attach($this->outlet->id, ['stock' => 1000, 'avg_cost' => 100]);
    }

    private function avg(Warehouse $warehouse): ?float
    {
        return ProductWarehouse::where(['product_id' => $this->beans->id, 'warehouse_id' => $warehouse->id])->value('avg_cost');
    }

    /** Orders 5 kg at the given price per kg for PUSAT and receives it all through the web endpoints. */
    private function receiveFiveKg(int $pricePerKg = 280000): void
    {
        $this->actingAs($this->admin);
        $this->post(route('purchase-orders.store'), [
            'warehouse_id' => $this->pusat->id,
            'supplier_id' => Supplier::create(['name' => 'CV Kopi'])->id,
            'items' => [['product_id' => $this->beans->id, 'qty_ordered' => 5, 'unit_price' => $pricePerKg, 'unit_id' => $this->kg->id]],
        ])->assertSessionHasNoErrors();
        $order = PurchaseOrder::firstOrFail();
        $this->post(route('purchase-orders.place', $order))->assertSessionHasNoErrors();
        $this->post(route('goods-receivings.store'), [
            'purchase_order_id' => $order->id,
            'items' => [['purchase_order_item_id' => $order->items()->value('id'), 'qty_received' => 5]],
        ])->assertSessionHasNoErrors();
    }

    public function test_receiving_merges_the_weighted_average_into_that_warehouse_only(): void
    {
        $this->receiveFiveKg();

        // (4,950 x 250 + 5,000 x 280) / 9,950
        $this->assertEqualsWithDelta(265.0754, $this->avg($this->pusat), 0.0001);
        $this->assertEquals(100, $this->avg($this->outlet));
        $this->assertSame(9950, ProductWarehouse::where(['product_id' => $this->beans->id, 'warehouse_id' => $this->pusat->id])->value('stock'));
    }

    public function test_receiving_updates_the_purchase_unit_price_and_reference_price(): void
    {
        $this->receiveFiveKg();

        $this->assertSame(280000, $this->beans->units()->where('units.id', $this->kg->id)->first()->pivot->buy_price);
        $this->assertSame(280, $this->beans->fresh()->buy_price);
    }

    public function test_receiving_into_an_empty_warehouse_takes_the_new_cost(): void
    {
        ProductWarehouse::where('warehouse_id', $this->pusat->id)->update(['stock' => 0]);

        $this->receiveFiveKg();

        $this->assertEquals(280, $this->avg($this->pusat));
    }

    public function test_receiving_with_no_previous_average_takes_the_new_cost(): void
    {
        ProductWarehouse::where('warehouse_id', $this->pusat->id)->update(['avg_cost' => null]);

        $this->receiveFiveKg();

        $this->assertEquals(280, $this->avg($this->pusat));
    }

    public function test_receiving_into_a_warehouse_without_a_row_creates_it_at_the_received_cost(): void
    {
        ProductWarehouse::where('warehouse_id', $this->pusat->id)->delete();

        $this->receiveFiveKg();

        $this->assertEquals(280, $this->avg($this->pusat));
    }

    public function test_unit_cost_falls_back_to_the_reference_price(): void
    {
        $service = app(InventoryCostService::class);

        $this->assertEquals(250, $service->unitCost($this->beans, $this->pusat->id));
        $this->assertEquals(100, $service->unitCost($this->beans, $this->outlet->id));

        ProductWarehouse::where('warehouse_id', $this->outlet->id)->update(['avg_cost' => null]);
        $this->assertEquals(250, $service->unitCost($this->beans, $this->outlet->id));
        $this->assertEquals(250, $service->unitCost($this->beans, 999));
    }

    public function test_a_new_warehouse_row_starts_at_the_reference_price(): void
    {
        $row = ProductWarehouse::create(['product_id' => $this->beans->id, 'warehouse_id' => Warehouse::create(['code' => 'W3', 'name' => 'W3', 'type' => 'branch', 'is_active' => true, 'sort_order' => 2])->id, 'stock' => 0]);

        $this->assertEquals(250, $row->fresh()->avg_cost);
    }

    public function test_raw_material_opening_stock_sets_the_average_to_the_reference_price(): void
    {
        $this->actingAs($this->admin)->post(route('raw-materials.store'), [
            'title' => 'Gula', 'stock_unit_id' => $this->gram->id, 'purchased_by' => 'central',
            'units' => [['unit_id' => $this->kg->id, 'conversion_factor' => 1000, 'buy_price' => 20000, 'is_purchase_default' => true]],
            'opening_warehouse_id' => $this->outlet->id, 'opening_qty' => 2, 'opening_unit_id' => $this->kg->id,
        ])->assertSessionHasNoErrors();

        $sugar = Product::where('title', 'Gula')->firstOrFail();
        $this->assertSame(20, $sugar->buy_price);
        $this->assertEquals(20, ProductWarehouse::where(['product_id' => $sugar->id, 'warehouse_id' => $this->outlet->id])->value('avg_cost'));
    }

    public function test_product_form_opening_stock_sets_the_average_to_the_buy_price(): void
    {
        $this->actingAs($this->admin)->post(route('products.store'), [
            'barcode' => 'B-1', 'sku' => 'S-1', 'title' => 'Teh', 'description' => 'x', 'category_id' => $this->beans->category_id,
            'buy_price' => 1234, 'sell_price' => 2000, 'stock' => 10, 'tax_rate' => 0, 'warehouse_id' => $this->outlet->id,
            'image' => UploadedFile::fake()->image('p.png'),
        ])->assertSessionHasNoErrors();

        $tea = Product::where('title', 'Teh')->firstOrFail();
        $this->assertEquals(1234, ProductWarehouse::where(['product_id' => $tea->id, 'warehouse_id' => $this->outlet->id])->value('avg_cost'));
    }

    public function test_api_product_store_opening_stock_sets_the_average_to_the_buy_price(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $this->postJson('/api/v1/products', [
            'barcode' => 'B-2', 'title' => 'Susu', 'buy_price' => 777, 'sell_price' => 1000, 'stock' => 5, 'tax_rate' => 0,
            'category_id' => $this->beans->category_id, 'warehouse_id' => $this->outlet->id,
        ])->assertCreated();

        $milk = Product::where('title', 'Susu')->firstOrFail();
        $this->assertEquals(777, ProductWarehouse::where(['product_id' => $milk->id, 'warehouse_id' => $this->outlet->id])->value('avg_cost'));
    }

    public function test_raw_materials_list_shows_the_average_per_accessible_warehouse(): void
    {
        $this->actingAs($this->admin)
            ->get(route('raw-materials.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('materials.data.0.stocks', fn ($stocks) => collect($stocks)->pluck('cost', 'warehouse_id')->map(fn ($c) => (float) $c)->all() === [
                    $this->pusat->id => 250.0,
                    $this->outlet->id => 100.0,
                ]));
    }
}
