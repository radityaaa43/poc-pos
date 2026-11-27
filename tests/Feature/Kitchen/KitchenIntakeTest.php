<?php

namespace Tests\Feature\Kitchen;

use App\Models\BankAccount;
use App\Models\Cart;
use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\KitchenStation;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\KitchenIntakeService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\TransactionTenderService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

// #236: a paid sale line whose product needs preparation becomes a kitchen item, exactly once.
class KitchenIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    private User $admin;

    private Warehouse $warehouse;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);

        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();
        $this->admin = User::where('email', 'arya@gmail.com')->first();
        $this->admin->markEmailAsVerified();

        $this->warehouse = Warehouse::create([
            'code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0,
        ]);
        $this->category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 0, null, $this->warehouse->id);
    }

    public function test_cash_sale_puts_flagged_items_in_the_kitchen(): void
    {
        $nasi = $this->product('Nasi goreng', flagged: true);
        $this->addToCart($nasi, 2);

        $this->actingAs($this->cashier)
            ->post(route('transactions.store'), ['payment_method' => 'cash', 'cash' => 50000])
            ->assertSessionHasNoErrors();

        $transaction = Transaction::firstOrFail();
        $this->assertSame('paid', $transaction->payment_status);
        $item = KitchenItem::sole();
        $this->assertSame($transaction->id, $item->transaction_id);
        $this->assertSame($transaction->details->sole()->id, $item->transaction_detail_id);
        $this->assertSame($nasi->id, $item->product_id);
        $this->assertSame($this->warehouse->id, $item->warehouse_id);
        $this->assertSame(2, $item->qty);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->status);
        $this->assertNotNull($item->accepted_at);
        $this->assertSame($this->cashier->id, $item->accepted_by);
    }

    public function test_cash_sale_puts_flagged_items_in_the_kitchen_via_api(): void
    {
        $nasi = $this->product('Nasi goreng', flagged: true);
        Sanctum::actingAs($this->cashier, ['*']);

        $this->postJson('/api/v1/pos/cart', ['product_id' => $nasi->id, 'qty' => 2])->assertSuccessful();
        $this->postJson('/api/v1/pos/checkout', ['payment_method' => 'cash', 'cash' => 50000])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid');

        $item = KitchenItem::sole();
        $this->assertSame($nasi->id, $item->product_id);
        $this->assertSame(2, $item->qty);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->status);
        $this->assertSame($this->cashier->id, $item->accepted_by);
    }

    public function test_pending_transfer_has_no_kitchen_items_until_confirmed(): void
    {
        $bankAccount = BankAccount::create(['bank_name' => 'BCA', 'account_number' => '1234567890', 'account_name' => 'Toko Test', 'is_active' => true]);
        PaymentSetting::create(['default_gateway' => 'bank_transfer', 'bank_transfer_enabled' => true]);
        $this->addToCart($this->product('Nasi goreng', flagged: true));

        $this->actingAs($this->cashier)->post(route('transactions.store'), [
            'payment_gateway' => 'bank_transfer',
            'bank_account_id' => $bankAccount->id,
        ])->assertSessionHasNoErrors();
        $transaction = Transaction::firstOrFail();
        $this->assertSame('pending', $transaction->payment_status);
        $this->assertSame(0, KitchenItem::count());

        $this->actingAs($this->admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('transactions.confirm-payment', $transaction))
            ->assertSessionHas('success');

        $this->assertSame('paid', $transaction->fresh()->payment_status);
        $this->assertSame(1, KitchenItem::count());
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, KitchenItem::sole()->status);
    }

    public function test_qris_pending_then_paid_webhook_adds_kitchen_items(): void
    {
        $this->enableMidtrans();
        $transaction = $this->pendingSale('midtrans', [$this->product('Nasi goreng', flagged: true)]);
        $this->assertSame(0, KitchenItem::count());

        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($transaction, 'settlement'))->assertOk();

        $this->assertSame('paid', $transaction->fresh()->payment_status);
        $item = KitchenItem::sole();
        $this->assertSame($transaction->id, $item->transaction_id);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->status);
        $this->assertNull($item->accepted_by); // a webhook has no signed-in user
    }

    public function test_partly_paid_split_has_no_kitchen_items(): void
    {
        $bankAccount = BankAccount::create(['bank_name' => 'BCA', 'account_number' => '1234567890', 'account_name' => 'Toko Test', 'is_active' => true]);
        $this->addToCart($this->product('Nasi goreng', flagged: true));

        $this->actingAs($this->cashier)->post(route('transactions.store'), [
            'tenders' => [
                ['method' => 'cash', 'amount' => 5000, 'cash_received' => 5000],
                ['method' => 'bank_transfer', 'amount' => 5000, 'bank_account_id' => $bankAccount->id],
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('pending', Transaction::firstOrFail()->payment_status);
        $this->assertSame(0, KitchenItem::count());
    }

    public function test_fully_paid_split_puts_items_in_the_kitchen(): void
    {
        $bankAccount = BankAccount::create(['bank_name' => 'BCA', 'account_number' => '1234567890', 'account_name' => 'Toko Test', 'is_active' => true]);
        $this->addToCart($this->product('Nasi goreng', flagged: true));
        $this->actingAs($this->cashier)->post(route('transactions.store'), [
            'tenders' => [
                ['method' => 'cash', 'amount' => 5000, 'cash_received' => 5000],
                ['method' => 'bank_transfer', 'amount' => 5000, 'bank_account_id' => $bankAccount->id],
            ],
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, KitchenItem::count()); // the transfer part is still open

        $transaction = Transaction::firstOrFail();
        $transaction->tenders()->where('method', 'bank_transfer')->update(['payment_status' => 'paid']);
        // The last part is confirmed: the sale's status is the aggregate of its parts, as the webhook computes it.
        $transaction->update(['payment_status' => app(TransactionTenderService::class)->aggregateStatus($transaction->tenders()->get())]);
        $this->assertSame('paid', $transaction->fresh()->payment_status);

        $this->assertSame(1, KitchenItem::count());
    }

    public function test_failed_intake_midway_creates_nothing_and_can_retry(): void
    {
        $transaction = $this->pendingSale('cash', [$this->product('Nasi', flagged: true), $this->product('Mie', flagged: true)]);

        // The second line fails to insert: the first must not stay behind, or the once-only guard would skip the rest forever.
        $creating = 0;
        KitchenItem::creating(function () use (&$creating) {
            if (++$creating === 2) {
                throw new RuntimeException('second line fails');
            }
        });
        $transaction->forceFill(['payment_status' => 'paid']);

        try {
            app(KitchenIntakeService::class)->intake($transaction);
            $this->fail('intake should have thrown on the second line');
        } catch (RuntimeException) {
            $this->assertSame(0, KitchenItem::where('transaction_id', $transaction->id)->count());
        } finally {
            KitchenItem::flushEventListeners();
        }

        app(KitchenIntakeService::class)->intake($transaction); // the retry records every line

        $this->assertSame(2, KitchenItem::where('transaction_id', $transaction->id)->count());
    }

    public function test_dine_order_paid_at_counter_adds_kitchen_items(): void
    {
        $rujak = $this->product('Rujak', flagged: true);
        $order = $this->acceptedDineOrder($rujak);
        $this->assertSame(0, KitchenItem::count()); // accepted, but the bill is still open

        $this->payAtCounter($order);

        $item = KitchenItem::sole();
        $this->assertSame($order->transaction_id, $item->transaction_id);
        $this->assertSame($rujak->id, $item->product_id);
        $this->assertSame(2, $item->qty);
        $this->assertSame($this->warehouse->id, $item->warehouse_id);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->status);
        $this->assertSame($this->cashier->id, $item->accepted_by);
    }

    public function test_dine_in_item_note_is_copied_to_the_kitchen_item(): void
    {
        $rujak = $this->product('Rujak', flagged: true);
        $table = DiningTable::create(['name' => 'Meja 8', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);
        $this->post(route('dine-order.store', $table->token), [
            'items' => [['product_id' => $rujak->id, 'qty' => 2, 'note' => 'tanpa cabai']],
            'payment_option' => 'pay_at_counter',
        ])->assertSessionHasNoErrors();
        $order = DineOrder::firstOrFail();
        $this->actingAs($this->cashier)->post(route('dine-orders.accept', $order))->assertSessionHasNoErrors();

        $this->payAtCounter($order->fresh());

        $this->assertSame('tanpa cabai', KitchenItem::sole()->note);
    }

    public function test_pos_line_has_no_kitchen_note(): void
    {
        $this->addToCart($this->product('Nasi goreng', flagged: true));

        $this->actingAs($this->cashier)
            ->post(route('transactions.store'), ['payment_method' => 'cash', 'cash' => 50000])
            ->assertSessionHasNoErrors();

        $this->assertNull(KitchenItem::sole()->note);
    }

    public function test_only_flagged_lines_reach_the_kitchen(): void
    {
        $nasi = $this->product('Nasi goreng', flagged: true);
        $mineral = $this->product('Air mineral', flagged: false);
        $this->addToCart($nasi);
        $this->addToCart($mineral);

        $this->actingAs($this->cashier)
            ->post(route('transactions.store'), ['payment_method' => 'cash', 'cash' => 50000])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, TransactionDetail::count());
        $this->assertSame([$nasi->id], KitchenItem::pluck('product_id')->all());
    }

    public function test_failed_and_expired_payments_add_no_kitchen_items(): void
    {
        $this->enableMidtrans();
        $nasi = $this->product('Nasi goreng', flagged: true);

        $old = $this->pendingSale('midtrans', [$nasi], 'INV-OLD', now()->subHours(30));
        $this->artisan('transactions:expire')->assertSuccessful();
        $this->assertSame('failed', $old->fresh()->payment_status);

        $denied = $this->pendingSale('midtrans', [$nasi], 'INV-DENIED');
        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($denied, 'deny'))->assertOk();
        $this->assertSame('failed', $denied->fresh()->payment_status);

        $this->assertSame(0, KitchenItem::count());
    }

    public function test_paid_twice_creates_kitchen_items_once(): void
    {
        $this->enableMidtrans();
        $transaction = $this->pendingSale('midtrans', [$this->product('Nasi goreng', flagged: true)]);
        $payload = $this->midtransPayload($transaction, 'settlement');

        $this->postJson(route('webhooks.midtrans'), $payload)->assertOk();
        $this->postJson(route('webhooks.midtrans'), $payload)->assertOk(); // the gateway retries
        app(KitchenIntakeService::class)->intake($transaction->fresh());

        $this->assertSame(1, KitchenItem::count());
    }

    // REQ-006 AC-02, AC-03, AC-04 (writing half): the item is given its station once, when it enters the kitchen.
    public function test_item_gets_the_station_of_its_product_and_unmapped_goes_to_the_default(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $drink = $this->product('Es teh', flagged: true);
        $drink->update(['kitchen_station_id' => $bar->id]);
        $food = $this->product('Nasi goreng', flagged: true);

        $this->paidSale([$drink, $food]);

        $items = KitchenItem::orderBy('id')->get();
        $this->assertSame([$drink->id, $food->id], $items->pluck('product_id')->all());
        $this->assertSame([$bar->id, $this->defaultStationId()], $items->pluck('kitchen_station_id')->all());
    }

    public function test_a_product_still_pointing_at_a_deleted_station_goes_to_the_default_station(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $drink = $this->product('Es teh', flagged: true);
        $drink->update(['kitchen_station_id' => $bar->id]);
        $bar->delete(); // deleted in a race: the product was not moved yet

        $this->paidSale([$drink]);

        $this->assertSame($this->defaultStationId(), KitchenItem::sole()->kitchen_station_id);
    }

    public function test_moving_the_product_later_does_not_change_the_item(): void
    {
        $bar = KitchenStation::create(['name' => 'Bar']);
        $grill = KitchenStation::create(['name' => 'Grill']);
        $dish = $this->product('Sate', flagged: true);
        $dish->update(['kitchen_station_id' => $bar->id]);
        $transaction = $this->paidSale([$dish]);

        $dish->update(['kitchen_station_id' => $grill->id]);
        app(KitchenIntakeService::class)->intake($transaction->fresh()); // a retry writes nothing new

        $this->assertSame($bar->id, KitchenItem::sole()->kitchen_station_id);
    }

    public function test_retry_after_failed_payment_records_kitchen_items_once(): void
    {
        $this->enableMidtrans();
        $order = $this->acceptedDineOrder($this->product('Rujak', flagged: true));
        $transaction = $order->transaction;
        $this->mock(PaymentGatewayManager::class)
            ->shouldReceive('createTenderPayment')->once()
            ->andReturn(['reference' => 'ref-1', 'payment_url' => null, 'qr_string' => 'QR']);
        $this->actingAs($this->cashier)
            ->post(route('dine-orders.pay', $order), ['tenders' => [['method' => 'qris', 'amount' => 20000]]])
            ->assertSessionHasNoErrors();

        $this->postJson(route('webhooks.midtrans'), $this->midtransPayload($transaction, 'expire'))->assertOk();
        $this->assertSame('failed', $transaction->fresh()->payment_status);
        $this->assertSame(0, KitchenItem::count());

        $this->payAtCounter($order);

        $this->assertSame('paid', $transaction->fresh()->payment_status);
        $this->assertSame(1, KitchenItem::count());
    }

    public function test_changing_the_flag_later_does_not_touch_existing_kitchen_items(): void
    {
        $nasi = $this->product('Nasi goreng', flagged: true);
        $mineral = $this->product('Air mineral', flagged: false);
        $this->addToCart($nasi);
        $this->addToCart($mineral);
        $this->actingAs($this->cashier)
            ->post(route('transactions.store'), ['payment_method' => 'cash', 'cash' => 50000])
            ->assertSessionHasNoErrors();
        $before = KitchenItem::sole()->only(['id', 'product_id', 'status', 'accepted_at']);

        $nasi->update(['needs_preparation' => false]);
        app(KitchenIntakeService::class)->intake(Transaction::firstOrFail());

        $this->assertSame(1, KitchenItem::count());
        $this->assertEquals($before, KitchenItem::sole()->only(['id', 'product_id', 'status', 'accepted_at']));
    }

    public function test_second_paid_flip_after_flag_change_adds_nothing(): void
    {
        $nasi = $this->product('Nasi goreng', flagged: true);
        $mineral = $this->product('Air mineral', flagged: false);
        $this->addToCart($nasi);
        $this->addToCart($mineral);
        $this->actingAs($this->cashier)
            ->post(route('transactions.store'), ['payment_method' => 'cash', 'cash' => 50000])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, KitchenItem::count());

        $mineral->update(['needs_preparation' => true]);
        $transaction = Transaction::firstOrFail();
        $transaction->update(['payment_status' => 'pending']);
        $transaction->update(['payment_status' => 'paid']); // paid a second time

        $this->assertSame([$nasi->id], KitchenItem::pluck('product_id')->all());
    }

    public function test_paid_sale_without_a_warehouse_is_skipped_and_payment_still_succeeds(): void
    {
        $transaction = $this->pendingSale('cash', [$this->product('Nasi goreng', flagged: true)]);
        $transaction->update(['warehouse_id' => null]);

        $transaction->update(['payment_status' => 'paid']);

        $this->assertSame('paid', $transaction->fresh()->payment_status);
        $this->assertSame(0, KitchenItem::count());
    }

    public function test_settling_a_pre_release_pay_later_sale_adds_no_kitchen_items(): void
    {
        $transaction = $this->pendingSale('pay_later', [$this->product('Nasi goreng', flagged: true)]);
        $transaction->forceFill(['is_pay_later' => true, 'payment_status' => 'unpaid'])->save();

        // Settling it later changes the method (a transfer, a gateway) and then pays: the flag still marks it pay later.
        $transaction->update(['payment_method' => 'bank_transfer']);
        $transaction->update(['payment_status' => 'paid']); // the customer pays the receivable after release

        $this->assertSame(0, KitchenItem::count());
    }

    public function test_intake_failure_does_not_roll_back_the_payment(): void
    {
        Exceptions::fake();
        $this->mock(KitchenIntakeService::class)->shouldReceive('intake')->andThrow(new RuntimeException('kitchen down'));

        // A sale created paid (checkout) and a pending one flipped to paid (hook): both stay paid.
        $this->addToCart($this->product('Nasi goreng', flagged: true));
        $this->actingAs($this->cashier)
            ->post(route('transactions.store'), ['payment_method' => 'cash', 'cash' => 50000])
            ->assertSessionHasNoErrors();
        $pending = $this->pendingSale('bank_transfer', [$this->product('Mie', flagged: true)], 'INV-KITCHEN-2');
        $pending->update(['payment_status' => 'paid']);

        $this->assertSame(['paid', 'paid'], Transaction::orderBy('id')->pluck('payment_status')->all());
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'kitchen down');
        $this->assertSame(0, KitchenItem::count());
    }

    // #341: the line remembers whether it needed preparation when it was sold.
    public function test_web_checkout_snapshots_the_flag_on_each_line(): void
    {
        $nasi = $this->product('Nasi goreng', flagged: true);
        $mineral = $this->product('Air mineral', flagged: false);
        $this->addToCart($nasi);
        $this->addToCart($mineral);

        $this->actingAs($this->cashier)
            ->post(route('transactions.store'), ['payment_method' => 'cash', 'cash' => 50000])
            ->assertSessionHasNoErrors();

        $details = Transaction::firstOrFail()->details->keyBy('product_id');
        $this->assertTrue($details[$nasi->id]->needs_preparation);
        $this->assertFalse($details[$mineral->id]->needs_preparation);
    }

    public function test_api_checkout_snapshots_the_flag(): void
    {
        $nasi = $this->product('Nasi goreng', flagged: true);
        $mineral = $this->product('Air mineral', flagged: false);
        Sanctum::actingAs($this->cashier, ['*']);

        $this->postJson('/api/v1/pos/cart', ['product_id' => $nasi->id, 'qty' => 1])->assertSuccessful();
        $this->postJson('/api/v1/pos/cart', ['product_id' => $mineral->id, 'qty' => 1])->assertSuccessful();
        $this->postJson('/api/v1/pos/checkout', ['payment_method' => 'cash', 'cash' => 50000])->assertCreated();

        $details = Transaction::firstOrFail()->details->keyBy('product_id');
        $this->assertTrue($details[$nasi->id]->needs_preparation);
        $this->assertFalse($details[$mineral->id]->needs_preparation);
    }

    public function test_dine_order_accept_snapshots_the_flag_so_a_change_before_payment_does_not_matter(): void
    {
        $rujak = $this->product('Rujak', flagged: true);
        $order = $this->acceptedDineOrder($rujak);
        $this->assertTrue($order->transaction->details->sole()->needs_preparation);

        $rujak->update(['needs_preparation' => false]); // changed while the bill is open
        $this->payAtCounter($order);

        $this->assertSame($rujak->id, KitchenItem::sole()->product_id);
    }

    public function test_a_product_flagged_after_the_sale_is_not_sent_to_the_kitchen(): void
    {
        $mineral = $this->product('Air mineral', flagged: false);
        $sale = $this->soldSale([$mineral]);

        $mineral->update(['needs_preparation' => true]);
        $sale->update(['payment_status' => 'paid']); // intake runs now
        $this->artisan('kitchen:recover-intake')->assertSuccessful(); // and so does the self-heal job

        $this->assertSame(0, KitchenItem::count());
    }

    public function test_a_product_unflagged_after_the_sale_still_goes_to_the_kitchen(): void
    {
        $nasi = $this->product('Nasi goreng', flagged: true);
        $sale = $this->soldSale([$nasi]);

        $nasi->update(['needs_preparation' => false]);
        $sale->update(['payment_status' => 'paid']);
        $this->assertSame([$nasi->id], KitchenItem::pluck('product_id')->all());

        // The self-heal job takes the same decision when the intake never ran.
        KitchenItem::query()->delete();
        $this->artisan('kitchen:recover-intake')->assertSuccessful();
        $this->assertSame([$nasi->id], KitchenItem::pluck('product_id')->all());
    }

    public function test_a_line_without_a_snapshot_falls_back_to_the_product_flag(): void
    {
        $nasi = $this->product('Nasi goreng', flagged: true);
        $mineral = $this->product('Air mineral', flagged: false);
        $frozen = $this->product('Es teh', flagged: true);
        $sale = $this->pendingSale('cash', [$nasi, $mineral, $frozen]); // written like a pre-release line: no snapshot
        $this->assertNull($sale->details()->where('product_id', $nasi->id)->firstOrFail()->needs_preparation);
        $sale->details()->where('product_id', $frozen->id)->update(['needs_preparation' => false]); // one line of the sale has a snapshot

        $sale->update(['payment_status' => 'paid']);

        // Decided per line: the null lines read the product, the frozen line its own snapshot.
        $this->assertSame([$nasi->id], KitchenItem::pluck('product_id')->all());
    }

    private function product(string $title, bool $flagged): Product
    {
        $product = Product::create([
            'title' => $title, 'sku' => 'SKU-'.uniqid(), 'barcode' => 'BC-'.uniqid(), 'buy_price' => 5000, 'sell_price' => 10000,
            'stock' => 100, 'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0,
            'category_id' => $this->category->id, 'needs_preparation' => $flagged,
        ]);
        $this->warehouse->products()->attach($product->id, ['stock' => 100]);

        return $product;
    }

    /** A pending cash sale of $products whose lines carry the sale-time snapshot, as a checkout writes it. */
    private function soldSale(array $products): Transaction
    {
        $sale = $this->pendingSale('cash', $products, 'INV-SNAPSHOT');
        $sale->details()->with('product')->get()->each(fn (TransactionDetail $line) => $line->update(['needs_preparation' => $line->product->needs_preparation]));

        return $sale;
    }

    private function defaultStationId(): int
    {
        return KitchenStation::where('is_default', true)->value('id');
    }

    /** A sale of $products that has just turned paid: the model hook runs the kitchen intake. */
    private function paidSale(array $products): Transaction
    {
        $transaction = $this->pendingSale('cash', $products, 'INV-STATION');
        $transaction->update(['payment_status' => 'paid']);

        return $transaction;
    }

    private function addToCart(Product $product, int $qty = 1): void
    {
        Cart::create(['cashier_id' => $this->cashier->id, 'product_id' => $product->id, 'qty' => $qty, 'price' => 10000]);
    }

    /** A gateway sale awaiting its webhook: 10000 per product, one line each. */
    private function pendingSale(string $method, array $products, string $invoice = 'INV-KITCHEN', $createdAt = null): Transaction
    {
        $transaction = new Transaction([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => $invoice,
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0,
            'grand_total' => 10000 * count($products), 'payment_method' => $method, 'payment_status' => 'pending',
        ]);
        $transaction->created_at = $createdAt ?? now();
        $transaction->save();

        foreach ($products as $product) {
            TransactionDetail::create([
                'transaction_id' => $transaction->id, 'product_id' => $product->id, 'qty' => 1, 'conversion_factor' => 1,
                'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000,
            ]);
        }

        return $transaction;
    }

    private function enableMidtrans(): void
    {
        PaymentSetting::create([
            'default_gateway' => 'cash', 'midtrans_enabled' => true, 'midtrans_server_key' => 'sk-test', 'midtrans_client_key' => 'ck-test',
        ]);
    }

    private function midtransPayload(Transaction $transaction, string $status): array
    {
        $gross = (string) (int) $transaction->grand_total;

        return [
            'order_id' => $transaction->invoice,
            'status_code' => '200',
            'gross_amount' => $gross,
            'transaction_status' => $status,
            'transaction_id' => 'mt-'.$transaction->id,
            'signature_key' => hash('sha512', $transaction->invoice.'200'.$gross.'sk-test'),
        ];
    }

    /** A guest QR order for 2 x 10000, accepted by the cashier: an open bill, nothing paid. */
    private function acceptedDineOrder(Product $product): DineOrder
    {
        $table = DiningTable::create(['name' => 'Meja 7', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);
        $this->post(route('dine-order.store', $table->token), [
            'items' => [['product_id' => $product->id, 'qty' => 2]],
            'payment_option' => 'pay_at_counter',
        ])->assertSessionHasNoErrors();
        $order = DineOrder::firstOrFail();
        $this->actingAs($this->cashier)->post(route('dine-orders.accept', $order))->assertSessionHasNoErrors();

        return $order->fresh();
    }

    private function payAtCounter(DineOrder $order): void
    {
        $this->actingAs($this->cashier)
            ->post(route('dine-orders.pay', $order), ['tenders' => [['method' => 'cash', 'amount' => 20000, 'cash_received' => 25000]]])
            ->assertSessionHasNoErrors();
    }
}
