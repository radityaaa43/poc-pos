<?php

namespace Tests\Feature\Purchasing;

use App\Models\Category;
use App\Models\GoodsReceivingItem;
use App\Models\Payable;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SupplierReturnService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * REQ-002 AC-08: the payable of a PO received in several deliveries.
 */
class PartialReceiptPayableTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Warehouse $warehouse;

    private Product $product;

    private PurchaseOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->product = Product::create([
            'title' => 'Gula', 'sku' => 'SKU-1', 'barcode' => 'BC-1', 'buy_price' => 1000, 'sell_price' => 0,
            'stock' => 100, 'image' => 'products/test.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => Category::create(['name' => 'Bahan', 'image' => 'c.jpg', 'description' => 'x'])->id,
        ]);
        $this->product->warehouses()->attach($this->warehouse->id, ['stock' => 100]);

        // 10 units at Rp 1.000, placed and ready to receive.
        $this->order = PurchaseOrder::create([
            'document_number' => 'PO-TEST-1',
            'supplier_id' => Supplier::create(['name' => 'CV Gula'])->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'ordered',
            'created_by' => $this->admin->id,
        ]);
        $this->order->items()->create(['product_id' => $this->product->id, 'qty_ordered' => 10, 'qty_received' => 0, 'unit_price' => 1000]);
    }

    private function receive(int $qty): void
    {
        $this->actingAs($this->admin)->post(route('goods-receivings.store'), [
            'purchase_order_id' => $this->order->id,
            'items' => [['purchase_order_item_id' => $this->order->items()->value('id'), 'qty_received' => $qty]],
        ])->assertSessionHasNoErrors();
    }

    private function pay(float $amount): void
    {
        $this->actingAs($this->admin)->post(route('payables.pay', Payable::firstOrFail()), [
            'amount' => $amount, 'paid_at' => now()->toDateString(), 'method' => 'cash',
        ])->assertSessionHasNoErrors();
    }

    private function completeReturn(int $qty): void
    {
        $service = app(SupplierReturnService::class);
        $return = $service->createReturn([
            'supplier_id' => $this->order->supplier_id,
            'warehouse_id' => $this->warehouse->id,
            'payable_id' => Payable::firstOrFail()->id,
        ], [[
            'goods_receiving_item_id' => GoodsReceivingItem::value('id'),
            'product_id' => $this->product->id,
            'qty_returned' => $qty,
            'unit_price' => 1000,
        ]], $this->admin->id);
        $service->complete($return, $this->admin->id);
    }

    private function assertPayable(float $total, float $paid, string $status): void
    {
        $this->assertSame(1, Payable::count());
        $payable = Payable::firstOrFail();
        $this->assertSame($total, $payable->total);
        $this->assertSame($paid, $payable->paid);
        $this->assertSame($status, $payable->status);
    }

    public function test_second_receipt_adds_to_the_payable_and_keeps_the_payment(): void
    {
        $this->receive(4);
        $this->assertPayable(4000, 0, 'unpaid');

        $this->pay(1500);
        $this->receive(6);

        $this->assertPayable(10000, 1500, 'partial');
    }

    public function test_second_receipt_after_a_completed_return_keeps_the_deduction(): void
    {
        $this->receive(4);
        $this->completeReturn(1);
        $this->assertPayable(3000, 0, 'unpaid');

        $this->receive(6);

        $this->assertPayable(9000, 0, 'unpaid');
    }

    public function test_payable_is_paid_when_the_payment_covers_the_new_total(): void
    {
        $this->receive(5);
        $this->pay(5000);
        $this->completeReturn(2);
        $this->assertPayable(3000, 5000, 'paid');

        $this->receive(1); // total 4000, still below the 5000 paid

        $this->assertPayable(4000, 5000, 'paid');
    }

    public function test_api_second_receipt_keeps_the_payment(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        $body = fn (int $qty) => [
            'purchase_order_id' => $this->order->id,
            'items' => [['purchase_order_item_id' => $this->order->items()->value('id'), 'qty_received' => $qty]],
        ];

        $this->postJson('/api/v1/goods-receivings', $body(4))->assertCreated();
        Payable::firstOrFail()->update(['paid' => 1500, 'status' => 'partial']);
        $this->postJson('/api/v1/goods-receivings', $body(6))->assertCreated();

        $this->assertPayable(10000, 1500, 'partial');
    }
}
