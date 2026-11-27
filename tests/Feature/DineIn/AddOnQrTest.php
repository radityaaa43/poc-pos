<?php

namespace Tests\Feature\DineIn;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\DineArea;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\KitchenItemService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// #251 (REQ-003 AC-21, BR-10, BR-11): a table-QR order joins only the order whose status page it was made from.
class AddOnQrTest extends TestCase
{
    use RefreshDatabase;

    private const CANCELLED = 'Pesanan asal sudah dibatalkan — buat pesanan baru';

    private User $cashier;

    private Outlet $outlet;

    private DiningTable $table;

    private Product $nasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();

        $this->outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true, 'is_sales_enabled' => true]);
        $warehouse = Warehouse::create([
            'outlet_id' => $this->outlet->id, 'code' => 'WH-1', 'name' => 'Gudang 1', 'type' => 'branch', 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->table = $this->tableNamed('Meja 7');

        $category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        $this->nasi = Product::create([
            'title' => 'Nasi goreng', 'sku' => 'SKU-1', 'barcode' => 'BC-1', 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $category->id, 'needs_preparation' => true,
        ]);
        $this->nasi->warehouses()->attach($warehouse->id, ['stock' => 100]);

        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 100000, null, $warehouse->id);
    }

    public function test_qr_add_on_links_to_the_order_it_was_made_from(): void
    {
        $original = $this->accept($this->order());

        $addOn = $this->order(from: $original);

        $this->assertSame($original->id, $addOn->add_on_to_dine_order_id);
        $this->assertNull($addOn->transaction_id, 'linked to a sale only at accept');

        $addOn = $this->accept($addOn);

        $this->assertSame($original->transaction_id, $addOn->transaction->parent_transaction_id);
        $this->assertSame($addOn->transaction_id, (int) AuditLog::where('event', 'transaction.add_on_created')->sole()->auditable_id);
    }

    public function test_qr_add_on_of_an_add_on_links_to_the_root(): void
    {
        $original = $this->accept($this->order());
        $first = $this->accept($this->order(from: $original));

        $second = $this->accept($this->order(from: $first));

        $this->assertSame($original->transaction_id, $second->transaction->parent_transaction_id);
    }

    // BR-10: the table alone never links two orders
    public function test_second_qr_order_at_same_table_is_not_linked(): void
    {
        $this->accept($this->order());

        $second = $this->accept($this->order());

        $this->assertNull($second->add_on_to_dine_order_id);
        $this->assertNull($second->transaction->parent_transaction_id);
    }

    // BR-10
    public function test_two_qr_orders_at_one_table_stay_separate(): void
    {
        $first = $this->accept($this->order());
        $second = $this->accept($this->order());

        $addOn = $this->accept($this->order(from: $second));

        $this->assertSame($second->transaction_id, $addOn->transaction->parent_transaction_id);
        $this->assertSame(0, Transaction::where('parent_transaction_id', $first->transaction_id)->count());
    }

    public function test_qr_add_on_with_other_table_token_is_rejected(): void
    {
        $original = $this->accept($this->order());
        $other = $this->tableNamed('Meja 8');

        foreach ([$original->access_token, 'bukan-token', (string) $original->id] as $reference) {
            $this->postJson(route('dine-order.store', $other->token), $this->payload($reference))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['add_on_to' => 'Pesanan asal tidak ditemukan di meja ini']);
        }

        $this->assertSame(1, DineOrder::count());
    }

    public function test_qr_add_on_to_an_order_not_yet_accepted_is_rejected(): void
    {
        $original = $this->order();

        $this->postJson(route('dine-order.store', $this->table->token), $this->payload($original->access_token))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['add_on_to' => 'Pesanan asal belum diterima — tunggu konfirmasi staf']);

        $this->assertSame(1, DineOrder::count());
    }

    // BR-11
    public function test_qr_add_on_to_cancelled_original_is_refused_at_submit(): void
    {
        $rejected = $this->order();
        $rejected->update(['status' => DineOrder::STATUS_REJECTED]);
        $cancelled = $this->accept($this->order());
        $cancelled->update(['status' => DineOrder::STATUS_CANCELLED]);
        $failed = $this->accept($this->order());
        $failed->transaction->update(['payment_status' => 'failed']);

        foreach ([$rejected, $cancelled, $failed] as $original) {
            $this->postJson(route('dine-order.store', $this->table->token), $this->payload($original->access_token))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['add_on_to' => self::CANCELLED]);
        }

        $this->assertSame(3, DineOrder::count());
    }

    // BR-11: cancelled between the guest's submit and the cashier's accept
    public function test_qr_add_on_to_cancelled_original_is_refused_at_accept(): void
    {
        $cases = [
            'failed' => fn (DineOrder $o) => $o->transaction->update(['payment_status' => 'failed']),
            'expired' => fn (DineOrder $o) => $o->transaction->update(['payment_status' => 'expired']),
            'cancelled' => fn (DineOrder $o) => $o->update(['status' => DineOrder::STATUS_CANCELLED]),
            'rejected' => fn (DineOrder $o) => $o->update(['status' => DineOrder::STATUS_REJECTED]),
        ];

        foreach ($cases as $case => $cancel) {
            $original = $this->accept($this->order());
            $addOn = $this->order(from: $original);
            $cancel($original->fresh());
            $sales = Transaction::count();
            $stock = (int) $this->nasi->fresh()->stock;

            $this->actingAs($this->cashier)->post(route('dine-orders.accept', $addOn))
                ->assertSessionHasErrors(['add_on_to' => self::CANCELLED]);

            $this->assertSame(DineOrder::STATUS_SUBMITTED, $addOn->fresh()->status, $case);
            $this->assertNull($addOn->fresh()->transaction_id, $case);
            $this->assertSame($sales, Transaction::count(), $case);
            $this->assertSame($stock, (int) $this->nasi->fresh()->stock, $case);
        }
    }

    // BR-6
    public function test_qr_order_after_the_previous_one_completed_has_no_parent(): void
    {
        $original = $this->accept($this->order());
        $addOn = $this->order(from: $original);
        $this->complete($original);

        $addOn = $this->accept($addOn);

        $this->assertSame(DineOrder::STATUS_ACCEPTED, $addOn->status);
        $this->assertNull($addOn->transaction->parent_transaction_id);
    }

    private function tableNamed(string $name): DiningTable
    {
        $area = DineArea::create(['outlet_id' => $this->outlet->id, 'name' => 'Area '.$name, 'is_active' => true]);

        return DiningTable::create(['dine_area_id' => $area->id, 'name' => $name, 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);
    }

    private function payload(?string $reference = null): array
    {
        return ['items' => [['product_id' => $this->nasi->id, 'qty' => 1]], 'payment_option' => DineOrder::PAY_AT_COUNTER]
            + ($reference === null ? [] : ['add_on_to' => $reference]);
    }

    /** A guest QR order at the table, still waiting for staff; $from is the order whose status page it was made from. */
    private function order(?DineOrder $from = null): DineOrder
    {
        $this->post(route('dine-order.store', $this->table->token), $this->payload($from?->access_token))->assertSessionHasNoErrors();

        return DineOrder::latest('id')->firstOrFail();
    }

    private function accept(DineOrder $order): DineOrder
    {
        $this->actingAs($this->cashier)->post(route('dine-orders.accept', $order))->assertSessionHasNoErrors();

        return $order->fresh();
    }

    /** Paid at the counter, made and handed over: the order is completed. */
    private function complete(DineOrder $order): void
    {
        $this->actingAs($this->cashier)
            ->post(route('dine-orders.pay', $order), ['tenders' => [['method' => 'cash', 'amount' => 10000, 'cash_received' => 10000]]])
            ->assertSessionHasNoErrors();
        $sale = $order->fresh()->transaction;
        app(KitchenItemService::class)->allReady($sale, $this->cashier);
        app(KitchenItemService::class)->handOverAll($sale, $this->cashier);

        $this->assertSame(DineOrder::STATUS_COMPLETED, $order->fresh()->status);
    }
}
