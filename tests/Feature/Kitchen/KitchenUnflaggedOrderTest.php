<?php

namespace Tests\Feature\Kitchen;

use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\KitchenIntakeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// BR-1 (#237, Pay now): an order with no flagged line needs no kitchen step; it is served when paid, then completed.
class KitchenUnflaggedOrderTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Category $category;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        $outlet = Outlet::create(['code' => 'OUT-A', 'name' => 'Outlet A', 'is_active' => true, 'is_sales_enabled' => true]);
        $this->warehouse = Warehouse::create([
            'outlet_id' => $outlet->id, 'code' => 'WH-A', 'name' => 'Gudang A', 'type' => 'branch', 'is_active' => true,
        ]);
    }

    public function test_dine_order_with_only_unflagged_items_is_served_when_paid(): void
    {
        $sale = $this->openBill([false, false]);

        $sale->update(['payment_status' => 'paid']);

        $order = $sale->dineOrder->fresh();
        $this->assertSame(0, KitchenItem::count());
        $this->assertSame(DineOrder::STATUS_COMPLETED, $order->status, 'served at payment, then completed because it is paid');
        $this->assertNotNull($order->served_at);
        $this->assertNull($order->served_by, 'the system served it');
        $this->assertNotNull($order->completed_at);
    }

    public function test_unpaid_bill_is_not_served(): void
    {
        $sale = $this->openBill([false]);

        app(KitchenIntakeService::class)->intake($sale);

        $this->assertSame(DineOrder::STATUS_ACCEPTED, $sale->dineOrder->fresh()->status);
    }

    public function test_pay_later_unpaid_order_with_no_kitchen_items_is_not_served_yet(): void
    {
        $sale = $this->openBill([false]);
        $sale->update(['payment_method' => 'pay_later']);

        app(KitchenIntakeService::class)->intakeUnpaid($sale->fresh());

        $this->assertSame(DineOrder::STATUS_ACCEPTED, $sale->dineOrder->fresh()->status, 'the Pay-later half is #279');
    }

    public function test_mixed_order_waits_only_for_flagged_items(): void
    {
        $sale = $this->openBill([true, false]);

        $sale->update(['payment_status' => 'paid']);

        $order = $sale->dineOrder->fresh();
        $this->assertSame(1, KitchenItem::count(), 'only the flagged line reaches the kitchen');
        $this->assertSame(DineOrder::STATUS_ACCEPTED, $order->status);
        $this->assertNull($order->served_at);
    }

    public function test_intake_twice_serves_once(): void
    {
        $sale = $this->openBill([false]);
        $sale->update(['payment_status' => 'paid']);
        $servedAt = $sale->dineOrder->fresh()->served_at;
        $this->travel(5)->minutes();

        app(KitchenIntakeService::class)->intake($sale->fresh());

        $order = $sale->dineOrder->fresh();
        $this->assertSame(DineOrder::STATUS_COMPLETED, $order->status);
        $this->assertEquals($servedAt, $order->served_at);
    }

    /** An accepted dine-in open bill with one line per entry; true marks the product as needing preparation. */
    private function openBill(array $flags): Transaction
    {
        $sale = Transaction::create([
            'cashier_id' => User::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice' => 'INV-'.++$this->seq,
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0,
            'grand_total' => 10000 * count($flags),
            'payment_method' => Transaction::METHOD_OPEN_BILL,
            'payment_status' => 'unpaid',
        ]);

        foreach ($flags as $needsPreparation) {
            $product = Product::create([
                'title' => 'Menu '.++$this->seq, 'sku' => 'SKU-'.$this->seq, 'barcode' => 'BC-'.$this->seq, 'buy_price' => 5000,
                'sell_price' => 10000, 'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
                'category_id' => $this->category->id, 'needs_preparation' => $needsPreparation,
            ]);
            TransactionDetail::create([
                'transaction_id' => $sale->id, 'product_id' => $product->id, 'qty' => 1, 'conversion_factor' => 1,
                'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000, 'discount_total' => 0, 'cost_total' => 0,
            ]);
        }

        DineOrder::create([
            'dine_table_id' => DiningTable::create(['name' => 'Meja 1', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0])->id,
            'transaction_id' => $sale->id,
            'status' => DineOrder::STATUS_ACCEPTED,
            'payment_option' => DineOrder::PAY_AT_COUNTER,
            'subtotal' => 10000 * count($flags),
            'item_count' => count($flags),
        ]);

        return $sale->load('dineOrder');
    }
}
