<?php

namespace Tests\Feature\Kitchen;

use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KitchenReleaseBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Category $category;

    private DiningTable $table;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        $this->table = DiningTable::create(['name' => 'Meja 1', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);
    }

    private function product(bool $needsPreparation): Product
    {
        $n = ++$this->seq;

        return Product::create([
            'title' => "Produk $n", 'sku' => "SKU-KB-$n", 'barcode' => "BC-KB-$n", 'description' => 'x',
            'image' => 'products/t.jpg', 'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 20,
            'tax_rate' => 0, 'category_id' => $this->category->id, 'needs_preparation' => $needsPreparation,
        ]);
    }

    /** A sale with one line of $product (qty 2), optionally tied to a dine order in $dineStatus. */
    private function sale(Product $product, string $paymentStatus, ?string $dineStatus = null): Transaction
    {
        $n = ++$this->seq;
        $transaction = Transaction::create([
            'cashier_id' => User::factory()->create()->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice' => "TRX-KB-$n",
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0,
            'grand_total' => 20000,
            'payment_method' => 'cash',
            'payment_status' => $paymentStatus,
        ]);
        $transaction->details()->create(['product_id' => $product->id, 'qty' => 2, 'price' => 20000]);

        if ($dineStatus !== null) {
            DineOrder::create([
                'dine_table_id' => $this->table->id,
                'access_token' => "tok-$n",
                'status' => $dineStatus,
                'transaction_id' => $transaction->id,
            ]);
        }

        return $transaction;
    }

    public function test_release_backfill_copies_in_progress_paid_dine_orders_only(): void
    {
        $flagged = $this->product(true);
        $plain = $this->product(false);

        $preparing = $this->sale($flagged, 'paid', DineOrder::STATUS_PREPARING);
        $unpaid = $this->sale($flagged, 'unpaid', DineOrder::STATUS_ACCEPTED);
        $oldPosSale = $this->sale($flagged, 'paid');
        $unflagged = $this->sale($plain, 'paid', DineOrder::STATUS_READY);

        $migration = require database_path('migrations/2026_11_29_100001_create_kitchen_items_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame(1, KitchenItem::count());

        $item = KitchenItem::firstOrFail();
        $this->assertSame($preparing->id, $item->transaction_id);
        $this->assertSame($preparing->details()->value('id'), $item->transaction_detail_id);
        $this->assertSame($flagged->id, $item->product_id);
        $this->assertSame($this->warehouse->id, $item->warehouse_id);
        $this->assertSame(2, $item->qty);
        $this->assertSame(KitchenItem::STATUS_PREPARING, $item->status);
        $this->assertNull($item->accepted_at);
        $this->assertNull($item->preparing_at);
        $this->assertNull($item->ready_at);
        $this->assertNull($item->served_at);
        $this->assertNull($item->accepted_by);

        $this->assertFalse(KitchenItem::where('transaction_id', $unpaid->id)->exists());
        $this->assertFalse(KitchenItem::where('transaction_id', $oldPosSale->id)->exists());
        $this->assertFalse(KitchenItem::where('transaction_id', $unflagged->id)->exists());
    }

    public function test_release_backfill_copies_the_dine_in_line_note(): void
    {
        $flagged = $this->product(true);
        $sale = $this->sale($flagged, 'paid', DineOrder::STATUS_ACCEPTED);
        $sale->dineOrder->items()->create(['product_id' => $flagged->id, 'qty' => 2, 'price' => 10000, 'note' => 'tanpa gula']);

        $migration = require database_path('migrations/2026_11_29_100001_create_kitchen_items_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame('tanpa gula', KitchenItem::sole()->note);
    }
}
