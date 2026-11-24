<?php

namespace Tests\Feature\Purchasing;

use App\Models\Category;
use App\Models\GoodsReceivingItem;
use App\Models\Outlet;
use App\Models\Payable;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\PurchaseOrder;
use App\Models\StockMutation;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-002 AC-09, AC-10, AC-11: supplier returns in purchase units.
 */
class SupplierReturnPurchaseUnitsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $warehouse;

    private Unit $kg;

    private Product $beans;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0,
        ]);
        $gram = Unit::firstOrCreate(['code' => 'GRAM'], ['name' => 'Gram', 'symbol' => 'g']);
        $this->kg = Unit::firstOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'symbol' => 'kg']);

        // Raw material kept in grams, bought per kg at Rp 250.000.
        $this->beans = Product::create([
            'title' => 'Biji Kopi', 'sku' => 'SKU-BEANS', 'barcode' => 'BC-BEANS', 'buy_price' => 250, 'sell_price' => 0,
            'stock' => 1000, 'image' => 'products/test.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => Category::create(['name' => 'Bahan', 'image' => 'c.jpg', 'description' => 'x'])->id,
        ]);
        $this->beans->warehouses()->attach($this->warehouse->id, ['stock' => 1000]);
        $this->beans->units()->attach($gram->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 250, 'sell_price' => 0]);
        $this->beans->units()->attach($this->kg->id, ['conversion_factor' => 1000, 'buy_price' => 250000, 'sell_price' => 0, 'is_purchase_default' => true]);

        $this->supplier = Supplier::create(['name' => 'CV Kopi']);
    }

    /** Orders 5 kg and receives $qty kg through the web endpoints. */
    private function receiveKg(int $qty = 2): GoodsReceivingItem
    {
        $this->actingAs($this->admin);
        if (! PurchaseOrder::exists()) {
            $this->post(route('purchase-orders.store'), [
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'items' => [['product_id' => $this->beans->id, 'unit_id' => $this->kg->id, 'qty_ordered' => 5, 'unit_price' => 250000]],
            ])->assertSessionHasNoErrors();
            $this->post(route('purchase-orders.place', PurchaseOrder::firstOrFail()))->assertSessionHasNoErrors();
        }
        $order = PurchaseOrder::firstOrFail();
        $this->post(route('goods-receivings.store'), [
            'purchase_order_id' => $order->id,
            'items' => [['purchase_order_item_id' => $order->items->first()->id, 'qty_received' => $qty]],
        ])->assertSessionHasNoErrors();

        return GoodsReceivingItem::latest('id')->firstOrFail();
    }

    private function returnPayload(GoodsReceivingItem $line, int $qty = 1): array
    {
        return ['items' => [['product_id' => $this->beans->id, 'goods_receiving_item_id' => $line->id, 'qty_returned' => $qty, 'unit_price' => 250000]]];
    }

    private function warehouseRow(): ProductWarehouse
    {
        return ProductWarehouse::where(['product_id' => $this->beans->id, 'warehouse_id' => $this->warehouse->id])->firstOrFail();
    }

    private function assertKgReturnCompleted(SupplierReturn $return, GoodsReceivingItem $line, float $avgCostBefore): void
    {
        $this->assertSame($this->warehouse->id, $return->warehouse_id);
        $this->assertSame($line->goods_receiving_id, $return->goods_receiving_id);
        $this->assertSame(Payable::firstOrFail()->id, $return->payable_id);
        $this->assertDatabaseHas('supplier_return_items', [
            'supplier_return_id' => $return->id,
            'goods_receiving_item_id' => $line->id,
            'unit_id' => $this->kg->id,
            'qty_returned' => 1,
        ]);
        $this->assertSame(1000.0, $return->items->first()->conversion_factor);

        $this->assertSame('completed', $return->fresh()->status);
        $this->assertSame(2000, $this->warehouseRow()->stock); // 1.000 + 2 kg received - 1 kg returned
        $this->assertSame(2000, (int) $this->beans->fresh()->stock);
        $this->assertSame(1000, (int) StockMutation::where('reference_type', 'supplier_return')->value('qty'));
        $this->assertSame(250000.0, (float) Payable::firstOrFail()->total); // 2 kg x Rp 250.000 - 1 kg x Rp 250.000
        $this->assertSame($avgCostBefore, $this->warehouseRow()->avg_cost);
    }

    public function test_web_return_from_a_kg_receiving_line_keeps_unit_warehouse_and_payable(): void
    {
        $line = $this->receiveKg();
        $avgCost = $this->warehouseRow()->avg_cost;

        $this->post(route('supplier-returns.store'), $this->returnPayload($line))->assertSessionHasNoErrors();
        $return = SupplierReturn::with('items')->firstOrFail();
        $this->post(route('supplier-returns.complete', $return))->assertSessionHasNoErrors();

        $this->assertKgReturnCompleted($return, $line, $avgCost);
    }

    public function test_api_return_from_a_kg_receiving_line_keeps_unit_warehouse_and_payable(): void
    {
        $line = $this->receiveKg();
        $avgCost = $this->warehouseRow()->avg_cost;

        Sanctum::actingAs($this->admin, ['*']);
        $id = $this->postJson('/api/v1/supplier-returns', $this->returnPayload($line))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/supplier-returns/{$id}/complete")->assertOk();

        $this->assertKgReturnCompleted(SupplierReturn::with('items')->findOrFail($id), $line, $avgCost);
    }

    public function test_a_later_receipt_keeps_the_completed_return_off_the_payable(): void
    {
        $line = $this->receiveKg();
        $this->post(route('supplier-returns.store'), $this->returnPayload($line));
        $this->post(route('supplier-returns.complete', SupplierReturn::firstOrFail()));

        $this->receiveKg(1);

        $this->assertSame(500000.0, (float) Payable::firstOrFail()->total); // 3 kg received - 1 kg returned
    }

    public function test_the_warehouse_sent_with_a_manual_line_is_kept_and_its_stock_reduced(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('supplier-returns.store'), [
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->beans->id, 'qty_returned' => 300, 'unit_price' => 250]],
        ])->assertSessionHasNoErrors();
        $return = SupplierReturn::firstOrFail();
        $this->assertSame($this->warehouse->id, $return->warehouse_id);

        $this->post(route('supplier-returns.complete', $return))->assertSessionHasNoErrors();
        $this->assertSame(700, $this->warehouseRow()->stock);
    }

    public function test_a_manual_line_in_kg_lowers_stock_in_grams(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('supplier-returns.store'), [
            'warehouse_id' => $this->warehouse->id,
            'items' => [['product_id' => $this->beans->id, 'unit_id' => $this->kg->id, 'conversion_factor' => 1, 'qty_returned' => 1, 'unit_price' => 250000]],
        ])->assertSessionHasNoErrors();
        $return = SupplierReturn::firstOrFail();
        $this->assertSame(1000.0, $return->items->first()->conversion_factor);

        $this->post(route('supplier-returns.complete', $return))->assertSessionHasNoErrors();
        $this->assertSame(0, $this->warehouseRow()->stock);
    }

    public function test_a_manual_line_rejects_a_unit_of_another_product(): void
    {
        $this->actingAs($this->admin);
        $box = Unit::firstOrCreate(['code' => 'BOX'], ['name' => 'Box', 'symbol' => 'box']);

        $this->post(route('supplier-returns.store'), [
            'items' => [['product_id' => $this->beans->id, 'unit_id' => $box->id, 'qty_returned' => 1, 'unit_price' => 1]],
        ])->assertSessionHasErrors('items.0.unit_id');
        $this->assertSame(0, SupplierReturn::count());
    }

    public function test_a_warehouse_the_user_cannot_access_is_rejected(): void
    {
        $line = $this->receiveKg();
        $outlet = Outlet::create(['code' => 'OUT-A', 'name' => 'Outlet A']);
        $this->warehouse->update(['outlet_id' => Outlet::create(['code' => 'OUT-B', 'name' => 'Outlet B'])->id]);
        $user = User::factory()->create();
        $user->givePermissionTo(['supplier-returns-access', 'supplier-returns-create']);
        $user->outlets()->attach($outlet->id);

        $this->actingAs($user)
            ->post(route('supplier-returns.store'), $this->returnPayload($line) + ['warehouse_id' => $this->warehouse->id])
            ->assertForbidden();
        // The same goes for a warehouse that only the receiving points to.
        $this->post(route('supplier-returns.store'), $this->returnPayload($line))->assertForbidden();
        $this->assertSame(0, SupplierReturn::count());
    }

    public function test_return_is_rejected_beyond_what_is_left_to_return(): void
    {
        $line = $this->receiveKg();

        $this->post(route('supplier-returns.store'), $this->returnPayload($line))->assertSessionHasNoErrors();
        // 1 kg of 2 kg is already on a (draft) return: 2 kg more is too much, 1 kg is fine.
        $this->post(route('supplier-returns.store'), $this->returnPayload($line, 2))->assertSessionHasErrors('items.0.qty_returned');
        $this->assertSame(1, SupplierReturn::count());
        $this->post(route('supplier-returns.store'), $this->returnPayload($line, 1))->assertSessionHasNoErrors();
        $this->post(route('supplier-returns.store'), $this->returnPayload($line, 1))->assertSessionHasErrors('items.0.qty_returned');
    }

    public function test_a_cancelled_return_frees_its_quantity(): void
    {
        $line = $this->receiveKg();
        $this->post(route('supplier-returns.store'), $this->returnPayload($line, 2))->assertSessionHasNoErrors();
        $this->post(route('supplier-returns.cancel', SupplierReturn::firstOrFail()));

        $this->post(route('supplier-returns.store'), $this->returnPayload($line, 2))->assertSessionHasNoErrors();
    }

    public function test_two_lines_of_one_receiving_line_count_together(): void
    {
        $line = $this->receiveKg();
        $item = $this->returnPayload($line, 2)['items'][0];

        $this->post(route('supplier-returns.store'), ['items' => [$item, $item]])->assertSessionHasErrors('items.1.qty_returned');
        $this->assertSame(0, SupplierReturn::count());
    }

    public function test_api_rejects_returning_more_than_received(): void
    {
        $line = $this->receiveKg();

        Sanctum::actingAs($this->admin, ['*']);
        $this->postJson('/api/v1/supplier-returns', $this->returnPayload($line, 3))->assertStatus(422)->assertJsonValidationErrors('items.0.qty_returned');
    }

    public function test_choosing_a_supplier_loads_its_receivings_with_unit_and_returnable_quantity(): void
    {
        $line = $this->receiveKg();
        $this->post(route('supplier-returns.store'), $this->returnPayload($line));
        Supplier::create(['name' => 'Lain']);

        // A partial reload of goodsReceivings only, as the page does when the supplier select changes.
        $this->get(route('supplier-returns.create', ['supplier_id' => $this->supplier->id]))
            ->assertInertia(fn ($page) => $page->reloadOnly('goodsReceivings', fn ($reload) => $reload
                ->has('goodsReceivings', 1)
                ->where('goodsReceivings.0.items.0.unit.symbol', 'kg')
                ->where('goodsReceivings.0.items.0.qty_returnable', 1)
                ->missing('suppliers')));

        $this->get(route('supplier-returns.create', ['supplier_id' => Supplier::where('name', 'Lain')->value('id')]))
            ->assertInertia(fn ($page) => $page->has('goodsReceivings', 0));
    }
}
