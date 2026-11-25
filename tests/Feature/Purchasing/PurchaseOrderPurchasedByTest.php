<?php

namespace Tests\Feature\Purchasing;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-002 follow-up (#78): an outlet cannot order a raw material that central buys.
 */
class PurchaseOrderPurchasedByTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $central;

    private Warehouse $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();

        $this->central = $this->warehouse('PUSAT', isSalesEnabled: false);
        $this->outlet = $this->warehouse('CAFE', isSalesEnabled: true);
    }

    private function warehouse(string $code, bool $isSalesEnabled): Warehouse
    {
        $outlet = Outlet::create(['code' => $code, 'name' => $code, 'is_active' => true, 'is_sales_enabled' => $isSalesEnabled]);

        return Warehouse::create(['code' => $code.'-WH', 'name' => $code, 'type' => 'branch', 'outlet_id' => $outlet->id, 'is_active' => true, 'sort_order' => 1]);
    }

    private function product(string $title, ?string $purchasedBy): Product
    {
        return Product::create([
            'title' => $title,
            'sku' => 'SKU-'.uniqid(),
            'barcode' => 'BC-'.uniqid(),
            'buy_price' => 250,
            'sell_price' => 0,
            'stock' => 0,
            'image' => 'products/test.jpg',
            'description' => 'x',
            'tax_rate' => 0,
            'purchased_by' => $purchasedBy,
            'category_id' => Category::firstOrCreate(['name' => 'Bahan'], ['image' => 'c.jpg', 'description' => 'x'])->id,
        ]);
    }

    private function payload(?Warehouse $warehouse, Product ...$products): array
    {
        return [
            'warehouse_id' => $warehouse?->id,
            'items' => array_map(fn (Product $p) => ['product_id' => $p->id, 'qty_ordered' => 2, 'unit_price' => 250], $products),
        ];
    }

    public function test_warehouse_knows_whether_it_is_central(): void
    {
        $legacy = Warehouse::create(['code' => 'OLD', 'name' => 'Gudang Lama', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);

        $this->assertTrue($this->central->isCentral());
        $this->assertFalse($this->outlet->isCentral());
        $this->assertFalse($legacy->isCentral());
        $this->assertSame([$this->central->id], Warehouse::central()->pluck('id')->all());
    }

    public function test_web_rejects_central_bought_material_for_an_outlet_warehouse(): void
    {
        $plain = $this->product('Gelas', null);
        $beans = $this->product('Biji Kopi', 'central');

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload($this->outlet, $plain, $beans))
            ->assertSessionHasErrors(['items.1.product_id' => 'Biji Kopi dibeli oleh pusat; outlet tidak bisa memesannya.']);

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_api_rejects_central_bought_material_for_an_outlet_warehouse(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $beans = $this->product('Biji Kopi', 'central');

        $this->postJson('/api/v1/purchase-orders', $this->payload($this->outlet, $beans))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.product_id']);

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_warehouse_without_an_outlet_is_not_central(): void
    {
        $legacy = Warehouse::create(['code' => 'OLD', 'name' => 'Gudang Lama', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);

        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload($legacy, $this->product('Biji Kopi', 'central')))
            ->assertSessionHasErrors('items.0.product_id');
    }

    public function test_central_warehouse_may_order_central_and_outlet_bought_materials(): void
    {
        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload($this->central, $this->product('Biji Kopi', 'central'), $this->product('Susu', 'outlet')))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PurchaseOrder::firstOrFail()->items()->count());
    }

    public function test_outlet_warehouse_may_order_outlet_bought_and_unset_materials(): void
    {
        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload($this->outlet, $this->product('Susu', 'outlet'), $this->product('Gelas', null)))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, PurchaseOrder::firstOrFail()->items()->count());
    }

    public function test_order_without_a_warehouse_is_not_restricted(): void
    {
        $this->actingAs($this->admin)
            ->post(route('purchase-orders.store'), $this->payload(null, $this->product('Biji Kopi', 'central')))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PurchaseOrder::count());
    }
}
