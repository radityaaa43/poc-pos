<?php

namespace Tests\Feature\Transactions;

use App\Models\BankAccount;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\CheckoutPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Service charge and PB1 on a POS sale (#450). Two products of Rp 10,000 with 11 % PPN at an outlet with a
 * 5 % service charge: Rp 20,000 + 1,000 service + 2,310 PPN = Rp 23,310.
 */
class BillTotalCheckoutTest extends TestCase
{
    use RefreshDatabase;

    // What a tampered client claims; the server never reads any of it.
    private const TAMPERED = ['grand_total' => 1, 'service_charge' => 999999, 'tax_total' => 0, 'tax_kind' => 'pb1'];

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

        $this->outlet = Outlet::create(['code' => 'SVC', 'name' => 'Service']);
        $this->warehouse = Warehouse::create([
            'code' => 'WH-SVC', 'name' => 'Gudang', 'status' => 'active', 'outlet_id' => $this->outlet->id,
        ]);
        $category = Category::create(['name' => 'Kategori', 'image' => '', 'description' => '']);
        $this->product = Product::create([
            'title' => 'Nasi', 'barcode' => 'SVC-001', 'sku' => 'SKU-SVC-001', 'image' => '', 'description' => '',
            'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 50, 'category_id' => $category->id,
            'tax_type' => 'exclusive', 'tax_rate' => 11,
        ]);
        $this->product->warehouses()->attach($this->warehouse->id, ['stock' => 50]);

        $this->cashier = User::factory()->create();
        $this->cashier->markEmailAsVerified();
        $this->cashier->givePermissionTo(['transactions-access', 'cashier-shifts-access']);
        $this->cashier->outlets()->attach($this->outlet->id, ['is_default' => true]);
    }

    private function openShift(): void
    {
        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 0, null, $this->warehouse->id);
    }

    private function fillCart(): void
    {
        Cart::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id, 'qty' => 2, 'price' => 20000,
        ]);
    }

    private function charge(string $rate = '5'): void
    {
        Setting::setForOutlet('service_charge_rate', $rate, $this->outlet);
    }

    private function sell(array $body = [])
    {
        return $this->actingAs($this->cashier)
            ->from(route('transactions.index'))
            ->post(route('transactions.store'), $body + ['order_type' => 'in_store', 'discount' => 0, 'cash' => 50000]);
    }

    private function assertBill(Transaction $sale, array $expected): void
    {
        $this->assertSame($expected, [
            'service_charge' => $sale->service_charge,
            'tax_kind' => $sale->tax_kind,
            'tax_total' => $sale->tax_total,
            'tax_base' => $sale->tax_base,
            'grand_total' => $sale->grand_total,
        ]);
    }

    private function dineInBill(): array
    {
        return ['service_charge' => 1000, 'tax_kind' => 'ppn', 'tax_total' => 2310, 'tax_base' => 21000, 'grand_total' => 23310];
    }

    public function test_preview_matches_checkout(): void
    {
        $this->charge();
        $this->openShift();
        $this->fillCart();

        $summary = $this->actingAs($this->cashier)
            ->postJson(route('transactions.pricing-preview'), ['order_type' => 'in_store'])
            ->assertOk()
            ->assertJsonPath('data.summary.service_charge', 1000)
            ->assertJsonPath('data.summary.tax_kind', 'ppn')
            ->assertJsonPath('data.summary.grand_total', 23310)
            ->assertJsonPath('data.summary.bill_basis.after_discount', 20000)
            ->json('data.summary');

        $this->sell()->assertSessionHasNoErrors();

        $sale = Transaction::sole();
        $this->assertBill($sale, $this->dineInBill());
        $this->assertSame($summary['grand_total'], $sale->grand_total);
        $this->assertSame(11.0, (float) $sale->tax_rate);
        $this->assertSame(26690, (int) $sale->change);
    }

    public function test_default_outlet_sale_stores_ppn_and_no_service_charge(): void
    {
        $this->openShift();
        $this->fillCart();

        $this->sell()->assertSessionHasNoErrors();

        $this->assertBill(Transaction::sole(), [
            'service_charge' => 0, 'tax_kind' => 'ppn', 'tax_total' => 2200, 'tax_base' => 20000, 'grand_total' => 22200,
        ]);
    }

    public function test_pb1_sale_stores_its_kind_and_rate(): void
    {
        $this->charge();
        Setting::setForOutlet('tax_kind', 'pb1', $this->outlet);
        $this->openShift();
        $this->fillCart();

        $this->sell()->assertSessionHasNoErrors();

        $sale = Transaction::sole();
        $this->assertBill($sale, [
            'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_total' => 2100, 'tax_base' => 21000, 'grand_total' => 23100,
        ]);
        $this->assertSame(10.0, (float) $sale->tax_rate);
    }

    public function test_client_totals_are_ignored_by_the_web_store(): void
    {
        $this->charge();
        $this->openShift();
        $this->fillCart();

        $this->sell(self::TAMPERED)->assertSessionHasNoErrors();

        $this->assertBill(Transaction::sole(), $this->dineInBill());
    }

    public function test_client_totals_are_ignored_by_the_api_checkout(): void
    {
        $this->charge();
        $this->openShift();
        $this->fillCart();
        Sanctum::actingAs($this->cashier, ['*']);

        $this->postJson('/api/v1/pos/checkout', self::TAMPERED + ['order_type' => 'in_store', 'payment_method' => 'cash', 'cash' => 50000])
            ->assertCreated()
            ->assertJsonPath('data.service_charge', 1000)
            ->assertJsonPath('data.tax_kind', 'ppn');

        $this->assertBill(Transaction::sole(), $this->dineInBill());
    }

    public function test_sync_recomputes_service_charge_from_outlet_settings(): void
    {
        $this->charge();
        $this->openShift();
        Sanctum::actingAs($this->cashier, ['*']);
        $queued = fn (string $uuid, int $cash) => self::TAMPERED + [
            'client_uuid' => $uuid, 'order_type' => 'in_store', 'cash' => $cash,
            'items' => [['product_id' => $this->product->id, 'qty' => 2, 'unit_id' => null]],
        ];

        $this->postJson('/api/v1/pos/transactions/sync', ['transactions' => [
            $queued('550e8400-e29b-41d4-a716-446655440001', 23000),
            $queued('550e8400-e29b-41d4-a716-446655440002', 23310),
        ]])->assertOk()
            // Cash that covered the screen's old total but not the server's is refused, not rounded away.
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.1.status', 'synced');

        $this->assertBill(Transaction::sole(), $this->dineInBill());
    }

    public function test_checkout_uses_settings_at_checkout_time(): void
    {
        $this->openShift();
        $this->fillCart();
        $this->actingAs($this->cashier)
            ->postJson(route('transactions.pricing-preview'), ['order_type' => 'in_store'])
            ->assertJsonPath('data.summary.grand_total', 22200);

        $this->charge();
        $this->sell()->assertSessionHasNoErrors();

        $this->assertBill(Transaction::sole(), $this->dineInBill());
    }

    public function test_cash_below_total_with_service_is_refused(): void
    {
        $this->charge();
        $this->openShift();
        $this->fillCart();

        $this->sell(['cash' => 23000])->assertSessionHasErrors('cash');

        $this->assertSame(0, Transaction::count());
    }

    public function test_tenders_must_sum_to_total_with_service(): void
    {
        $this->charge();
        $this->openShift();
        $this->fillCart();
        $bank = BankAccount::create(['bank_name' => 'BCA', 'account_number' => '1', 'account_name' => 'Toko', 'is_active' => true]);
        $tenders = fn (int $transfer) => ['tenders' => [
            ['method' => 'cash', 'amount' => 10000, 'cash_received' => 10000],
            ['method' => 'bank_transfer', 'amount' => $transfer, 'bank_account_id' => $bank->id],
        ]];

        $this->sell($tenders(12200))->assertSessionHasErrors();
        $this->assertSame(0, Transaction::count());

        $this->sell($tenders(13310))->assertSessionHasNoErrors();
        $this->assertBill(Transaction::sole(), $this->dineInBill());
    }

    public function test_pay_later_includes_service(): void
    {
        $this->charge();
        Setting::setForOutlet('pay_later_enabled', '1', $this->outlet);
        $customer = Customer::create(['name' => 'Budi', 'no_telp' => '62811', 'address' => 'Jl. Satu']);
        $customer->forceFill(['may_buy_on_credit' => true])->save();
        $this->openShift();
        $this->fillCart();

        $this->sell(['pay_later' => '1', 'customer_id' => $customer->id, 'due_date' => now()->addWeek()->toDateString(), 'cash' => 0])
            ->assertSessionHasNoErrors();

        $this->assertBill(Transaction::sole(), $this->dineInBill());
        $this->assertSame(23310, (int) Receivable::sole()->total);
    }

    public function test_api_preview_and_cart_take_the_order_type(): void
    {
        $this->charge();
        $this->openShift();
        $this->fillCart();
        Sanctum::actingAs($this->cashier, ['*']);

        foreach (['/api/v1/transactions/pricing-preview', '/api/v1/pos/cart'] as $url) {
            $this->getJson($url.'?order_type=in_store')->assertOk()
                ->assertJsonPath('data.summary.service_charge', 1000)
                ->assertJsonPath('data.summary.tax_kind', 'ppn')
                ->assertJsonPath('data.summary.grand_total', 23310);
            // No order type, or a takeaway: the keys stay, the charge does not.
            foreach (['', '?order_type=takeaway'] as $query) {
                $this->getJson($url.$query)->assertOk()
                    ->assertJsonPath('data.summary.service_charge', 0)
                    ->assertJsonPath('data.summary.tax_kind', 'ppn')
                    ->assertJsonPath('data.summary.grand_total', 22200);
            }
        }
        $this->getJson('/api/v1/transactions/pricing-preview?order_type=dine')->assertUnprocessable();
    }

    public function test_pos_page_opens_with_the_dine_in_bill(): void
    {
        $this->charge();
        $this->openShift();
        $this->fillCart();

        $this->actingAs($this->cashier)->get(route('transactions.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('initialPricingPreview.summary.service_charge', 1000)
                ->where('initialPricingPreview.summary.grand_total', 23310));
    }

    public function test_preview_without_a_shift_uses_the_defaults(): void
    {
        $this->charge();
        $this->fillCart();

        $summary = app(CheckoutPreviewService::class)
            ->checkout($this->cashier, null, ['order_type' => 'in_store'], Cart::where('cashier_id', $this->cashier->id)->get())['summary'];

        $this->assertSame(0, $summary['service_charge']);
        $this->assertSame(22200, $summary['grand_total']);
    }
}
