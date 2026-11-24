<?php

namespace Tests\Feature\Products;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReorderPointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->actingAs(User::where('email', 'arya@gmail.com')->first());

        $this->category = Category::create([
            'name' => 'Kategori Test',
            'image' => 'categories/test.jpg',
            'description' => 'Kategori untuk test',
        ]);

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT',
            'name' => 'Gudang Pusat',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private static int $seq = 0;

    private function createProduct(array $overrides = []): Product
    {
        self::$seq++;

        $product = Product::create(array_merge([
            'title' => 'Produk '.self::$seq,
            'sku' => 'SKU-'.self::$seq,
            'buy_price' => 10000,
            'sell_price' => 15000,
            'stock' => 100,
            'image' => 'products/test.jpg',
            'barcode' => 'BC-'.self::$seq,
            'description' => 'Deskripsi produk test',
            'tax_rate' => 0,
            'category_id' => $this->category->id,
        ], $overrides));

        // suggestedOrderQty() reads stock from the product_warehouse pivot
        $product->warehouses()->attach($this->warehouse->id, ['stock' => $product->stock]);

        return $product;
    }

    public function test_generate_creates_draft_po_with_suggested_qty(): void
    {
        $this->createProduct(['min_stock' => 50, 'max_stock' => 200, 'stock' => 40]);

        $this->artisan('reorder:generate')->assertSuccessful();

        $order = PurchaseOrder::latest('id')->first();
        $this->assertNotNull($order);
        $this->assertEquals('draft', $order->status);
        $item = $order->items->first();
        $this->assertEquals(160, $item->qty_ordered); // max 200 - stock 40
        $this->assertEquals(10000, $item->unit_price);
    }

    public function test_generate_skips_low_stock_without_max(): void
    {
        $this->createProduct(['min_stock' => 50, 'stock' => 10]);

        $this->artisan('reorder:generate')
            ->expectsOutput('Low-stock products found, but no reorder quantity to suggest.')
            ->assertSuccessful();

        $this->assertNull(PurchaseOrder::latest('id')->first());
    }

    public function test_generate_does_nothing_when_healthy(): void
    {
        $this->createProduct(['min_stock' => 10, 'max_stock' => 200, 'stock' => 100]);

        $this->artisan('reorder:generate')
            ->expectsOutput('No low-stock products.')
            ->assertSuccessful();

        $this->assertNull(PurchaseOrder::latest('id')->first());
    }

    public function test_generate_creates_per_warehouse_po_using_pivot_stock(): void
    {
        $otherWarehouse = Warehouse::create([
            'code' => 'CABANG-PB',
            'name' => 'Gudang Cabang PB',
            'type' => 'branch',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $product = $this->createProduct(['min_stock' => 50, 'max_stock' => 200, 'stock' => 0]);
        // override pivot stock: low in PUSAT, healthy in CABANG
        $product->warehouses()->sync([
            $this->warehouse->id => ['stock' => 40],
            $otherWarehouse->id => ['stock' => 300],
        ]);

        $this->artisan('reorder:generate')->assertSuccessful();

        $orders = PurchaseOrder::with('items')->get();
        $this->assertCount(1, $orders);

        $order = $orders->first();
        $this->assertEquals($this->warehouse->id, $order->warehouse_id);
        $this->assertEquals(160, $order->items->first()->qty_ordered); // max 200 - pivot 40
    }

    /** A warehouse of a central (non-sales) or sales outlet, so reorder scoping can tell them apart. */
    private function outletWarehouse(string $code, bool $isSalesEnabled): Warehouse
    {
        $outlet = Outlet::create(['code' => $code, 'name' => $code, 'is_active' => true, 'is_sales_enabled' => $isSalesEnabled]);

        return Warehouse::create(['code' => $code.'-WH', 'name' => $code, 'type' => 'branch', 'outlet_id' => $outlet->id, 'is_active' => true, 'sort_order' => 2]);
    }

    /** Raw material in grams (stock 700 g, max 3.000 g) bought per kg, low in the given warehouse. */
    private function rawMaterial(Warehouse $warehouse, string $purchasedBy, int $kgPrice = 250000): Product
    {
        $gram = Unit::firstOrCreate(['code' => 'GRAM'], ['name' => 'Gram', 'symbol' => 'g']);
        $kg = Unit::firstOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'symbol' => 'kg']);

        $product = $this->createProduct(['min_stock' => 1000, 'max_stock' => 3000, 'buy_price' => 250, 'purchased_by' => $purchasedBy]);
        $product->warehouses()->sync([$warehouse->id => ['stock' => 700]]);
        $product->units()->attach($gram->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 250, 'sell_price' => 0]);
        $product->units()->attach($kg->id, ['conversion_factor' => 1000, 'buy_price' => $kgPrice, 'sell_price' => 0, 'is_purchase_default' => true]);

        return $product;
    }

    public function test_generate_scopes_raw_materials_by_purchased_by(): void
    {
        $central = $this->outletWarehouse('PUSAT-OUT', false);
        $outlet = $this->outletWarehouse('CAFE', true);

        $centralBought = $this->rawMaterial($central, 'central');
        $outletBought = $this->rawMaterial($outlet, 'outlet');
        // Each raw material is low in both warehouses; only the matching one may be ordered.
        $centralBought->warehouses()->sync([$central->id => ['stock' => 700], $outlet->id => ['stock' => 700]]);
        $outletBought->warehouses()->sync([$central->id => ['stock' => 700], $outlet->id => ['stock' => 700]]);
        $plain = $this->createProduct(['min_stock' => 50, 'max_stock' => 200, 'stock' => 40]);
        $plain->warehouses()->sync([$central->id => ['stock' => 40], $outlet->id => ['stock' => 40]]);

        $this->artisan('reorder:generate')->assertSuccessful();

        $productIds = fn (Warehouse $w) => PurchaseOrder::where('warehouse_id', $w->id)->firstOrFail()
            ->items->pluck('product_id')->sort()->values()->all();
        $this->assertSame([$centralBought->id, $plain->id], $productIds($central));
        $this->assertSame([$outletBought->id, $plain->id], $productIds($outlet));
    }

    public function test_generate_converts_need_to_default_purchase_unit_rounded_up(): void
    {
        $central = $this->outletWarehouse('PUSAT-OUT', false);
        $beans = $this->rawMaterial($central, 'central');

        $this->artisan('reorder:generate')->assertSuccessful();

        $item = PurchaseOrder::where('warehouse_id', $central->id)->firstOrFail()->items->first();
        $this->assertSame($beans->units()->where('code', 'KG')->value('units.id'), $item->unit_id);
        $this->assertEquals(1000, $item->conversion_factor);
        $this->assertEquals(3, $item->qty_ordered); // 3.000 - 700 = 2.300 g -> 3 kg
        $this->assertEquals(250000, $item->unit_price);
    }

    public function test_generate_prices_purchase_unit_from_product_buy_price_when_unit_price_is_unset(): void
    {
        $central = $this->outletWarehouse('PUSAT-OUT', false);
        $this->rawMaterial($central, 'central', kgPrice: 0);

        $this->artisan('reorder:generate')->assertSuccessful();

        $this->assertEquals(250000, PurchaseOrder::where('warehouse_id', $central->id)->firstOrFail()->items->first()->unit_price); // 250 x 1000
    }

    public function test_generate_keeps_stock_unit_when_material_has_no_purchase_unit(): void
    {
        $central = $this->outletWarehouse('PUSAT-OUT', false);
        $beans = $this->rawMaterial($central, 'central');
        $beans->units()->detach();

        $this->artisan('reorder:generate')->assertSuccessful();

        $item = PurchaseOrder::where('warehouse_id', $central->id)->firstOrFail()->items->first();
        $this->assertNull($item->unit_id);
        $this->assertEquals(2300, $item->qty_ordered);
        $this->assertEquals(250, $item->unit_price);
    }
}
