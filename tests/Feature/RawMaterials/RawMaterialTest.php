<?php

namespace Tests\Feature\RawMaterials;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\StockMutation;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RawMaterialTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $pusat;

    private Unit $gram;

    private Unit $kg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();

        $this->pusat = Warehouse::create(['code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);
        $this->gram = Unit::where('code', 'GRAM')->firstOrFail();
        $this->kg = Unit::where('code', 'KG')->firstOrFail();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'Biji kopi',
            'stock_unit_id' => $this->gram->id,
            'purchased_by' => 'central',
            'min_stock' => 2500,
            'units' => [
                ['unit_id' => $this->kg->id, 'conversion_factor' => 1000, 'buy_price' => 250000, 'is_purchase_default' => true],
            ],
        ], $overrides);
    }

    private function rawMaterial(string $title = 'Gula'): Product
    {
        $product = Product::create([
            'category_id' => Category::create(['name' => 'Bahan Baku'])->id,
            'barcode' => 'RM-'.$title,
            'sku' => 'RM-'.$title,
            'title' => $title,
            'buy_price' => 10,
            'sell_price' => 0,
            'stock' => 0,
            'tax_rate' => 0,
            'is_raw_material' => true,
        ]);
        $product->units()->attach($this->gram->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 10, 'sell_price' => 0]);

        return $product;
    }

    private function mutation(Product $product, string $referenceType): StockMutation
    {
        return StockMutation::create([
            'product_id' => $product->id,
            'warehouse_id' => $this->pusat->id,
            'reference_type' => $referenceType,
            'reference_id' => 1,
            'mutation_type' => 'in',
            'qty' => 5,
            'stock_before' => 0,
            'stock_after' => 5,
        ]);
    }

    public function test_creating_without_codes_stores_raw_material_with_reference_price(): void
    {
        $this->actingAs($this->admin)
            ->post(route('raw-materials.store'), $this->payload())
            ->assertRedirect(route('raw-materials.index'));

        $product = Product::where('title', 'Biji kopi')->firstOrFail();
        $this->assertTrue($product->is_raw_material);
        $this->assertMatchesRegularExpression('/^RM-[A-Z0-9]{5}$/', $product->sku);
        $this->assertMatchesRegularExpression('/^RM-[A-Z0-9]{5}$/', $product->barcode);
        $this->assertSame(0, $product->sell_price);
        $this->assertSame(250, $product->buy_price);
        $this->assertSame(2500, $product->min_stock);
        $this->assertSame('central', $product->purchased_by);
        $this->assertSame('Bahan Baku', $product->category->name);

        $units = $product->units->keyBy('code');
        $this->assertTrue($units['GRAM']->pivot->is_base);
        $this->assertEquals(1, $units['GRAM']->pivot->conversion_factor);
        $this->assertTrue($units['KG']->pivot->is_purchase_default);
        $this->assertEquals(1000, $units['KG']->pivot->conversion_factor);
        $this->assertSame(250000, $units['KG']->pivot->buy_price);

        $this->assertSame(1, Category::where('name', 'Bahan Baku')->count());
    }

    public function test_purchased_by_and_stock_unit_are_required(): void
    {
        $this->actingAs($this->admin)
            ->post(route('raw-materials.store'), $this->payload(['purchased_by' => '', 'stock_unit_id' => '']))
            ->assertSessionHasErrors(['purchased_by', 'stock_unit_id']);
    }

    public function test_opening_stock_in_outlet_warehouse_is_converted_to_stock_unit(): void
    {
        $outlet = Outlet::create(['code' => 'OUT-A', 'name' => 'Outlet A', 'is_active' => true]);
        $warehouse = Warehouse::create(['code' => 'OUT-A-WH', 'name' => 'Gudang A', 'type' => 'branch', 'outlet_id' => $outlet->id, 'is_active' => true, 'sort_order' => 1]);

        $this->actingAs($this->admin)
            ->post(route('raw-materials.store'), $this->payload([
                'opening_warehouse_id' => $warehouse->id,
                'opening_qty' => 2,
                'opening_unit_id' => $this->kg->id,
            ]))
            ->assertRedirect(route('raw-materials.index'));

        $product = Product::where('title', 'Biji kopi')->firstOrFail();
        $this->assertSame(2000, $product->stock);
        $this->assertDatabaseHas('product_warehouse', ['product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'stock' => 2000]);
        $this->assertDatabaseHas('stock_mutations', [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'reference_type' => 'product_create',
            'qty' => 2000,
        ]);
    }

    public function test_opening_stock_unit_must_belong_to_the_material(): void
    {
        $other = Unit::where('code', 'LITER')->first() ?? Unit::where('id', '!=', $this->kg->id)->where('id', '!=', $this->gram->id)->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('raw-materials.store'), $this->payload([
                'opening_warehouse_id' => $this->pusat->id,
                'opening_qty' => 2,
                'opening_unit_id' => $other->id,
            ]))
            ->assertSessionHasErrors('opening_unit_id');
    }

    public function test_index_lists_only_raw_materials_with_stock_for_accessible_warehouses(): void
    {
        $outletA = Outlet::create(['code' => 'OUT-A', 'name' => 'Outlet A', 'is_active' => true]);
        $outletB = Outlet::create(['code' => 'OUT-B', 'name' => 'Outlet B', 'is_active' => true]);
        $whA = Warehouse::create(['code' => 'A-WH', 'name' => 'Gudang A', 'type' => 'branch', 'outlet_id' => $outletA->id, 'is_active' => true, 'sort_order' => 1]);
        $whB = Warehouse::create(['code' => 'B-WH', 'name' => 'Gudang B', 'type' => 'branch', 'outlet_id' => $outletB->id, 'is_active' => true, 'sort_order' => 2]);

        $material = $this->rawMaterial();
        $material->warehouses()->attach([$whA->id => ['stock' => 30], $whB->id => ['stock' => 70]]);
        $sellable = $this->rawMaterial('Kopi jadi');
        $sellable->update(['is_raw_material' => false]);

        $manager = User::factory()->create();
        $manager->assignRole('manager');
        $manager->markEmailAsVerified();
        $manager->outlets()->attach($outletA->id);

        $this->actingAs($manager)
            ->get(route('raw-materials.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/RawMaterials/Index')
                ->has('materials.data', 1)
                ->where('materials.data.0.title', 'Gula')
                ->where('materials.data.0.unit', 'GRAM')
                ->has('materials.data.0.stocks', 1)
                ->where('materials.data.0.stocks.0.warehouse_id', $whA->id)
                ->where('materials.data.0.stocks.0.stock', 30)
                ->where('materials.data.0.stocks.0.cost', 10)
            );
    }

    public function test_index_searches_by_name_or_sku(): void
    {
        $this->rawMaterial('Gula');
        $this->rawMaterial('Susu');

        $this->actingAs($this->admin)
            ->get(route('raw-materials.index', ['search' => 'RM-Susu']))
            ->assertInertia(fn (Assert $page) => $page->has('materials.data', 1)->where('materials.data.0.title', 'Susu'));
    }

    public function test_update_changes_fields_and_recomputes_reference_price(): void
    {
        $product = $this->rawMaterial();

        $this->actingAs($this->admin)
            ->put(route('raw-materials.update', $product), $this->payload(['title' => 'Gula halus', 'units' => [
                ['unit_id' => $this->kg->id, 'conversion_factor' => 1000, 'buy_price' => 15000, 'is_purchase_default' => true],
            ]]))
            ->assertRedirect(route('raw-materials.index'));

        $product->refresh();
        $this->assertSame('Gula halus', $product->title);
        $this->assertSame(15, $product->buy_price);
        $this->assertSame('RM-Gula', $product->sku);
    }

    public function test_update_rejects_changing_the_stock_unit(): void
    {
        $product = $this->rawMaterial();

        $this->actingAs($this->admin)
            ->put(route('raw-materials.update', $product), $this->payload(['stock_unit_id' => $this->kg->id, 'units' => []]))
            ->assertSessionHasErrors('stock_unit_id');
    }

    public function test_deleting_a_recipe_component_is_rejected(): void
    {
        $material = $this->rawMaterial();
        $recipe = Product::create([
            'category_id' => $material->category_id, 'barcode' => 'B1', 'sku' => 'S1', 'title' => 'Kopi susu',
            'buy_price' => 0, 'sell_price' => 20000, 'stock' => 0, 'tax_rate' => 0, 'is_composite' => true,
        ]);
        $recipe->components()->attach($material->id, ['qty' => 18]);

        $this->actingAs($this->admin)
            ->delete(route('raw-materials.destroy', $material))
            ->assertSessionHasErrors(['delete' => 'Bahan baku tidak bisa dihapus karena dipakai sebagai komponen resep.']);

        $this->assertModelExists($material);
    }

    public function test_deleting_a_material_with_other_history_is_rejected(): void
    {
        $material = $this->rawMaterial();
        $this->mutation($material, 'product_create');
        $this->mutation($material, 'goods_receiving');

        $this->actingAs($this->admin)
            ->delete(route('raw-materials.destroy', $material))
            ->assertSessionHasErrors('delete');

        $this->assertModelExists($material);
    }

    public function test_a_material_with_only_opening_stock_deletes(): void
    {
        $material = $this->rawMaterial();
        $this->mutation($material, 'product_create');

        $this->actingAs($this->admin)
            ->delete(route('raw-materials.destroy', $material))
            ->assertSessionHasNoErrors();

        $this->assertModelMissing($material);
    }

    public function test_cashier_cannot_open_the_screen(): void
    {
        $cashier = User::factory()->create();
        $cashier->assignRole('cashier');
        $cashier->markEmailAsVerified();

        $this->actingAs($cashier)->get(route('raw-materials.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('raw-materials.create'))->assertForbidden();
        $this->actingAs($cashier)->post(route('raw-materials.store'), $this->payload())->assertForbidden();
    }

    public function test_non_raw_material_product_is_not_found_on_this_screen(): void
    {
        $product = $this->rawMaterial();
        $product->update(['is_raw_material' => false]);

        $this->actingAs($this->admin)->get(route('raw-materials.edit', $product))->assertNotFound();
    }
}
