<?php

namespace Tests\Feature\Console;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RawMaterialDemoSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoRawMaterialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->artisan('seed:demo', ['--force' => true])->assertSuccessful();
    }

    public function test_demo_raw_materials_have_purchase_setup_and_costed_opening_stock(): void
    {
        $materials = Product::where('is_raw_material', true)->with(['units', 'warehouses'])->get();

        $this->assertCount(12, $materials);
        $this->assertSame(['central', 'outlet'], $materials->pluck('purchased_by')->unique()->sort()->values()->all());
        $this->assertEqualsCanonicalizing(
            ['Air Mineral', 'Gula Aren Cair', 'Air Jeruk Nipis', 'Es Batu'],
            $this->materialTitles('outlet')
        );

        foreach ($materials as $material) {
            $base = $material->units->filter(fn ($u) => $u->pivot->is_base);
            $default = $material->units->filter(fn ($u) => $u->pivot->is_purchase_default);

            $this->assertContains($material->purchased_by, ['central', 'outlet'], $material->title);
            $this->assertCount(1, $base, $material->title);
            $this->assertEquals(1, $base->first()->pivot->conversion_factor, $material->title);
            $this->assertCount(1, $default, $material->title);
            $this->assertGreaterThan(0, $default->first()->pivot->buy_price, $material->title);
            $this->assertGreaterThan(0, $material->min_stock, $material->title);

            $this->assertCount(4, $material->warehouses, $material->title);
            foreach ($material->warehouses as $warehouse) {
                $this->assertNotNull($warehouse->pivot->avg_cost, $material->title);
                $this->assertGreaterThan(0, $warehouse->pivot->stock, $material->title);
                $this->assertDatabaseHas('stock_mutations', [
                    'product_id' => $material->id,
                    'warehouse_id' => $warehouse->id,
                    'reference_type' => 'product_create',
                    'qty' => $warehouse->pivot->stock,
                ]);
            }
            $this->assertSame((int) $material->warehouses->sum('pivot.stock'), (int) $material->stock, $material->title);
        }
    }

    public function test_demo_recipes_are_composites_of_existing_raw_materials(): void
    {
        $recipes = Product::where('is_composite', true)->where('barcode', 'like', 'MNR-%')->with('components')->get();

        $this->assertGreaterThanOrEqual(3, $recipes->count());
        $this->assertLessThanOrEqual(5, $recipes->count());
        $this->assertTrue($recipes->contains('title', 'Americano'));

        foreach ($recipes as $recipe) {
            $this->assertGreaterThan(0, $recipe->sell_price, $recipe->title);
            $this->assertNotEmpty($recipe->components, $recipe->title);
            $this->assertTrue($recipe->components->every(fn ($c) => $c->is_raw_material), $recipe->title);
        }

        $americano = $recipes->firstWhere('title', 'Americano');
        $this->assertEquals(
            ['Biji Kopi' => 18, 'Air Mineral' => 200, 'Cup 12oz' => 1],
            $americano->components->mapWithKeys(fn ($c) => [$c->title => (float) $c->pivot->qty])->all()
        );
    }

    public function test_reorder_drafts_split_between_central_and_outlet_materials(): void
    {
        $this->artisan('reorder:generate')->assertSuccessful();

        $titlesAt = fn (string $code) => PurchaseOrder::where('warehouse_id', Warehouse::where('code', $code)->value('id'))
            ->with('items.product')->get()->flatMap->items->pluck('product.title')->sort()->values()->all();

        $this->assertSame(['Bubuk Matcha'], array_values(array_intersect($titlesAt('PUSAT'), $this->materialTitles('central'))));
        $this->assertSame([], array_intersect($titlesAt('PUSAT'), $this->materialTitles('outlet')));
        $this->assertSame(['Air Jeruk Nipis'], array_values(array_intersect($titlesAt('WH-MAL'), $this->materialTitles('outlet'))));
        $this->assertSame([], array_intersect($titlesAt('WH-MAL'), $this->materialTitles('central')));
    }

    public function test_running_the_seeder_again_does_not_duplicate_or_restock(): void
    {
        $stock = Product::where('barcode', 'RM-0001')->value('stock');

        $this->seed(RawMaterialDemoSeeder::class);

        $this->assertSame(12, Product::where('is_raw_material', true)->count());
        $this->assertSame(1, Product::where('title', 'Americano')->count());
        $this->assertSame($stock, Product::where('barcode', 'RM-0001')->value('stock'));
        $this->assertSame(48, DB::table('stock_mutations')->where('reference_type', 'product_create')->whereIn('product_id', Product::where('is_raw_material', true)->pluck('id'))->count());
    }

    private function materialTitles(string $purchasedBy): array
    {
        return Product::where('is_raw_material', true)->where('purchased_by', $purchasedBy)->pluck('title')->all();
    }
}
