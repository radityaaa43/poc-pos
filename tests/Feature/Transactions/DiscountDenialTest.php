<?php

namespace Tests\Feature\Transactions;

use App\Http\Controllers\Apps\DiscountApprovalController;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\DiscountApprovalLog;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * A denied manual discount (#450, BR-4 and BR-6): 2 x Rp 10,000 with 11 % PPN, recomputed from what the sale
 * stored, whatever the outlet's rules say by then.
 */
class DiscountDenialTest extends TestCase
{
    use RefreshDatabase;

    private const PPN_WITH_SERVICE = ['service_charge_rate' => '5'];

    private const PB1_WITH_SERVICE = ['service_charge_rate' => '5', 'tax_kind' => 'pb1', 'pb1_rate' => '10'];

    private Outlet $outlet;

    private Warehouse $warehouse;

    private User $manager;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions-access', 'cashier-shifts-access', 'discounts-approve'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->outlet = Outlet::create(['code' => 'DSC', 'name' => 'Discount']);
        $this->warehouse = Warehouse::create([
            'code' => 'WH-DSC', 'name' => 'Gudang', 'status' => 'active', 'outlet_id' => $this->outlet->id,
        ]);
        $category = Category::create(['name' => 'Kategori', 'image' => '', 'description' => '']);
        $this->product = Product::create([
            'title' => 'Nasi', 'barcode' => 'DSC-001', 'sku' => 'SKU-DSC-001', 'image' => '', 'description' => '',
            'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 50, 'category_id' => $category->id,
            'tax_type' => 'exclusive', 'tax_rate' => 11,
        ]);
        $this->product->warehouses()->attach($this->warehouse->id, ['stock' => 50]);

        $this->manager = User::factory()->create();
        $this->manager->markEmailAsVerified();
        $this->manager->givePermissionTo(['transactions-access', 'cashier-shifts-access', 'discounts-approve']);
        $this->manager->outlets()->attach($this->outlet->id, ['is_default' => true]);

        // Every manual discount waits for approval.
        Setting::set('discount_approval_threshold', '1');
    }

    private function sell(array $rules, array $body = []): Transaction
    {
        foreach ($rules as $key => $value) {
            Setting::setForOutlet($key, $value, $this->outlet);
        }
        app(CashierShiftService::class)->openShift($this->manager, $this->manager, 0, null, $this->warehouse->id);
        Cart::create([
            'cashier_id' => $this->manager->id, 'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id, 'qty' => 2, 'price' => 20000,
        ]);

        $this->actingAs($this->manager)
            ->post(route('transactions.store'), $body + ['order_type' => 'in_store', 'discount' => 5000, 'cash' => 50000])
            ->assertSessionHasNoErrors();

        $sale = Transaction::sole();
        $this->assertSame('pending', $sale->discount_approval_status);

        return $sale;
    }

    private function deny(Transaction $sale): void
    {
        $this->post(route('discount-approvals.deny', $sale))->assertSessionHas('success');
    }

    private function bill(Transaction $sale): array
    {
        $sale->refresh();

        return [$sale->service_charge, $sale->tax_total, $sale->tax_base, $sale->grand_total];
    }

    /** Each bill is [service charge, tax, tax base, grand total], as sold with the discount and after denying it. */
    public static function deniedBills(): array
    {
        $delivery = ['order_type' => 'delivery', 'shipping_cost' => 10000];

        return [
            'PB1 with service charge' => [self::PB1_WITH_SERVICE, [], 5.0, [750, 1575, 15750, 17325], [1000, 2100, 21000, 23100]],
            'PPN with service charge' => [self::PPN_WITH_SERVICE, [], 5.0, [750, 2283, 20750, 18033], [1000, 2310, 21000, 23310]],
            // The stored service charge is 0 here, so only the stored rate can bring it back.
            'discount covering the whole bill' => [self::PB1_WITH_SERVICE, ['discount' => 20000], 5.0, [0, 0, 0, 0], [1000, 2100, 21000, 23100]],
            'PPN delivery with shipping' => [self::PPN_WITH_SERVICE, $delivery, 0.0, [0, 3300, 30000, 28300], [0, 3300, 30000, 33300]],
            'PB1 delivery with shipping' => [self::PB1_WITH_SERVICE, $delivery, 0.0, [0, 1500, 15000, 26500], [0, 2000, 20000, 32000]],
        ];
    }

    #[DataProvider('deniedBills')]
    public function test_denial_recomputes_from_the_rates_the_sale_stored(array $rules, array $body, float $rate, array $sold, array $restored): void
    {
        $sale = $this->sell($rules, $body);
        $this->assertSame($sold, $this->bill($sale));
        $this->assertSame($rate, (float) $sale->service_charge_rate);

        // Today's rules must not reach the sale.
        foreach (['service_charge_rate' => '50', 'tax_kind' => 'ppn', 'pb1_rate' => '25'] as $key => $value) {
            Setting::setForOutlet($key, $value, $this->outlet);
        }
        $this->product->update(['tax_rate' => 5]);

        $this->deny($sale);

        $this->assertSame($restored, $this->bill($sale));
        $this->assertSame(0, $sale->discount);
    }

    public function test_a_sale_from_before_the_bill_rules_only_gets_the_discount_back(): void
    {
        $sale = Transaction::create([
            'cashier_id' => $this->manager->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => 'TRX-OLD',
            'grand_total' => 91000, 'discount' => 20000, 'tax_rate' => 11, 'tax_total' => 11000, 'cash' => 100000,
            'change' => 0, 'payment_method' => 'cash', 'payment_status' => 'pending_approval',
            'discount_approval_status' => 'pending',
        ]);

        $this->actingAs($this->manager);
        $this->deny($sale);

        $this->assertSame([0, 11000, null, 111000], $this->bill($sale));
    }

    public function test_a_second_denial_that_loaded_the_sale_earlier_adds_nothing(): void
    {
        $stale = $this->sell(self::PPN_WITH_SERVICE);
        $this->deny(Transaction::sole());

        // The second request passed the first check before the first one committed.
        try {
            app(DiscountApprovalController::class)->deny(request(), $stale);
            $this->fail('The second denial must be refused under the lock.');
        } catch (NotFoundHttpException) {
            $this->assertSame([1000, 2310, 21000, 23310], $this->bill(Transaction::sole()));
        }
    }

    public function test_the_receivable_of_a_pay_later_sale_follows_the_new_total(): void
    {
        Setting::setForOutlet('pay_later_enabled', '1', $this->outlet);
        $customer = Customer::create(['name' => 'Budi', 'no_telp' => '62811', 'address' => 'Jl. Satu']);
        $customer->forceFill(['may_buy_on_credit' => true])->save();
        $sale = $this->sell(self::PPN_WITH_SERVICE, [
            'pay_later' => '1', 'customer_id' => $customer->id, 'due_date' => now()->addWeek()->toDateString(), 'cash' => 0,
        ]);
        // Paid in full while the discount still waited.
        Receivable::sole()->update(['paid' => 18033, 'status' => 'paid']);

        $this->deny($sale);

        $receivable = Receivable::sole();
        $this->assertSame([23310.0, 5277.0, 'partial'], [$receivable->total, $receivable->remaining, $receivable->status]);
    }

    public function test_denial_recomputes_loyalty_points_and_total_spent(): void
    {
        Setting::set('loyalty_earn_rate_amount', '1000');
        $customer = Customer::create(['name' => 'Budi', 'no_telp' => '62812', 'address' => 'Jl. Satu']);
        $customer->forceFill([
            'is_loyalty_member' => true, 'loyalty_points' => 0, 'loyalty_total_spent' => 0,
        ])->save();

        $sale = $this->sell(self::PPN_WITH_SERVICE, ['customer_id' => $customer->id]);
        $oldTotal = (int) $sale->grand_total;
        $before = $customer->fresh();

        $this->deny($sale);

        $diff = (int) $sale->fresh()->grand_total - $oldTotal;
        $after = $customer->fresh();

        // Points and total spent follow the recomputed total: the difference's worth is added.
        $this->assertGreaterThan(0, $diff);
        $this->assertSame((int) $before->loyalty_total_spent + $diff, (int) $after->loyalty_total_spent);
        $this->assertSame(intdiv($diff, 1000), (int) $after->loyalty_points - (int) $before->loyalty_points);
        $this->assertDatabaseHas('loyalty_point_histories', ['customer_id' => $customer->id, 'transaction_id' => $sale->id]);
    }

    public function test_the_api_denial_recomputes_and_keeps_the_notes(): void
    {
        $sale = $this->sell(self::PPN_WITH_SERVICE);
        Sanctum::actingAs($this->manager, ['*']);

        $this->postJson('/api/v1/discount-approvals/'.$sale->id.'/deny', ['notes' => 'Terlalu besar'])->assertOk();

        $this->assertSame([1000, 2310, 21000, 23310], $this->bill($sale));
        $this->assertSame('Terlalu besar', DiscountApprovalLog::sole()->notes);
    }
}
