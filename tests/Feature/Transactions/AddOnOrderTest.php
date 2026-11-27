<?php

namespace Tests\Feature\Transactions;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DineArea;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// #251 (REQ-003 AC-21, BR-1..BR-11): an add-on made on the POS is its own sale, linked to the original order.
class AddOnOrderTest extends TestCase
{
    use RefreshDatabase;

    private const CANCELLED = 'Pesanan asal sudah dibatalkan — buat pesanan baru';

    private User $cashier;

    private User $admin;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private Category $category;

    private Product $nasi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();
        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();

        [$this->outlet, $this->warehouse] = $this->outletWithWarehouse('A');
        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        $this->nasi = $this->product('Nasi goreng');

        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 0, null, $this->warehouse->id);
    }

    // AC-21, BR-2, BR-8
    public function test_pos_add_on_links_to_the_original_and_is_its_own_sale(): void
    {
        $original = $this->sell();

        $addOn = $this->addOnTo($original);

        $this->assertNotSame($original->id, $addOn->id);
        $this->assertNotSame($original->invoice, $addOn->invoice);
        $this->assertSame($original->id, $addOn->parent_transaction_id);
        $this->assertNull($original->fresh()->parent_transaction_id);
        $this->assertSame('paid', $addOn->payment_status);
        $this->assertSame(1, KitchenItem::where('transaction_id', $addOn->id)->count());

        $log = AuditLog::where('event', 'transaction.add_on_created')->sole();
        $this->assertSame($addOn->id, (int) $log->auditable_id);
        $this->assertSame($original->id, $log->meta['parent_transaction_id']);
        // BR-8: the add-on's receipt names the original order.
        $this->actingAs($this->cashier)->get(route('transactions.print', $addOn->invoice))
            ->assertInertia(fn (Assert $page) => $page->where('transaction.parent.invoice', $original->invoice));
    }

    // TC-1: the link always points at the root
    public function test_add_on_to_an_add_on_links_to_the_root(): void
    {
        $original = $this->sell();
        $first = $this->addOnTo($original);

        $second = $this->addOnTo($first);

        $this->assertSame($original->id, $second->parent_transaction_id);
    }

    // BR-1
    public function test_add_on_requires_permission_and_open_shift(): void
    {
        $original = $this->sell();
        $nobody = User::factory()->create();
        $noShift = User::factory()->create();
        $noShift->givePermissionTo('transactions-access');
        $payload = ['cash' => 100000, 'add_on_to' => $original->invoice];

        $this->actingAs($nobody)->post(route('transactions.store'), $payload)->assertForbidden();
        $this->cartFor($noShift, $this->nasi);
        $this->actingAs($noShift)->post(route('transactions.store'), $payload)->assertRedirect();

        $this->assertSame(1, Transaction::count());
    }

    public function test_add_on_in_another_outlet_is_refused(): void
    {
        [, $warehouseB] = $this->outletWithWarehouse('B');
        $near = Warehouse::create(['outlet_id' => $this->outlet->id, 'code' => 'WH-A2', 'name' => 'Gudang A2', 'type' => 'branch', 'is_active' => true]);

        $this->assertRefused($this->existingSale($warehouseB, 'unpaid')->invoice, 'Buka shift di outlet pesanan ini');
        $this->assertRefused('TRX-TIDAKADA', 'Pesanan asal tidak ditemukan');
        // Any warehouse of the shift's outlet will do; money still owed keeps an order open (BR-9).
        $original = $this->existingSale($near, 'unpaid');
        $this->assertSame($original->id, $this->addOnTo($original)->parent_transaction_id);
    }

    // BR-4
    public function test_add_on_deducts_stock_in_both_tables(): void
    {
        $original = $this->sell();

        $this->addOnTo($original);

        $this->assertSame(98, (int) $this->nasi->fresh()->stock);
        $this->assertSame(98, (int) $this->nasi->warehouses()->whereKey($this->warehouse->id)->first()->pivot->stock);
    }

    // BR-4 (AC-27)
    public function test_add_on_counts_in_the_shift_it_is_made_in(): void
    {
        $original = $this->sell();
        $later = User::factory()->create();
        $later->givePermissionTo('transactions-access');
        $shift = app(CashierShiftService::class)->openShift($later, $later, 0, null, $this->warehouse->id);

        $addOn = $this->addOnTo($original, by: $later);

        $this->assertSame($shift->id, $addOn->cashier_shift_id);
        $this->assertNotSame($shift->id, $original->fresh()->cashier_shift_id);
    }

    // BR-5
    public function test_promotion_applies_to_the_add_on_alone(): void
    {
        $original = $this->sell();
        PricingRule::create([
            'name' => 'Potongan', 'is_active' => true, 'priority' => 100, 'target_type' => 'product', 'product_id' => $this->nasi->id,
            'customer_scope' => 'all', 'discount_type' => 'fixed_amount', 'discount_value' => 1000,
        ]);

        $addOn = $this->addOnTo($original);

        $this->assertSame(9000, $addOn->grand_total);
        $this->assertSame(10000, $original->fresh()->grand_total);
    }

    // BR-5, TC-3
    public function test_original_sale_rows_are_unchanged_by_an_add_on(): void
    {
        $customer = Customer::create(['name' => 'Hadi', 'no_telp' => '0811111111', 'address' => 'x', 'is_loyalty_member' => true, 'loyalty_points' => 50]);
        $original = $this->sell(['customer_id' => $customer->id]);
        $rows = fn () => [
            $original->fresh()->getAttributes(),
            $original->details()->orderBy('id')->get()->map->getAttributes()->all(),
            $original->profits()->orderBy('id')->get()->map->getAttributes()->all(),
        ];
        $before = $rows();
        $this->travel(5)->minutes();

        $this->addOnTo($original, ['customer_id' => $customer->id]);

        $this->assertSame($before, $rows());
    }

    // BR-3
    public function test_pay_now_add_on_reaches_the_kitchen_only_when_paid(): void
    {
        $original = $this->sell();

        $addOn = $this->addOnTo($original, $this->byTransfer());

        $this->assertSame('pending', $addOn->payment_status);
        $this->assertSame(0, KitchenItem::where('transaction_id', $addOn->id)->count());

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('transactions.confirm-payment', $addOn))
            ->assertSessionHas('success');

        $this->assertSame(1, KitchenItem::where('transaction_id', $addOn->id)->count());
    }

    // BR-3
    public function test_pay_later_add_on_reaches_the_kitchen_at_once(): void
    {
        Setting::setForOutlet('pay_later_enabled', '1', $this->outlet);
        $original = $this->sell();

        $addOn = $this->addOnTo($original, ['pay_later' => true, 'note' => 'Meja depan']);

        $this->assertSame($original->id, $addOn->parent_transaction_id);
        $this->assertSame('unpaid', $addOn->payment_status);
        $this->assertSame(1, KitchenItem::where('transaction_id', $addOn->id)->count());
    }

    // BR-9
    public function test_add_on_to_unpaid_original_keeps_its_own_payment_rule(): void
    {
        $original = $this->sell($this->byTransfer());

        $addOn = $this->addOnTo($original);

        $this->assertSame($original->id, $addOn->parent_transaction_id);
        $this->assertSame('paid', $addOn->payment_status);
        $this->assertSame('pending', $original->fresh()->payment_status);
        $this->assertSame([$addOn->id], KitchenItem::pluck('transaction_id')->all());
    }

    // BR-6
    public function test_add_on_to_a_completed_order_starts_a_new_order(): void
    {
        $served = $this->sell();
        KitchenItem::where('transaction_id', $served->id)->update(['status' => KitchenItem::STATUS_SERVED]);
        $nothingToMake = $this->sell(product: $this->product('Air mineral', flagged: false));

        $this->assertNull($this->addOnTo($served)->parent_transaction_id);
        $this->assertNull($this->addOnTo($nothingToMake)->parent_transaction_id);
        $this->assertSame(0, AuditLog::where('event', 'transaction.add_on_created')->count());
    }

    // BR-7, BR-11
    public function test_add_on_to_a_cancelled_order_is_refused(): void
    {
        $failed = $this->existingSale($this->warehouse, 'failed');
        $expired = $this->existingSale($this->warehouse, 'expired');
        $dine = $this->existingSale($this->warehouse, 'unpaid');
        $this->dineOrderFor($dine, DineOrder::STATUS_CANCELLED);
        $returned = $this->sell();
        $this->returnEverything($returned);
        $autoCancelled = $this->existingSale($this->warehouse, 'pending');
        $autoCancelled->forceFill(['payment_method' => 'midtrans', 'created_at' => now()->subDays(2)])->save();
        $this->artisan('transactions:expire')->assertSuccessful();
        $this->assertSame('failed', $autoCancelled->fresh()->payment_status);

        foreach ([$failed, $expired, $dine, $returned, $autoCancelled] as $original) {
            $this->assertRefused($original->invoice, self::CANCELLED);
        }
    }

    // BR-11
    public function test_add_on_to_cancelled_original_is_refused_on_pos_and_qr(): void
    {
        $area = DineArea::create(['outlet_id' => $this->outlet->id, 'name' => 'Depan', 'is_active' => true]);
        $table = DiningTable::create(['dine_area_id' => $area->id, 'name' => 'Meja 1', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);
        $sale = $this->existingSale($this->warehouse, 'failed');
        $order = $this->dineOrderFor($sale, DineOrder::STATUS_ACCEPTED, $table);

        $this->assertRefused($sale->invoice, self::CANCELLED);
        $this->post(route('dine-order.store', $table->token), [
            'items' => [['product_id' => $this->nasi->id, 'qty' => 1]], 'payment_option' => 'pay_at_counter', 'add_on_to' => $order->access_token,
        ])->assertSessionHasErrors(['add_on_to' => self::CANCELLED]);

        $this->assertSame(1, DineOrder::count());
    }

    // BR-7, TC-5
    public function test_sync_rejects_a_queued_add_on(): void
    {
        $original = $this->sell();
        Sanctum::actingAs($this->cashier, ['*']);

        $this->postJson('/api/v1/pos/transactions/sync', ['transactions' => [[
            'client_uuid' => '550e8400-e29b-41d4-a716-446655440251',
            'items' => [['product_id' => $this->nasi->id, 'qty' => 1]],
            'cash' => 100000,
            'add_on_to' => $original->invoice,
        ]]])->assertOk()
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.reason', 'Pesanan tambahan tidak tersedia saat offline');

        $this->assertSame(1, Transaction::count());
        $this->assertSame(0, Cart::count());
        $this->assertSame(99, (int) $this->nasi->fresh()->stock);
    }

    public function test_pos_page_says_what_the_add_on_will_do(): void
    {
        $open = $this->sell();
        $done = $this->sell(product: $this->product('Air mineral', flagged: false));
        $cancelled = $this->existingSale($this->warehouse, 'failed');

        foreach ([[$open, 'linked'], [$done, 'completed'], [$cancelled, 'cancelled']] as [$sale, $state]) {
            $this->actingAs($this->cashier)->get(route('transactions.index', ['add_on_to' => $sale->invoice]))
                ->assertInertia(fn (Assert $page) => $page->where('addOn.invoice', $sale->invoice)->where('addOn.state', $state));
        }

        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page->where('addOn', null));
    }

    // The POS reloads its own page after these: the add-on must not be dropped on the way.
    public function test_cart_and_refused_checkout_redirects_keep_the_add_on(): void
    {
        $original = $this->sell();
        $withAddOn = route('transactions.index', ['add_on_to' => $original->invoice]);

        $this->actingAs($this->cashier)->post(route('transactions.addToCart'), [
            'product_id' => $this->nasi->id, 'qty' => 1, 'sell_price' => 10000, 'add_on_to' => $original->invoice,
        ])->assertRedirect($withAddOn);

        $this->actingAs($this->cashier)->post(route('transactions.store'), ['payment_gateway' => 'midtrans', 'add_on_to' => $original->invoice])
            ->assertRedirect($withAddOn);

        $this->actingAs($this->admin)->post(route('cashier-shifts.store'), [
            'opening_cash' => 0, 'warehouse_id' => $this->warehouse->id, 'redirect_to' => 'transactions', 'add_on_to' => $original->invoice,
        ])->assertRedirect($withAddOn);
    }

    // TC-1
    public function test_parent_transaction_migration_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_12_11_100001_add_add_on_links_to_transactions_and_dine_orders.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('transactions', 'parent_transaction_id'));
        $this->assertFalse(Schema::hasColumn('dine_orders', 'add_on_to_dine_order_id'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('transactions', 'parent_transaction_id'));
        $this->assertTrue(Schema::hasColumn('dine_orders', 'add_on_to_dine_order_id'));
    }

    /** @return array{Outlet, Warehouse} */
    private function outletWithWarehouse(string $code): array
    {
        $outlet = Outlet::create(['code' => 'OUT-'.$code, 'name' => 'Outlet '.$code, 'is_active' => true, 'is_sales_enabled' => true]);
        $warehouse = Warehouse::create(['outlet_id' => $outlet->id, 'code' => 'WH-'.$code, 'name' => 'Gudang '.$code, 'type' => 'branch', 'is_active' => true]);

        return [$outlet, $warehouse];
    }

    private function product(string $title, bool $flagged = true): Product
    {
        $product = Product::create([
            'title' => $title, 'sku' => 'SKU-'.uniqid(), 'barcode' => 'BC-'.uniqid(), 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $this->category->id, 'needs_preparation' => $flagged,
        ]);
        $product->warehouses()->attach($this->warehouse->id, ['stock' => 100]);

        return $product;
    }

    private function cartFor(User $user, Product $product): void
    {
        Cart::create(['cashier_id' => $user->id, 'product_id' => $product->id, 'qty' => 1, 'price' => 10000]);
    }

    /** A POS checkout of one unit, paid in cash unless $payload says otherwise. */
    private function sell(array $payload = [], ?Product $product = null, ?User $by = null): Transaction
    {
        $by ??= $this->cashier;
        $this->cartFor($by, $product ?? $this->nasi);
        $this->actingAs($by)->post(route('transactions.store'), $payload + ['cash' => 100000])->assertSessionHasNoErrors();

        return Transaction::latest('id')->firstOrFail();
    }

    private function addOnTo(Transaction $original, array $payload = [], ?User $by = null): Transaction
    {
        return $this->sell($payload + ['add_on_to' => $original->invoice], by: $by);
    }

    private function byTransfer(): array
    {
        $bank = BankAccount::create(['bank_name' => 'BCA', 'account_number' => '1234567890', 'account_name' => 'Toko', 'is_active' => true]);
        PaymentSetting::create(['default_gateway' => 'bank_transfer', 'bank_transfer_enabled' => true]);

        return ['payment_gateway' => 'bank_transfer', 'bank_account_id' => $bank->id];
    }

    /** A sale already on record, without lines or kitchen items. */
    private function existingSale(Warehouse $warehouse, string $status): Transaction
    {
        $sale = Transaction::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $warehouse->id, 'invoice' => 'INV-'.uniqid(),
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => 10000,
            'payment_method' => 'cash', 'payment_status' => $status,
        ]);
        $sale->details()->create([
            'transaction_id' => $sale->id, 'product_id' => $this->nasi->id, 'qty' => 1, 'conversion_factor' => 1,
            'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000, 'discount_total' => 0, 'cost_total' => 0,
        ]);

        return $sale;
    }

    private function dineOrderFor(Transaction $sale, string $status, ?DiningTable $table = null): DineOrder
    {
        $table ??= DiningTable::create(['name' => 'Meja '.uniqid(), 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);

        return DineOrder::create([
            'dine_table_id' => $table->id, 'transaction_id' => $sale->id, 'status' => $status,
            'payment_option' => DineOrder::PAY_AT_COUNTER, 'subtotal' => 10000, 'item_count' => 1,
        ]);
    }

    private function returnEverything(Transaction $sale): void
    {
        $return = SalesReturn::create([
            'code' => 'RET-'.uniqid(), 'transaction_id' => $sale->id, 'cashier_id' => $this->cashier->id,
            'status' => 'completed', 'return_type' => 'refund_cash', 'total_return_amount' => $sale->grand_total,
        ]);

        foreach ($sale->details as $detail) {
            $return->items()->create([
                'transaction_detail_id' => $detail->id, 'product_id' => $detail->product_id, 'qty_sold' => $detail->qty,
                'qty_return' => $detail->qty, 'unit_price' => $detail->unit_price, 'subtotal' => $detail->price, 'return_reason' => 'Salah pesan',
            ]);
        }
    }

    /** The checkout is refused with $message and sells nothing. */
    private function assertRefused(string $invoice, string $message): void
    {
        $count = Transaction::count();
        $stock = (int) $this->nasi->fresh()->stock;
        $this->cartFor($this->cashier, $this->nasi);

        $this->actingAs($this->cashier)->post(route('transactions.store'), ['cash' => 100000, 'add_on_to' => $invoice])
            ->assertSessionHasErrors(['add_on_to' => $message]);

        $this->assertSame($count, Transaction::count());
        $this->assertSame($stock, (int) $this->nasi->fresh()->stock);
        Cart::query()->delete();
    }
}
