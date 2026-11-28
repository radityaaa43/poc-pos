<?php

namespace Tests\Feature\DineIn;

use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\Category;
use App\Models\DineArea;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\KitchenItemService;
use Closure;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
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
        // An add-on of an add-on joins the root order.
        $this->assertSame($original->transaction_id, $this->accept($this->order(from: $addOn))->transaction->parent_transaction_id);

        // BR-10: the table alone never links two orders.
        $second = $this->accept($this->order());
        $this->assertNull($second->add_on_to_dine_order_id);
        $this->assertNull($second->transaction->parent_transaction_id);
        $this->assertSame($second->transaction_id, $this->accept($this->order(from: $second))->transaction->parent_transaction_id);
        $this->assertSame(2, Transaction::where('parent_transaction_id', $original->transaction_id)->count(), 'only its own add-ons');
    }

    public function test_qr_add_on_with_another_tables_token_or_to_an_unaccepted_order_is_rejected(): void
    {
        $original = $this->order();
        $this->assertSubmitRefused($this->table, $original->access_token, 'Pesanan asal belum diterima — tunggu konfirmasi staf');
        $this->accept($original);
        $other = $this->tableNamed('Meja 8');

        foreach ([$original->access_token, 'bukan-token', (string) $original->id] as $reference) {
            $this->assertSubmitRefused($other, $reference, 'Pesanan asal tidak ditemukan di meja ini');
        }

        $this->assertSame(1, DineOrder::count());
    }

    // BR-11: cancelled between the guest's submit and the cashier's accept; a new submit is refused too
    public function test_qr_add_on_to_cancelled_original_is_refused_at_accept(): void
    {
        $cases = [
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
            $this->assertSubmitRefused($this->table, $original->access_token, self::CANCELLED);
        }
    }

    public function test_qr_order_after_the_previous_one_completed_has_no_parent(): void
    {
        $original = $this->accept($this->order());
        $addOn = $this->order(from: $original);
        $this->complete($original);

        $addOn = $this->accept($addOn);

        $this->assertSame(DineOrder::STATUS_ACCEPTED, $addOn->status);
        $this->assertNull($addOn->transaction->parent_transaction_id);
    }

    // BR-12: the accepted bill can still be paid after a failed or expired payment
    public function test_qr_add_on_to_a_failed_or_expired_bill_is_linked(): void
    {
        foreach (['failed', 'expired'] as $status) {
            $original = $this->accept($this->order());
            $original->transaction->update(['payment_status' => $status]);

            $addOn = $this->accept($this->order(from: $original));

            $this->assertSame($original->transaction_id, $addOn->transaction->parent_transaction_id, $status);
        }
    }

    // BR-13: a completed original stays open while one of its add-ons is
    public function test_qr_order_joins_a_completed_original_while_an_add_on_is_open(): void
    {
        $original = $this->accept($this->order());
        $this->accept($this->order(from: $original));
        $late = $this->order(from: $original);
        $this->complete($original);

        // So its status page still offers "Tambah pesanan", on load and on the poll.
        $this->assertStatusPageOffersAddOn($original, true);
        $this->assertSame($original->transaction_id, $this->accept($late)->transaction->parent_transaction_id);

        KitchenItem::query()->update(['status' => KitchenItem::STATUS_SERVED]);
        Transaction::query()->update(['payment_status' => 'paid']);
        $this->assertStatusPageOffersAddOn($original, false);
    }

    private function assertStatusPageOffersAddOn(DineOrder $order, bool $offered): void
    {
        $this->get(route('dine-order.status', $order->access_token))
            ->assertInertia(fn (Assert $page) => $page->where('order.add_on_open', $offered));
        $this->getJson(route('dine-order.status-check', $order->access_token))->assertJsonPath('order.add_on_open', $offered);
    }

    // The POS checkout and pay() take the shift first; accept the other way round would deadlock with them.
    public function test_accept_and_checkout_lock_in_order_before_their_first_plain_read(): void
    {
        $original = $this->accept($this->order());
        DB::enableQueryLog();
        $addOn = $this->order(from: $original);
        $this->assertEmpty(preg_grep('/for update/i', array_column(DB::getQueryLog(), 'query')), 'a guest submit takes no lock');

        // A cancellation committed before these locks is seen; one that comes later waits for them.
        $this->assertLeadingReads(['cashier_shifts', 'dine_orders', 'dine_orders', 'transactions', 'transactions', 'transactions'], fn () => $this->accept($addOn));
        Cart::create(['cashier_id' => $this->cashier->id, 'product_id' => $this->nasi->id, 'qty' => 1, 'price' => 10000]);
        $this->assertLeadingReads(['cashier_shifts', 'transactions', 'transactions'], fn () => $this->post(
            route('transactions.store'), ['cash' => 100000, 'add_on_to' => $original->transaction->invoice],
        )->assertSessionHasNoErrors());
    }

    /** The first reads of the app's own transaction (level 1 is the test's), by table; on MySQL (CI) each of them locks. */
    private function assertLeadingReads(array $tables, Closure $act): void
    {
        $sql = [];
        DB::listen(function (QueryExecuted $query) use (&$sql) {
            if (DB::transactionLevel() > 1) {
                $sql[] = $query->sql;
            }
        });

        $act();

        $leading = array_slice($sql, 0, count($tables));
        $this->assertSame($tables, preg_replace('/^.*? from .(\w+).*$/s', '$1', $leading));
        if (DB::getDriverName() === 'mysql') {
            foreach ($leading as $read) {
                $this->assertStringContainsString('for update', $read);
            }
        }
    }

    private function assertSubmitRefused(DiningTable $table, string $reference, string $message): void
    {
        $this->postJson(route('dine-order.store', $table->token), $this->payload($reference))
            ->assertStatus(422)->assertJsonValidationErrors(['add_on_to' => $message]);
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
