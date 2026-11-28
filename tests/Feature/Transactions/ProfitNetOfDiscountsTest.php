<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerVoucher;
use App\Models\Outlet;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\Profit;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * #570: a sale's profit is net of every discount — manual, redeemed points and voucher — and of the PPN its
 * price includes (BR-2, BR-5); a denied manual discount rebuilds it (BR-6).
 */
class ProfitNetOfDiscountsTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        foreach ([
            'transactions-access',
            'cashier-shifts-access',
            'cashier-shifts-open',
            'cashier-shifts-close',
            'discounts-approve',
            'profits-access',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->outlet = Outlet::create(['code' => 'PFT', 'name' => 'Profit', 'is_active' => true]);
        $this->warehouse = Warehouse::create([
            'code' => 'WH-PFT', 'name' => 'Gudang Profit', 'status' => 'active', 'outlet_id' => $this->outlet->id,
        ]);
        $this->cashier = $this->userIn($this->outlet, [
            'transactions-access', 'cashier-shifts-access', 'cashier-shifts-open', 'cashier-shifts-close',
            'discounts-approve', 'profits-access',
        ]);
        $this->openShiftFor($this->cashier, $this->warehouse);
    }

    // BR-1 / TC-1: a voucher sale records profit net of the voucher. Red before the fix (the row held the full price).
    public function test_voucher_sale_records_profit_net_of_the_voucher(): void
    {
        $product = $this->product(60000, 40000);
        $customer = $this->member();
        $voucher = $this->voucher($customer, CustomerVoucher::TYPE_FIXED_AMOUNT, 10000);

        $this->cart($product, 1);

        $this->checkout(['customer_id' => $customer->id, 'customer_voucher_id' => $voucher->id, 'cash' => 60000])
            ->assertSessionHasNoErrors();

        $sale = Transaction::sole();
        $this->assertSame(10000, (int) $sale->customer_voucher_discount);
        // 60.000 − 10.000 voucher − 40.000 HPP
        $this->assertSame(10000, $this->profit($sale));
    }

    // BR-1 / TC-1: a points sale records profit net of the points. Red before the fix.
    public function test_redeemed_points_sale_records_profit_net_of_the_points(): void
    {
        $product = $this->product(60000, 40000);
        $customer = $this->member(20);

        $this->cart($product, 1);

        $this->checkout(['customer_id' => $customer->id, 'redeem_points' => 10, 'cash' => 60000])
            ->assertSessionHasNoErrors();

        $sale = Transaction::sole();
        $this->assertSame(1000, (int) $sale->loyalty_discount_total);
        // 60.000 − 1.000 points − 40.000 HPP
        $this->assertSame(19000, $this->profit($sale));
    }

    // BR-2: all three bill-level discounts come off once, in order and clamped by the loyalty preview.
    public function test_voucher_points_and_manual_come_off_together(): void
    {
        $product = $this->product(60000, 40000);
        $customer = $this->member(20);
        $voucher = $this->voucher($customer, CustomerVoucher::TYPE_FIXED_AMOUNT, 10000);

        $this->cart($product, 1);

        $this->checkout([
            'customer_id' => $customer->id,
            'customer_voucher_id' => $voucher->id,
            'redeem_points' => 10,
            'discount' => 5000,
            'cash' => 60000,
        ])->assertSessionHasNoErrors();

        $sale = Transaction::sole();
        $this->assertSame([5000, 1000, 10000], [
            (int) $sale->discount, (int) $sale->loyalty_discount_total, (int) $sale->customer_voucher_discount,
        ]);
        // 60.000 − 16.000 discounts − 40.000 HPP
        $this->assertSame(4000, $this->profit($sale));
    }

    // BR-2: a promotion is already in the line price; it is never subtracted a second time.
    public function test_promotion_is_taken_off_once(): void
    {
        $product = $this->product(60000, 40000);
        $customer = $this->member();
        PricingRule::create([
            'name' => 'Promo Member', 'is_active' => true, 'priority' => 200, 'target_type' => 'product',
            'product_id' => $product->id, 'customer_scope' => 'registered',
            'discount_type' => 'fixed_amount', 'discount_value' => 10000,
        ]);

        $this->cart($product, 1);

        $this->checkout(['customer_id' => $customer->id, 'cash' => 60000])->assertSessionHasNoErrors();

        $sale = Transaction::sole();
        $detail = $sale->details()->sole();
        $this->assertSame(50000, (int) $detail->price);
        // (60.000 − 10.000 promo) − 40.000 HPP: the promotion is in the price, not a bill discount.
        $this->assertSame(10000, $this->profit($sale));
    }

    // BR-2 / TC-4: a 100 % discount records −cost and no line gets more than its price.
    public function test_fully_discounted_sale_records_minus_the_cost(): void
    {
        $product = $this->product(60000, 40000);

        $this->cart($product, 1);

        $this->checkout(['discount' => 60000, 'cash' => 0])->assertSessionHasNoErrors();

        $sale = Transaction::sole();
        $this->assertSame(60000, (int) $sale->discount);
        $this->assertSame(-40000, $this->profit($sale));
    }

    // BR-2 / TC-4: the cumulative split adds up exactly; the Rp 1 remainder stays on the last line.
    public function test_uneven_split_rows_add_up_to_net_revenue_less_cost(): void
    {
        $lines = [$this->product(10000, 0), $this->product(10000, 0), $this->product(10000, 0)];
        foreach ($lines as $product) {
            $this->cart($product, 1);
        }

        $this->checkout(['discount' => 100, 'cash' => 30000])->assertSessionHasNoErrors();

        $sale = Transaction::sole();
        $this->assertSame([33, 34, 33], $sale->profits()->orderBy('id')->pluck('total')->map(fn ($v) => (int) $v)->all());
        // Σ price − bill discount − Σ cost, to the rupiah.
        $this->assertSame(29900, $this->profit($sale));
    }

    // BR-2 / REQ-010: service charge, tax and shipping are not revenue, so they never enter profit.
    public function test_service_charge_tax_and_shipping_are_not_in_profit(): void
    {
        Setting::setForOutlet('service_charge_rate', '5', $this->outlet);
        $product = $this->product(60000, 40000);
        $product->update(['tax_type' => 'exclusive', 'tax_rate' => 11]);
        $customer = $this->member();
        $voucher = $this->voucher($customer, CustomerVoucher::TYPE_FIXED_AMOUNT, 10000);

        $this->cart($product, 1);

        $this->checkout([
            'customer_id' => $customer->id, 'customer_voucher_id' => $voucher->id,
            'order_type' => 'in_store', 'shipping_cost' => 5000, 'cash' => 200000,
        ])->assertSessionHasNoErrors();

        $sale = Transaction::sole();
        $this->assertSame(2500, (int) $sale->service_charge); // 5 % of 50.000
        $this->assertGreaterThan(0, (int) $sale->tax_total);
        $this->assertSame(5000, (int) $sale->shipping_cost);
        // Only 60.000 − 10.000 voucher − 40.000 HPP; service charge, PPN and shipping stay out.
        $this->assertSame(10000, $this->profit($sale));
    }

    // BR-3 / TC-5: a sale recorded before the release keeps its rows; nothing is recalculated.
    public function test_earlier_sales_keep_their_recorded_profit(): void
    {
        $old = Transaction::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => 'TRX-OLD-570',
            'cash' => 60000, 'change' => 0, 'discount' => 10000, 'customer_voucher_discount' => 0,
            'loyalty_discount_total' => 0, 'grand_total' => 50000, 'payment_method' => 'cash', 'payment_status' => 'paid',
        ]);
        Profit::create(['transaction_id' => $old->id, 'total' => 20000]); // the old basis, left alone

        $product = $this->product(60000, 40000);
        $customer = $this->member();
        $voucher = $this->voucher($customer, CustomerVoucher::TYPE_FIXED_AMOUNT, 10000);
        $this->cart($product, 1);
        $this->checkout(['customer_id' => $customer->id, 'customer_voucher_id' => $voucher->id, 'cash' => 60000])
            ->assertSessionHasNoErrors();

        $this->assertSame(20000, (int) Profit::where('transaction_id', $old->id)->sum('total'));
        $this->assertSame(20000, (int) $old->profits()->sum('total'));
        $this->assertSame(10000, $this->profit(Transaction::latest('id')->firstOrFail()));
    }

    // BR-4 / TC-6: the report's period total is the sum of the sales' recorded rows.
    public function test_profit_report_total_equals_the_recorded_profits(): void
    {
        $product = $this->product(60000, 40000);
        $customer = $this->member();
        $voucher = $this->voucher($customer, CustomerVoucher::TYPE_FIXED_AMOUNT, 10000);
        $this->cart($product, 1);
        $this->checkout(['customer_id' => $customer->id, 'customer_voucher_id' => $voucher->id, 'cash' => 60000])
            ->assertSessionHasNoErrors();

        $ledger = (int) Profit::whereIn('transaction_id', Transaction::pluck('id'))->sum('total');

        $this->actingAs($this->cashier)
            ->get(route('reports.profits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Reports/Profit')
                ->where('summary.profit_total', 10000)
                ->where('summary.profit_total', $ledger));
    }

    // BR-2 / TC-7: the API checkout writes the same rows as the web checkout, from one service.
    public function test_api_checkout_records_the_same_profit(): void
    {
        $product = $this->product(60000, 40000);
        $customer = $this->member();
        $voucher = $this->voucher($customer, CustomerVoucher::TYPE_FIXED_AMOUNT, 10000);

        Sanctum::actingAs($this->cashier, ['*']);
        $this->postJson('/api/v1/pos/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $this->postJson('/api/v1/pos/checkout', [
            'customer_id' => $customer->id, 'customer_voucher_id' => $voucher->id,
            'payment_method' => 'cash', 'cash' => 60000,
        ])->assertCreated();

        $this->assertSame(10000, $this->profit(Transaction::sole()));
    }

    // TC-9: a sale at one outlet changes its own profit report and not another outlet's.
    public function test_profit_stays_in_its_own_outlet(): void
    {
        $otherOutlet = Outlet::create(['code' => 'PFT-B', 'name' => 'Profit B', 'is_active' => true]);
        $otherWarehouse = Warehouse::create([
            'code' => 'WH-PFT-B', 'name' => 'Gudang B', 'status' => 'active', 'outlet_id' => $otherOutlet->id,
        ]);
        $otherCashier = $this->userIn($otherOutlet, [
            'transactions-access', 'cashier-shifts-access', 'cashier-shifts-open', 'cashier-shifts-close',
        ]);
        $this->openShiftFor($otherCashier, $otherWarehouse);

        $productA = $this->product(30000, 10000);
        $this->cart($productA, 1);
        $this->checkout(['cash' => 30000])->assertSessionHasNoErrors();

        $productB = $this->product(50000, 10000);
        $this->cart($productB, 1, $otherWarehouse, $otherCashier);
        $this->actingAs($otherCashier)->post(route('transactions.store'), ['discount' => 0, 'cash' => 50000])
            ->assertSessionHasNoErrors();

        $reporter = $this->userIn($this->outlet, ['profits-access']);
        $reporter->outlets()->syncWithoutDetaching([$otherOutlet->id]);

        $this->actingAs($reporter);
        $this->session(['active_outlet_id' => $this->outlet->id]);
        $this->get(route('reports.profits.index'))
            ->assertInertia(fn (Assert $page) => $page->where('summary.profit_total', 20000));

        $this->session(['active_outlet_id' => $otherOutlet->id]);
        $this->get(route('reports.profits.index'))
            ->assertInertia(fn (Assert $page) => $page->where('summary.profit_total', 40000));
    }

    // BR-5 / TC-12: a PPN-inclusive line records profit on its price without the PPN; exclusive is unchanged.
    public function test_inclusive_ppn_line_records_profit_without_its_ppn(): void
    {
        $inclusive = $this->product(111000, 40000);
        $inclusive->update(['tax_type' => 'inclusive', 'tax_rate' => 11]);

        $this->cart($inclusive, 1);
        $this->checkout(['cash' => 200000])->assertSessionHasNoErrors();

        $sale = Transaction::sole();
        // 111.000 − 11.000 PPN inside the price − 40.000 HPP
        $this->assertSame(60000, $this->profit($sale));

        $exclusive = $this->product(111000, 40000);
        $exclusive->update(['tax_type' => 'exclusive', 'tax_rate' => 11]);
        $this->cart($exclusive, 1);
        $this->checkout(['cash' => 200000])->assertSessionHasNoErrors();

        // The exclusive PPN is added on top, so the whole price is revenue: unchanged from today.
        $this->assertSame(71000, $this->profit(Transaction::latest('id')->firstOrFail()));
    }

    // BR-6 / TC-13: denying a manual discount rebuilds the rows from the restored bill; approving keeps them.
    public function test_denying_a_manual_discount_rebuilds_the_profit(): void
    {
        Setting::set('discount_approval_threshold', '1');
        $product = $this->product(10000, 5000);
        $this->cart($product, 2);

        $this->checkout(['discount' => 5000, 'cash' => 50000])->assertSessionHasNoErrors();
        $sale = Transaction::sole();
        $this->assertSame('pending', $sale->discount_approval_status);
        // 20.000 − 5.000 discount − 10.000 HPP
        $this->assertSame(5000, $this->profit($sale));

        $this->post(route('discount-approvals.deny', $sale))->assertSessionHas('success');

        // The customer never got the discount: 20.000 − 10.000 HPP, the same as a full-price checkout.
        $this->assertSame(0, (int) $sale->fresh()->discount);
        $this->assertSame(10000, $this->profit($sale->fresh()));

        // An approved discount keeps its rows untouched.
        $this->cart($product, 2);
        $this->checkout(['discount' => 5000, 'cash' => 50000])->assertSessionHasNoErrors();
        $approved = Transaction::latest('id')->firstOrFail();
        $this->post(route('discount-approvals.approve', $approved))->assertSessionHas('success');
        $this->assertSame(5000, $this->profit($approved));
    }

    private function userIn(Outlet $outlet, array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);
        $user->outlets()->attach($outlet->id, ['is_default' => true]);

        return $user;
    }

    private function openShiftFor(User $user, Warehouse $warehouse): CashierShift
    {
        return app(CashierShiftService::class)->openShift($user, $user, 100000, null, $warehouse->id);
    }

    private function product(int $sellPrice, int $buyPrice): Product
    {
        $category = Category::create(['name' => 'Kategori '.Str::random(5), 'image' => '', 'description' => '']);
        $product = Product::create([
            'title' => 'Produk '.Str::random(5), 'barcode' => 'PFT-'.Str::upper(Str::random(8)),
            'sku' => 'SKU-'.Str::upper(Str::random(8)), 'image' => '', 'description' => '',
            'buy_price' => $buyPrice, 'sell_price' => $sellPrice, 'stock' => 100,
            'category_id' => $category->id, 'tax_type' => 'exclusive', 'tax_rate' => 0,
            'min_stock' => 0, 'max_stock' => 1000, 'is_composite' => false,
        ]);
        foreach (Warehouse::pluck('id') as $warehouseId) {
            $product->warehouses()->attach($warehouseId, ['stock' => 100]);
        }

        return $product;
    }

    private function member(int $points = 0): Customer
    {
        return Customer::create([
            'name' => 'Member '.Str::random(5), 'no_telp' => '6287'.random_int(1000000, 9999999),
            'address' => 'Jl. Uji', 'is_loyalty_member' => true,
            'member_code' => 'MEM-'.Str::upper(Str::random(6)),
            'loyalty_tier' => LoyaltyService::TIER_REGULAR,
            'loyalty_points' => $points, 'loyalty_member_since' => now()->subMonth(),
        ]);
    }

    private function voucher(Customer $customer, string $type, int $value): CustomerVoucher
    {
        return CustomerVoucher::create([
            'customer_id' => $customer->id, 'code' => 'VCR-'.Str::upper(Str::random(6)),
            'name' => 'Voucher Uji', 'discount_type' => $type, 'discount_value' => $value,
            'minimum_order' => 0, 'is_active' => true,
        ]);
    }

    private function cart(Product $product, int $qty, ?Warehouse $warehouse = null, ?User $cashier = null): void
    {
        Cart::create([
            'cashier_id' => ($cashier ?? $this->cashier)->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'product_id' => $product->id, 'qty' => $qty, 'price' => $product->sell_price * $qty,
        ]);
    }

    private function checkout(array $body)
    {
        return $this->actingAs($this->cashier)
            ->post(route('transactions.store'), $body + ['discount' => 0, 'shipping_cost' => 0, 'grand_total' => 999999]);
    }

    /** The sale's recorded profit, read from the ledger and cast (MySQL returns strings). */
    private function profit(Transaction $sale): int
    {
        return (int) $sale->profits()->sum('total');
    }
}
