<?php

namespace Tests\Feature\PayLater;

use App\Models\Category;
use App\Models\Customer;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class OpenBillsListTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private User $cashier;

    private Product $product;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'transactions-access', 'guard_name' => 'web']);

        $this->outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true]);
        $this->warehouse = $this->warehouseFor($this->outlet, 'WH-1');
        $category = Category::create(['name' => 'Kategori', 'image' => '', 'description' => '']);
        $this->product = Product::create([
            'title' => 'Produk Kasir',
            'barcode' => 'OB-001',
            'sku' => 'SKU-OB-001',
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

        $this->cashier = User::factory()->create();
        $this->cashier->markEmailAsVerified();
        $this->cashier->givePermissionTo('transactions-access');
        $this->cashier->outlets()->attach($this->outlet->id, ['is_default' => true]);

        Setting::setForOutlet('pay_later_enabled', '1', $this->outlet);
    }

    private function warehouseFor(Outlet $outlet, string $code): Warehouse
    {
        return Warehouse::create(['code' => $code, 'name' => $code, 'status' => 'active', 'outlet_id' => $outlet->id]);
    }

    /** An unpaid pay-later sale of 2 x 10000 (a guest unless a customer is given). */
    private function payLaterBill(array $overrides = [], ?Warehouse $warehouse = null): Transaction
    {
        $bill = Transaction::create($overrides + [
            'cashier_id' => $this->cashier->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'invoice' => 'INV-OB-'.++$this->seq,
            'cash' => 0,
            'change' => 0,
            'discount' => 0,
            'grand_total' => 20000,
            'payment_method' => 'pay_later',
            'payment_status' => 'unpaid',
            'is_pay_later' => true,
            'note' => 'Meja 4',
        ]);
        TransactionDetail::create([
            'transaction_id' => $bill->id,
            'product_id' => $this->product->id,
            'qty' => 2,
            'conversion_factor' => 1,
            'base_unit_price' => 10000,
            'unit_price' => 10000,
            'price' => 10000,
        ]);

        return $bill;
    }

    /** A completed return of $qty units (10000 each) on the bill. */
    private function returnQty(Transaction $bill, int $qty): void
    {
        $return = SalesReturn::create([
            'code' => 'SR-OB-'.++$this->seq,
            'transaction_id' => $bill->id,
            'status' => 'completed',
            'total_return_amount' => $qty * 10000,
        ]);
        SalesReturnItem::create([
            'sales_return_id' => $return->id,
            'transaction_detail_id' => $bill->details()->firstOrFail()->id,
            'product_id' => $this->product->id,
            'qty_sold' => 2,
            'qty_return' => $qty,
            'unit_price' => 10000,
            'subtotal' => $qty * 10000,
            'return_reason' => 'rusak',
        ]);
    }

    public function test_open_bills_lists_guest_pay_later_with_label_and_age(): void
    {
        $guest = $this->payLaterBill();
        $guest->forceFill(['created_at' => now()->subHour()])->save();
        $registered = $this->payLaterBill(['customer_id' => Customer::create(['name' => 'Sari', 'no_telp' => '62822', 'address' => 'Jl. Dua'])->id, 'note' => null]);

        $table = DiningTable::create(['name' => 'Meja 7', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);
        $dineBill = $this->payLaterBill(['payment_method' => Transaction::METHOD_OPEN_BILL, 'is_pay_later' => false, 'note' => null]);
        $order = DineOrder::create([
            'dine_table_id' => $table->id,
            'transaction_id' => $dineBill->id,
            'status' => DineOrder::STATUS_ACCEPTED,
            'payment_option' => 'pay_at_counter',
            'payment_status' => 'unpaid',
            'subtotal' => 20000,
            'item_count' => 2,
        ]);

        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('openBills', 3)
                ->where('openBills.0.id', $guest->id)
                ->where('openBills.0.order_id', null)
                ->where('openBills.0.invoice', $guest->invoice)
                ->where('openBills.0.label', 'Meja 4')
                ->where('openBills.0.table', null)
                ->where('openBills.0.order_status', null)
                ->where('openBills.0.grand_total', 20000)
                ->where('openBills.0.amount', 20000)
                ->where('openBills.0.pending', false)
                ->where('openBills.0.created_at', $guest->fresh()->created_at->toIso8601String())
                ->where('openBills.1.id', $registered->id)
                ->where('openBills.1.label', 'Sari')
                // the dine row keeps its old keys and values
                ->where('openBills.2.id', $dineBill->id)
                ->where('openBills.2.order_id', $order->id)
                ->where('openBills.2.table', 'Meja 7')
                ->where('openBills.2.label', 'Meja 7')
                ->where('openBills.2.order_status', DineOrder::STATUS_ACCEPTED)
                ->where('openBills.2.grand_total', 20000));
    }

    // #445 BR-3
    public function test_a_named_bill_carries_its_order_name_on_the_page_and_in_the_polled_list(): void
    {
        $this->payLaterBill(['order_name' => 'Budi 7']);

        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page->where('openBills.0.order_name', 'Budi 7'));
        $this->actingAs($this->cashier)->getJson(route('transactions.open-bills'))
            ->assertJsonPath('openBills.0.order_name', 'Budi 7');
    }

    public function test_credit_bill_with_receivable_is_not_listed(): void
    {
        $customer = Customer::create(['name' => 'Budi', 'no_telp' => '62811', 'address' => 'Jl. Satu']);
        $credit = $this->payLaterBill(['customer_id' => $customer->id]);
        Receivable::create([
            'transaction_id' => $credit->id,
            'invoice' => $credit->invoice,
            'customer_id' => $customer->id,
            'total' => 20000,
            'paid' => 0,
            'due_date' => now()->addDays(7)->toDateString(),
            'status' => 'unpaid',
        ]);

        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page->has('openBills', 0));
    }

    public function test_other_outlet_bill_is_not_listed(): void
    {
        $other = Outlet::create(['code' => 'OUT-2', 'name' => 'Outlet 2', 'is_active' => true]);
        $this->payLaterBill([], $this->warehouseFor($other, 'WH-2'));

        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page->has('openBills', 0));
    }

    public function test_fully_returned_bill_is_not_listed(): void
    {
        $bill = $this->payLaterBill();
        $this->returnQty($bill, 2);

        $this->assertSame(0, $bill->remainingTotal());
        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page->has('openBills', 0));
    }

    public function test_partly_returned_bill_is_still_listed(): void
    {
        $bill = $this->payLaterBill();
        $this->returnQty($bill, 1);

        $this->assertSame(10000, $bill->remainingTotal());
        $this->actingAs($this->cashier)->get(route('transactions.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('openBills', 1)
                ->where('openBills.0.id', $bill->id)
                ->where('openBills.0.amount', 10000)
                ->where('openBills.0.grand_total', 20000));
    }

    public function test_expiry_command_skips_pay_later_bills(): void
    {
        $bill = $this->payLaterBill(['payment_method' => 'midtrans', 'payment_status' => 'pending']);
        $bill->forceFill(['created_at' => now()->subHours(30)])->save();
        $plain = $this->payLaterBill(['payment_method' => 'midtrans', 'payment_status' => 'pending', 'is_pay_later' => false, 'note' => null]);
        $plain->forceFill(['created_at' => now()->subHours(30)])->save();

        $this->artisan('transactions:expire')->assertSuccessful();

        $this->assertSame('pending', $bill->fresh()->payment_status);
        $this->assertSame('failed', $plain->fresh()->payment_status);
    }
}
