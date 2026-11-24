<?php

namespace Tests\Feature\Purchasing;

use App\Models\Category;
use App\Models\GoodsReceivingItem;
use App\Models\Payable;
use App\Models\Product;
use App\Models\ProductBatch;
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
 * REQ-002 AC-04, AC-05, AC-19: PO and goods receiving lines in purchase units.
 */
class PurchaseUnitsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $warehouse;

    private Unit $gram;

    private Unit $kg;

    private Product $beans;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->gram = Unit::firstOrCreate(['code' => 'GRAM'], ['name' => 'Gram', 'symbol' => 'g']);
        $this->kg = Unit::firstOrCreate(['code' => 'KG'], ['name' => 'Kilogram', 'symbol' => 'kg']);

        // Raw material kept in grams, bought per kg at Rp 250.000.
        $this->beans = $this->product('Biji Kopi', 250);
        $this->beans->units()->attach($this->gram->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 250, 'sell_price' => 0]);
        $this->beans->units()->attach($this->kg->id, ['conversion_factor' => 1000, 'buy_price' => 250000, 'sell_price' => 0, 'is_purchase_default' => true]);
    }

    private function product(string $title, int $buyPrice, int $stock = 1000): Product
    {
        $product = Product::create([
            'title' => $title,
            'sku' => 'SKU-'.uniqid(),
            'barcode' => 'BC-'.uniqid(),
            'buy_price' => $buyPrice,
            'sell_price' => 0,
            'stock' => $stock,
            'image' => 'products/test.jpg',
            'description' => 'x',
            'tax_rate' => 0,
            'category_id' => Category::firstOrCreate(['name' => 'Bahan'], ['image' => 'c.jpg', 'description' => 'x'])->id,
        ]);
        $product->warehouses()->attach($this->warehouse->id, ['stock' => $stock]);

        return $product;
    }

    private function poPayload(array $item): array
    {
        return [
            'warehouse_id' => $this->warehouse->id,
            'items' => [array_merge(['product_id' => $this->beans->id, 'qty_ordered' => 5, 'unit_price' => 250000], $item)],
        ];
    }

    /** Creates and places a 5 kg PO through the web endpoints. */
    private function placedKgOrder(): PurchaseOrder
    {
        $this->actingAs($this->admin);
        $this->post(route('purchase-orders.store'), $this->poPayload(['unit_id' => $this->kg->id]) + ['supplier_id' => Supplier::create(['name' => 'CV Kopi'])->id])->assertSessionHasNoErrors();
        $order = PurchaseOrder::firstOrFail();
        $this->post(route('purchase-orders.place', $order))->assertSessionHasNoErrors();

        return $order->fresh();
    }

    public function test_po_line_stores_unit_and_server_side_factor(): void
    {
        $this->actingAs($this->admin);

        // A conversion_factor sent by the client must be ignored.
        $this->post(route('purchase-orders.store'), $this->poPayload(['unit_id' => $this->kg->id, 'conversion_factor' => 1]))
            ->assertSessionHasNoErrors();

        $item = PurchaseOrder::firstOrFail()->items()->firstOrFail();
        $this->assertSame($this->kg->id, $item->unit_id);
        $this->assertSame(1000.0, $item->conversion_factor);
        $this->assertSame(250000.0, $item->unit_price);
    }

    public function test_po_line_without_unit_stays_in_stock_unit(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('purchase-orders.store'), $this->poPayload(['unit_price' => 250]))->assertSessionHasNoErrors();

        $item = PurchaseOrder::firstOrFail()->items()->firstOrFail();
        $this->assertNull($item->unit_id);
        $this->assertSame(1.0, $item->conversion_factor);
    }

    public function test_po_rejects_a_unit_of_another_product(): void
    {
        $this->actingAs($this->admin);
        $other = $this->product('Gula', 20);
        $other->units()->attach($this->gram->id, ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 20, 'sell_price' => 0]);

        // PCS-like unit that only the other product has.
        $box = Unit::firstOrCreate(['code' => 'BOX'], ['name' => 'Box', 'symbol' => 'box']);
        $other->units()->attach($box->id, ['conversion_factor' => 10, 'buy_price' => 200, 'sell_price' => 0]);

        $this->post(route('purchase-orders.store'), $this->poPayload(['unit_id' => $box->id]))
            ->assertSessionHasErrors('items.0.unit_id');

        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_receiving_part_of_a_kg_po_adds_grams(): void
    {
        $order = $this->placedKgOrder();
        $poItem = $order->items->first();

        $this->post(route('goods-receivings.store'), [
            'purchase_order_id' => $order->id,
            'items' => [['purchase_order_item_id' => $poItem->id, 'qty_received' => 2, 'batch_number' => 'B-1']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(3000, (int) $this->beans->fresh()->stock);
        $this->assertSame(3000, (int) ProductWarehouse::where('product_id', $this->beans->id)->value('stock'));

        $mutation = StockMutation::where('reference_type', 'goods_receiving')->firstOrFail();
        $this->assertSame(2000, (int) $mutation->qty);
        $this->assertSame(1000, (int) $mutation->stock_before);
        $this->assertSame(3000, (int) $mutation->stock_after);
        $this->assertStringContainsString('Penerimaan 2 kg dari PO '.$order->document_number, $mutation->notes);

        $this->assertSame(2000, (int) ProductBatch::where('product_id', $this->beans->id)->value('stock'));

        $poItem->refresh();
        $this->assertSame(2, $poItem->qty_received);
        $this->assertSame('partial_received', $order->fresh()->status);

        $this->assertDatabaseHas('goods_receiving_items', [
            'purchase_order_item_id' => $poItem->id,
            'unit_id' => $this->kg->id,
            'qty_received' => 2,
        ]);
        $this->assertSame(1000.0, GoodsReceivingItem::firstOrFail()->conversion_factor);
        $this->assertSame(500000.0, Payable::firstOrFail()->total); // 2 kg x Rp 250.000, not 2000 g x 250.000
    }

    public function test_receiving_rejects_a_non_whole_stock_quantity(): void
    {
        $half = Unit::firstOrCreate(['code' => 'HALF'], ['name' => 'Setengah', 'symbol' => 'hf']);
        $this->beans->units()->attach($half->id, ['conversion_factor' => 0.5, 'buy_price' => 125, 'sell_price' => 0]);

        $this->actingAs($this->admin);
        $this->post(route('purchase-orders.store'), $this->poPayload(['unit_id' => $half->id]))->assertSessionHasNoErrors();
        $order = PurchaseOrder::firstOrFail();
        $this->post(route('purchase-orders.place', $order));

        // 3 x 0.5 = 1.5 g is not a whole number of stock units.
        $this->post(route('goods-receivings.store'), [
            'purchase_order_id' => $order->id,
            'items' => [['purchase_order_item_id' => $order->items->first()->id, 'qty_received' => 3]],
        ])->assertSessionHasErrors('items');

        $this->assertSame(1000, (int) $this->beans->fresh()->stock);
        $this->assertDatabaseCount('goods_receivings', 0);
    }

    public function test_receiving_without_unit_keeps_the_old_note_and_quantity(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('purchase-orders.store'), $this->poPayload(['unit_price' => 250]))->assertSessionHasNoErrors();
        $order = PurchaseOrder::firstOrFail();
        $this->post(route('purchase-orders.place', $order));

        $this->post(route('goods-receivings.store'), [
            'purchase_order_id' => $order->id,
            'items' => [['purchase_order_item_id' => $order->items->first()->id, 'qty_received' => 4]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1004, (int) $this->beans->fresh()->stock);
        $this->assertSame('Penerimaan dari PO '.$order->document_number, StockMutation::where('reference_type', 'goods_receiving')->value('notes'));
    }

    public function test_api_po_and_receiving_in_kg(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $id = $this->postJson('/api/v1/purchase-orders', $this->poPayload(['unit_id' => $this->kg->id]))
            ->assertCreated()
            ->assertJsonPath('data.items.0.conversion_factor', 1000)
            ->json('data.id');
        $this->postJson("/api/v1/purchase-orders/{$id}/place")->assertOk();

        $poItemId = PurchaseOrder::findOrFail($id)->items()->value('id');
        $this->postJson('/api/v1/goods-receivings', [
            'purchase_order_id' => $id,
            'items' => [['purchase_order_item_id' => $poItemId, 'qty_received' => 2]],
        ])->assertCreated();

        $this->assertSame(3000, (int) $this->beans->fresh()->stock);
        $this->assertSame(3000, (int) ProductWarehouse::where('product_id', $this->beans->id)->value('stock'));
        $this->assertSame(2000, (int) StockMutation::where('reference_type', 'goods_receiving')->value('qty'));
        $this->assertSame(2, PurchaseOrder::findOrFail($id)->items()->first()->qty_received);
    }

    public function test_api_receiving_rejects_a_non_whole_stock_quantity(): void
    {
        $half = Unit::firstOrCreate(['code' => 'HALF'], ['name' => 'Setengah', 'symbol' => 'hf']);
        $this->beans->units()->attach($half->id, ['conversion_factor' => 0.5, 'buy_price' => 125, 'sell_price' => 0]);

        Sanctum::actingAs($this->admin, ['*']);
        $id = $this->postJson('/api/v1/purchase-orders', $this->poPayload(['unit_id' => $half->id]))->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-orders/{$id}/place")->assertOk();

        $this->postJson('/api/v1/goods-receivings', [
            'purchase_order_id' => $id,
            'items' => [['purchase_order_item_id' => PurchaseOrder::findOrFail($id)->items()->value('id'), 'qty_received' => 3]],
        ])->assertStatus(422);

        $this->assertSame(1000, (int) $this->beans->fresh()->stock);
        $this->assertDatabaseCount('goods_receivings', 0);
    }

    public function test_supplier_return_rejects_a_receiving_item_in_a_purchase_unit(): void
    {
        $order = $this->placedKgOrder();
        $this->post(route('goods-receivings.store'), [
            'purchase_order_id' => $order->id,
            'items' => [['purchase_order_item_id' => $order->items->first()->id, 'qty_received' => 2]],
        ])->assertSessionHasNoErrors();

        // Supplier returns count stock units; a kg line would cut the payable by qty x kg price. See #29.
        $this->post(route('supplier-returns.store'), [
            'items' => [['product_id' => $this->beans->id, 'goods_receiving_item_id' => GoodsReceivingItem::value('id'), 'qty_returned' => 1, 'unit_price' => 250000]],
        ])->assertSessionHasErrors('items.0.goods_receiving_item_id');

        $this->assertSame(0, SupplierReturn::count());
    }

    public function test_supplier_return_still_works_for_a_receiving_item_in_the_stock_unit(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('purchase-orders.store'), $this->poPayload(['unit_price' => 250]))->assertSessionHasNoErrors();
        $order = PurchaseOrder::firstOrFail();
        $this->post(route('purchase-orders.place', $order));
        $this->post(route('goods-receivings.store'), [
            'purchase_order_id' => $order->id,
            'items' => [['purchase_order_item_id' => $order->items->first()->id, 'qty_received' => 4]],
        ])->assertSessionHasNoErrors();

        $this->post(route('supplier-returns.store'), [
            'items' => [['product_id' => $this->beans->id, 'goods_receiving_item_id' => GoodsReceivingItem::value('id'), 'qty_returned' => 1, 'unit_price' => 250]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, SupplierReturn::count());
    }

    public function test_api_po_rejects_a_unit_of_another_product(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $other = $this->product('Gula', 20);
        $box = Unit::firstOrCreate(['code' => 'BOX'], ['name' => 'Box', 'symbol' => 'box']);
        $other->units()->attach($box->id, ['conversion_factor' => 10, 'buy_price' => 200, 'sell_price' => 0]);

        $this->postJson('/api/v1/purchase-orders', $this->poPayload(['unit_id' => $box->id]))
            ->assertStatus(422);

        $this->assertDatabaseCount('purchase_orders', 0);
    }
}
