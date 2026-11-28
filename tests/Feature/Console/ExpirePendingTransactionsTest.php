<?php

namespace Tests\Feature\Console;

use App\Models\Category;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\SalesReturn;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\TransactionDetailBatchAllocation;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExpirePendingTransactionsTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PermissionSeeder::class,
            RoleSeeder::class,
            UserSeeder::class,
        ]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'name' => 'PUSAT',
            'code' => 'PUSAT',
            'type' => 'main',
            'is_active' => true,
            'sort_order' => 0,
            'status' => 'active',
        ]);

        $category = Category::create(['name' => 'Test Category']);

        $this->product = Product::create([
            'image' => 'test.png',
            'barcode' => '1234567890',
            'sku' => 'EXPIRE-1',
            'title' => 'Produk Expire',
            'description' => 'test',
            'category_id' => $category->id,
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 0,
            'tax_rate' => 0,
            'tax_type' => 'exclusive',
        ]);

        $this->warehouse->products()->attach($this->product->id, ['stock' => 0]);
    }

    private function makePendingTransaction(string $invoice, $createdAt, int $conversionFactor = 1): Transaction
    {
        $transaction = new Transaction([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'invoice' => $invoice,
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 20000,
            'payment_method' => 'midtrans',
            'payment_status' => 'pending',
        ]);
        $transaction->created_at = $createdAt;
        $transaction->save();

        $detail = TransactionDetail::create([
            'transaction_id' => $transaction->id,
            'product_id' => $this->product->id,
            'qty' => 2,
            'conversion_factor' => $conversionFactor,
            'base_unit_price' => 10000,
            'unit_price' => 10000,
            'price' => 10000,
        ]);

        $batch = ProductBatch::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_number' => 'BATCH-'.$invoice,
            'received_at' => now(),
            'stock' => 0,
        ]);

        TransactionDetailBatchAllocation::create([
            'transaction_detail_id' => $detail->id,
            'product_batch_id' => $batch->id,
            'qty' => 2 * $conversionFactor,
        ]);

        return $transaction;
    }

    public function test_expires_old_pending_transaction_and_restocks(): void
    {
        $transaction = $this->makePendingTransaction('INV-EXPIRE-1', now()->subHours(30));

        \Artisan::call('transactions:expire');
        $transaction->refresh();

        $this->assertEquals('failed', $transaction->payment_status);

        // pivot + global + batch restocked
        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'stock' => 2,
        ]);
        $this->assertEquals(2, $this->product->fresh()->stock);
        $this->assertEquals(2, $transaction->details->first()->batchAllocations->first()->productBatch->stock);

        $this->assertDatabaseHas(StockMutation::class, [
            'reference_type' => 'transaction_expire',
            'reference_id' => $transaction->id,
            'mutation_type' => 'in',
            'qty' => 2,
        ]);
    }

    public function test_expires_multi_unit_transaction_and_restocks_base_units(): void
    {
        $transaction = $this->makePendingTransaction('INV-EXPIRE-MU', now()->subHours(30), conversionFactor: 12);

        \Artisan::call('transactions:expire');
        $transaction->refresh();

        $this->assertEquals('failed', $transaction->payment_status);

        // qty 2 x factor 12 = 24 base units restored to pivot, global, and batch
        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'stock' => 24,
        ]);
        $this->assertEquals(24, $this->product->fresh()->stock);
        $this->assertEquals(24, $transaction->details->first()->batchAllocations->first()->productBatch->stock);

        $this->assertDatabaseHas(StockMutation::class, [
            'reference_type' => 'transaction_expire',
            'reference_id' => $transaction->id,
            'mutation_type' => 'in',
            'qty' => 24,
        ]);
    }

    public function test_retried_sale_is_not_expired_by_its_original_age(): void
    {
        // Created 30 h ago, but the cashier retried the payment a few minutes ago (payment attempt 1).
        $retried = $this->makePendingTransaction('INV-RETRIED', now()->subHours(30));
        $retried->forceFill(['payment_attempt' => 1, 'updated_at' => now()->subMinutes(5)])->saveQuietly();
        // Same age, retried 30 h ago: the retry itself went stale, so it expires as before.
        $stale = $this->makePendingTransaction('INV-RETRIED-STALE', now()->subHours(40));
        $stale->forceFill(['payment_attempt' => 1, 'updated_at' => now()->subHours(30)])->saveQuietly();

        \Artisan::call('transactions:expire');

        $this->assertEquals('pending', $retried->fresh()->payment_status);
        $this->assertDatabaseMissing(StockMutation::class, ['reference_type' => 'transaction_expire', 'reference_id' => $retried->id]);
        $this->assertEquals('failed', $stale->fresh()->payment_status);
    }

    public function test_fresh_pending_transaction_is_untouched(): void
    {
        $transaction = $this->makePendingTransaction('INV-FRESH-1', now()->subHours(2));

        $this->artisan('transactions:expire')->assertSuccessful();

        $transaction->refresh();

        $this->assertEquals('pending', $transaction->payment_status);
        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'stock' => 0,
        ]);
    }

    public function test_pending_approval_transaction_is_untouched(): void
    {
        $transaction = $this->makePendingTransaction('INV-APPROVAL-1', now()->subHours(30));
        $transaction->update(['payment_status' => 'pending_approval']);

        $this->artisan('transactions:expire')->assertSuccessful();

        $transaction->refresh();

        $this->assertEquals('pending_approval', $transaction->payment_status);
    }

    public function test_dry_run_does_not_mutate(): void
    {
        $transaction = $this->makePendingTransaction('INV-DRY-1', now()->subHours(30));

        $this->artisan('transactions:expire', ['--dry-run' => true])->assertSuccessful();

        $transaction->refresh();

        $this->assertEquals('pending', $transaction->payment_status);
        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'stock' => 0,
        ]);
    }

    public function test_webhook_ignores_terminal_transaction(): void
    {
        $transaction = $this->makePendingTransaction('INV-TERM-1', now());
        $transaction->update(['payment_status' => 'paid']);

        $paymentSetting = PaymentSetting::create([
            'midtrans_enabled' => true,
            'midtrans_server_key' => 'test-server-key',
        ]);

        $grossAmount = '20000.00';
        $orderId = 'INV-TERM-1';
        $statusCode = '200';
        $signature = hash('sha512', $orderId.$statusCode.$grossAmount.'test-server-key');

        $response = $this->postJson('/api/webhooks/midtrans', [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'gateway-ref-1',
        ]);

        $response->assertOk();

        // paid_at must not have been set / status untouched by the late webhook
        $transaction->refresh();
        $this->assertEquals('paid', $transaction->payment_status);
        $this->assertNull($transaction->payment_reference);
    }

    /** #380 (BR-4): a failed gateway sale is no longer terminal for money that still arrives; see PosLatePaymentTest. */
    public function test_webhook_does_not_book_a_late_settlement_of_an_expired_and_restocked_transaction(): void
    {
        $transaction = $this->makePendingTransaction('INV-TERM-2', now()->subHours(30));
        $this->artisan('transactions:expire')->assertSuccessful();
        $this->assertEquals('failed', $transaction->fresh()->payment_status);

        PaymentSetting::create([
            'midtrans_enabled' => true,
            'midtrans_server_key' => 'test-server-key',
        ]);

        $grossAmount = '20000.00';
        $orderId = 'INV-TERM-2';
        $statusCode = '200';
        $signature = hash('sha512', $orderId.$statusCode.$grossAmount.'test-server-key');

        $response = $this->postJson('/api/webhooks/midtrans', [
            'order_id' => $orderId,
            'status_code' => $statusCode,
            'gross_amount' => $grossAmount,
            'signature_key' => $signature,
            'transaction_status' => 'settlement',
            'fraud_status' => 'accept',
            'transaction_id' => 'gateway-ref-2',
        ]);

        $response->assertOk();

        // The goods went back to stock, so the sale stays failed; the money is flagged for a manual refund instead of dropped.
        $transaction->refresh();
        $this->assertEquals('failed', $transaction->payment_status);
        $this->assertNotNull($transaction->late_superseded_payment_at);
    }

    /** A completed return that put `$qtyReturn` of the sale's single line back, unless `$restock` is off (#504). */
    private function completedReturnedLine(Transaction $transaction, int $qtyReturn, bool $restock = true): void
    {
        $detail = $transaction->details()->firstOrFail();
        $return = SalesReturn::create([
            'code' => 'RET-'.uniqid(),
            'transaction_id' => $transaction->id,
            'cashier_id' => $this->cashier->id,
            'status' => 'completed',
            'return_type' => 'refund_cash',
            'total_return_amount' => 10000 * $qtyReturn,
        ]);
        $return->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $detail->product_id,
            'qty_sold' => $detail->qty,
            'qty_return' => $qtyReturn,
            'unit_price' => 10000,
            'subtotal' => 10000 * $qtyReturn,
            'return_reason' => 'Salah pesan',
            'restock_to_inventory' => $restock,
        ]);
    }

    private function putBack(Product $product, int $qty): void
    {
        $product->increment('stock', $qty);
        DB::table('product_warehouse')
            ->where(['product_id' => $product->id, 'warehouse_id' => $this->warehouse->id])
            ->increment('stock', $qty);
    }

    /** #504 [BR-1]: a completed return already restocked part of the sale; expiry restocks only the rest. */
    public function test_expiry_restocks_only_what_returns_did_not(): void
    {
        $transaction = $this->makePendingTransaction('INV-RET-1', now()->subHours(30));
        $this->completedReturnedLine($transaction, qtyReturn: 1);
        $this->putBack($this->product, 1); // the completed return already put 1 of the 2 units back

        \Artisan::call('transactions:expire');

        // 1 (return) + 1 (expiry) = the 2 sold, not 3.
        $this->assertEquals(2, $this->product->fresh()->stock);
        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'stock' => 2,
        ]);
        $this->assertDatabaseHas(StockMutation::class, [
            'reference_type' => 'transaction_expire',
            'reference_id' => $transaction->id,
            'qty' => 1,
        ]);
    }

    public function test_unticked_return_does_not_reduce_expiry_restock(): void
    {
        $transaction = $this->makePendingTransaction('INV-RET-2', now()->subHours(30));
        $this->completedReturnedLine($transaction, qtyReturn: 1, restock: false);

        \Artisan::call('transactions:expire');

        // The unticked return put nothing back, so expiry restocks the full 2.
        $this->assertEquals(2, $this->product->fresh()->stock);
        $this->assertDatabaseHas(StockMutation::class, [
            'reference_type' => 'transaction_expire',
            'reference_id' => $transaction->id,
            'qty' => 2,
        ]);
    }

    public function test_expiry_of_a_partly_returned_recipe_menu(): void
    {
        $categoryId = $this->product->category_id;
        $component = Product::create([
            'image' => 'c.png', 'barcode' => 'COMP-1', 'sku' => 'COMP-1', 'title' => 'Biji kopi',
            'description' => 'x', 'category_id' => $categoryId, 'buy_price' => 1000, 'sell_price' => 0,
            'stock' => 0, 'tax_rate' => 0, 'tax_type' => 'exclusive',
        ]);
        $this->warehouse->products()->attach($component->id, ['stock' => 0]);
        $menu = Product::create([
            'image' => 'm.png', 'barcode' => 'MENU-1', 'sku' => 'MENU-1', 'title' => 'Menu',
            'description' => 'x', 'category_id' => $categoryId, 'buy_price' => 0, 'sell_price' => 20000,
            'stock' => 0, 'tax_rate' => 0, 'tax_type' => 'exclusive', 'is_composite' => true,
        ]);
        $this->warehouse->products()->attach($menu->id, ['stock' => 0]);
        $menu->components()->attach($component->id, ['qty' => 2]); // two components per menu

        $transaction = new Transaction([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id,
            'invoice' => 'INV-RECIPE', 'cash' => 0, 'change' => 0, 'discount' => 0,
            'grand_total' => 40000, 'payment_method' => 'midtrans', 'payment_status' => 'pending',
        ]);
        $transaction->created_at = now()->subHours(30);
        $transaction->save();
        TransactionDetail::create([
            'transaction_id' => $transaction->id, 'product_id' => $menu->id, 'qty' => 2,
            'conversion_factor' => 1, 'base_unit_price' => 20000, 'unit_price' => 20000, 'price' => 40000,
        ]);
        $this->completedReturnedLine($transaction, qtyReturn: 1); // 1 of the 2 menus returned
        $this->putBack($component, 2); // the return put back 1 menu's worth (2 components)

        \Artisan::call('transactions:expire');

        // 1 menu still out restocks 2 components: 2 (return) + 2 (expiry) = 4, not 6.
        $this->assertEquals(4, $component->fresh()->stock);
        $this->assertDatabaseHas('product_warehouse', [
            'product_id' => $component->id,
            'warehouse_id' => $this->warehouse->id,
            'stock' => 4,
        ]);
        $this->assertEquals(0, $menu->fresh()->stock, 'a recipe menu holds no stock of its own');
    }

    /** #504: a fully returned sale restocks nothing on expiry but must still be marked cancelled (wasRestocked). */
    public function test_fully_returned_sale_still_marks_it_cancelled_on_expiry(): void
    {
        $transaction = $this->makePendingTransaction('INV-RET-FULL', now()->subHours(30));
        $this->completedReturnedLine($transaction, qtyReturn: 2); // all 2 sold units already returned
        $this->putBack($this->product, 2);

        \Artisan::call('transactions:expire');

        $this->assertTrue($transaction->fresh()->wasRestocked());
        $this->assertEquals(2, $this->product->fresh()->stock, 'no extra restock on a fully returned sale');
    }
}
