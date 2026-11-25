<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PayLaterTest extends TestCase
{
    use RefreshDatabase;

    private const OFF_MESSAGE = 'Bayar belakangan tidak aktif di outlet ini';

    private Outlet $outlet;

    private Warehouse $warehouse;

    private User $cashier;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions-access', 'cashier-shifts-access'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true]);
        $this->warehouse = Warehouse::create([
            'code' => 'WH-1',
            'name' => 'Gudang Outlet 1',
            'status' => 'active',
            'outlet_id' => $this->outlet->id,
        ]);
        $category = Category::create(['name' => 'Kategori', 'image' => '', 'description' => '']);
        $this->product = Product::create([
            'title' => 'Produk Kasir',
            'barcode' => 'PL-001',
            'sku' => 'SKU-PL-001',
            'image' => '',
            'description' => '',
            'buy_price' => 5000,
            'sell_price' => 10000,
            'stock' => 50,
            'category_id' => $category->id,
            'tax_type' => 'exclusive',
            'tax_rate' => 0,
            'min_stock' => 0,
            'max_stock' => 100,
            'is_composite' => false,
        ]);
        $this->product->warehouses()->attach($this->warehouse->id, ['stock' => 50]);

        $this->cashier = User::factory()->create();
        $this->cashier->markEmailAsVerified();
        $this->cashier->givePermissionTo(['transactions-access', 'cashier-shifts-access']);

        app(CashierShiftService::class)->openShift(
            cashier: $this->cashier,
            actor: $this->cashier,
            openingCash: 100000,
            notes: null,
            warehouseId: $this->warehouse->id,
        );
    }

    private function payLaterOn(): void
    {
        Setting::setForOutlet('pay_later_enabled', '1', $this->outlet);
    }

    private function creditCustomer(): Customer
    {
        $customer = Customer::create(['name' => 'Budi', 'no_telp' => '62811', 'address' => 'Jl. Satu']);
        // may_buy_on_credit is not fillable: only the approver endpoint sets it.
        $customer->forceFill(['may_buy_on_credit' => true])->save();

        return $customer;
    }

    private function plainCustomer(): Customer
    {
        return Customer::create(['name' => 'Sari', 'no_telp' => '62822', 'address' => 'Jl. Dua']);
    }

    /** Put one line in the cashier's cart. */
    private function fillCart(): void
    {
        Cart::create([
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'qty' => 1,
            'price' => $this->product->sell_price,
        ]);
    }

    private function api(array $body)
    {
        Sanctum::actingAs($this->cashier, ['*']);

        return $this->postJson('/api/v1/pos/checkout', ['payment_method' => 'pay_later'] + $body);
    }

    private function web(array $body)
    {
        return $this->from(route('transactions.index'))
            ->actingAs($this->cashier)
            ->post(route('transactions.store'), [
                'pay_later' => '1',
                'discount' => 0,
                'grand_total' => $this->product->sell_price,
            ] + $body);
    }

    private function dueDate(): string
    {
        return now()->addDays(7)->toDateString();
    }

    public function test_pay_later_sale_is_flagged_is_pay_later(): void
    {
        $this->payLaterOn();

        $this->fillCart();
        $this->api(['customer_id' => $this->creditCustomer()->id, 'due_date' => $this->dueDate()])->assertSuccessful();
        $creditSale = Transaction::latest('id')->firstOrFail();

        $this->fillCart();
        $this->api(['note' => 'Meja 4'])->assertSuccessful(); // a guest
        $guestSale = Transaction::latest('id')->firstOrFail();

        // A later gateway settle overwrites payment_method, so the flag is what marks a pay-later bill.
        $creditSale->update(['payment_method' => 'midtrans']);
        $this->assertTrue($creditSale->fresh()->is_pay_later);
        $this->assertTrue($guestSale->is_pay_later);
    }

    public function test_release_marks_existing_pay_later_sales(): void
    {
        $this->payLaterOn();
        $this->fillCart();
        $this->api(['customer_id' => $this->creditCustomer()->id, 'due_date' => $this->dueDate()])->assertSuccessful();
        $payLater = Transaction::sole();
        $cash = Transaction::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => 'INV-OLD-CASH',
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => 10000,
            'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);
        $payLater->forceFill(['is_pay_later' => false])->save(); // as a sale made before the column existed

        $migration = require database_path('migrations/2026_11_30_100002_add_is_pay_later_to_transactions_table.php');
        $migration->down();
        $migration->up();

        $this->assertTrue($payLater->fresh()->is_pay_later);
        $this->assertFalse($cash->fresh()->is_pay_later);
    }

    public function test_pay_later_sale_goes_to_the_kitchen_unpaid(): void
    {
        $this->payLaterOn();
        $this->product->update(['needs_preparation' => true]);
        $this->fillCart();

        $this->api(['customer_id' => $this->creditCustomer()->id, 'due_date' => $this->dueDate()])->assertSuccessful();

        $transaction = Transaction::sole();
        $this->assertSame('unpaid', $transaction->payment_status);
        $item = KitchenItem::sole();
        $this->assertSame($transaction->id, $item->transaction_id);
        $this->assertSame($this->product->id, $item->product_id);
        $this->assertSame(KitchenItem::STATUS_ACCEPTED, $item->status);
    }

    public function test_settling_the_pay_later_sale_does_not_record_it_in_the_kitchen_again(): void
    {
        $this->payLaterOn();
        $this->product->update(['needs_preparation' => true]);
        $this->fillCart();
        $this->api(['customer_id' => $this->creditCustomer()->id, 'due_date' => $this->dueDate()])->assertSuccessful();
        $itemId = KitchenItem::sole()->id;

        Transaction::sole()->update(['payment_status' => 'paid']); // the receivable is paid later

        $this->assertSame([$itemId], KitchenItem::pluck('id')->all());
    }

    public function test_checkout_refuses_pay_later_when_outlet_setting_off(): void
    {
        $customer = $this->creditCustomer(); // even a credit customer: the outlet setting comes first

        $this->fillCart();
        $this->api(['customer_id' => $customer->id, 'due_date' => $this->dueDate()])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pay_later'])
            ->assertJsonPath('errors.pay_later.0', self::OFF_MESSAGE);
        $this->assertSame(0, Transaction::count());
        $this->assertSame(1, Cart::count(), 'the cart is kept so the cashier can pay another way');

        $this->web(['customer_id' => $customer->id, 'due_date' => $this->dueDate()])
            ->assertSessionHasErrors(['pay_later' => self::OFF_MESSAGE]);
        $this->assertSame(0, Transaction::count());
    }

    public function test_pay_later_is_per_outlet(): void
    {
        $other = Outlet::create(['code' => 'OUT-2', 'name' => 'Outlet 2', 'is_active' => true]);
        Setting::setForOutlet('pay_later_enabled', '1', $other);

        $this->fillCart();
        $this->api(['note' => 'bon tamu'])
            ->assertStatus(422)
            ->assertJsonPath('errors.pay_later.0', self::OFF_MESSAGE);
    }

    public function test_guest_pay_later_requires_note(): void
    {
        $this->payLaterOn();

        $this->fillCart();
        $this->api(['note' => 'x'])->assertStatus(422)->assertJsonValidationErrors(['note']);
        $this->api([])->assertStatus(422)->assertJsonValidationErrors(['note']);
        $this->assertSame(0, Transaction::count());

        $this->api(['note' => 'ab'])
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'pay_later')
            ->assertJsonPath('data.payment_status', 'unpaid');
    }

    public function test_web_guest_pay_later_requires_note(): void
    {
        $this->payLaterOn();

        $this->fillCart();
        $this->web(['note' => 'x'])->assertSessionHasErrors(['note']);
        $this->assertSame(0, Transaction::count());

        $this->web(['note' => 'ab'])->assertSessionHasNoErrors();
        $this->assertSame('unpaid', Transaction::sole()->payment_status);
    }

    public function test_credit_customer_gets_receivable(): void
    {
        $this->payLaterOn();
        $customer = $this->creditCustomer();

        $this->fillCart();
        $this->api(['customer_id' => $customer->id, 'due_date' => $this->dueDate()])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'unpaid');

        $receivable = Receivable::sole();
        $this->assertSame($customer->id, $receivable->customer_id);
        $this->assertSame($this->dueDate(), $receivable->due_date->toDateString());
    }

    public function test_credit_customer_pay_later_still_requires_a_due_date(): void
    {
        $this->payLaterOn();

        $this->fillCart();
        $this->api(['customer_id' => $this->creditCustomer()->id])->assertStatus(422);
        $this->assertSame(0, Transaction::count());
    }

    public function test_non_credit_customer_and_guest_get_none(): void
    {
        $this->payLaterOn();

        $this->fillCart();
        $this->api(['customer_id' => $this->plainCustomer()->id])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'unpaid');

        $this->fillCart();
        $this->api(['note' => 'bon tamu'])
            ->assertCreated()
            ->assertJsonPath('data.payment_status', 'unpaid');

        $this->assertSame(2, Transaction::where('payment_method', 'pay_later')->count());
        $this->assertSame(0, Receivable::count());
    }

    public function test_sync_rejects_queued_pay_later_with_message(): void
    {
        $this->payLaterOn(); // the old entry is refused even where pay later is on
        $uuid = '660e8400-e29b-41d4-a716-446655440099';
        Sanctum::actingAs($this->cashier, ['*']);

        $this->postJson('/api/v1/pos/transactions/sync', [
            'transactions' => [[
                'client_uuid' => $uuid,
                'customer_id' => $this->creditCustomer()->id,
                'pay_later' => true,
                'due_date' => $this->dueDate(),
                'cash' => 0,
                'items' => [['product_id' => $this->product->id, 'qty' => 1]],
            ]],
        ])->assertOk()
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.reason', 'Bayar belakangan offline tidak lagi didukung — masukkan ulang saat online');

        $this->assertSame(0, Transaction::count());
        $this->assertSame(0, Receivable::count());
        $this->assertSame(50, $this->product->warehouses()->first()->pivot->stock);
    }

    public function test_revoking_credit_keeps_receivables(): void
    {
        $this->payLaterOn();
        $customer = $this->creditCustomer();

        $this->fillCart();
        $this->api(['customer_id' => $customer->id, 'due_date' => $this->dueDate()])->assertCreated();

        $approver = User::factory()->create();
        Permission::firstOrCreate(['name' => 'customers-credit-approve', 'guard_name' => 'web']);
        $approver->givePermissionTo('customers-credit-approve');
        $this->actingAs($approver)
            ->patch(route('customers.credit', $customer), ['may_buy_on_credit' => false])
            ->assertSessionHasNoErrors();

        $this->assertFalse($customer->fresh()->may_buy_on_credit);
        $this->assertSame(1, Receivable::where('customer_id', $customer->id)->where('status', 'unpaid')->count());
    }
}
