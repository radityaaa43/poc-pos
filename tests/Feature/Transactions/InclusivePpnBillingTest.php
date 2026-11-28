<?php

namespace Tests\Feature\Transactions;

use App\Models\Cart;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\BillTotalService;
use App\Services\CashierShiftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * #576: a tax-inclusive price already carries its PPN. It is reported in `tax_total` and `tax_included`, never added
 * to the grand total again; an exclusive price keeps its PPN on top.
 */
class InclusivePpnBillingTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    private Warehouse $warehouse;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transactions-access', 'cashier-shifts-access', 'cashier-shifts-open', 'cashier-shifts-close', 'discounts-approve'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->outlet = Outlet::create(['code' => 'PPN', 'name' => 'Ppn', 'is_active' => true]);
        $this->warehouse = Warehouse::create([
            'code' => 'WH-PPN', 'name' => 'Gudang PPN', 'status' => 'active', 'outlet_id' => $this->outlet->id,
        ]);
        $this->cashier = User::factory()->create();
        $this->cashier->givePermissionTo(['transactions-access', 'cashier-shifts-access', 'cashier-shifts-open', 'cashier-shifts-close', 'discounts-approve']);
        $this->cashier->outlets()->attach($this->outlet->id, ['is_default' => true]);
        app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);
    }

    // BR-1 / TC-1 (red before the fix): an inclusive line of 166.500 at 11 % is charged 166.500, its 16.500 PPN
    // reported, and the tax base is the price without PPN.
    public function test_an_inclusive_line_is_not_charged_its_ppn_twice(): void
    {
        $product = $this->product(166500, 40000, 'inclusive', 11);
        $this->cart($product, 1);

        $this->checkout(['cash' => 300000])->assertSessionHasNoErrors();

        $sale = Transaction::sole();
        $this->assertSame(166500, (int) $sale->grand_total);
        $this->assertSame(16500, (int) $sale->tax_total);
        $this->assertSame(16500, (int) $sale->tax_included);
        $this->assertSame(150000, (int) $sale->tax_base);
    }

    // BR-3 / TC-2: a mixed bill charges only the exclusive line's, the shipping's and the service charge's PPN.
    public function test_a_mixed_bill_charges_only_the_ppn_it_adds(): void
    {
        Setting::setForOutlet('service_charge_rate', '5', $this->outlet);
        $inclusive = $this->product(111000, 40000, 'inclusive', 11);
        $exclusive = $this->product(20000, 5000, 'exclusive', 11);
        $this->cart($inclusive, 1);
        $this->cart($exclusive, 1);

        $lines = app(BillTotalService::class)->taxLines([
            ['product_id' => $inclusive->id, 'line_total' => 111000],
            ['product_id' => $exclusive->id, 'line_total' => 20000],
        ], $this->outlet);
        $bill = app(BillTotalService::class)->compute($this->outlet, $lines, 131000, 10000, true);

        // 131.000 + 10.000 shipping + 6.550 service (5 %) + added PPN 2.200 + 1.100 + 721
        $this->assertSame(151571, $bill['grand_total']);
        $this->assertSame(11000, $bill['tax_included']);
        $this->assertSame(15021, $bill['tax_total']);
        // The inclusive line enters the base at its price without PPN.
        $this->assertSame(126550, $bill['tax_base']);
        $this->assertSame(2200, $bill['bill_basis']['line_tax_added']);
        $this->assertSame(11000, $bill['bill_basis']['line_tax_included']);
        $this->assertSame(13200, $bill['bill_basis']['line_tax_total']);
    }

    // BR-2 / TC-3: denying a manual discount gives the inclusive price back exactly, adding no PPN.
    public function test_a_denied_discount_restores_an_inclusive_bill_without_adding_ppn(): void
    {
        Setting::set('discount_approval_threshold', '1');
        $product = $this->product(166500, 40000, 'inclusive', 11);
        $this->cart($product, 1);

        $this->checkout(['discount' => 5000, 'cash' => 300000])->assertSessionHasNoErrors();
        $sale = Transaction::sole();
        $this->assertSame(161500, (int) $sale->grand_total);

        $this->post(route('discount-approvals.deny', $sale))->assertSessionHas('success');

        $sale->refresh();
        $this->assertSame(166500, (int) $sale->grand_total);
        $this->assertSame(16500, (int) $sale->tax_total);
        $this->assertSame(16500, (int) $sale->tax_included);
    }

    // BR-5 / TC-3: a sale recorded before this fix keeps its figures; its restore adds only the discount back.
    public function test_a_sale_from_before_the_fix_restores_as_before(): void
    {
        $old = Transaction::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => 'TRX-OLD-576',
            'cash' => 300000, 'change' => 0, 'discount' => 5000, 'loyalty_discount_total' => 0,
            'customer_voucher_discount' => 0, 'shipping_cost' => 0, 'grand_total' => 178000, 'tax_total' => 16500,
            'tax_rate' => 11, 'tax_base' => 150000, 'tax_kind' => 'ppn', 'service_charge' => 0,
            'payment_method' => 'cash', 'payment_status' => 'pending_approval', 'discount_approval_status' => 'pending',
        ]);
        // It was charged the PPN on top: 166.500 − 5.000 + 16.500.
        $this->assertSame(0, (int) $old->tax_included);

        $restored = app(BillTotalService::class)->restore($old, 5000);

        $this->assertSame(183000, $restored['grand_total']);
        $this->assertSame(16500, $restored['tax_total']);
        $this->assertSame(0, $restored['tax_included']);
    }

    // BR-4 / TC-3: the API checkout stores the same split as the web one.
    public function test_the_api_checkout_stores_tax_included(): void
    {
        $product = $this->product(166500, 40000, 'inclusive', 11);

        Sanctum::actingAs($this->cashier, ['*']);
        $this->postJson('/api/v1/pos/cart', ['product_id' => $product->id, 'qty' => 1])->assertOk();
        $response = $this->postJson('/api/v1/pos/checkout', ['payment_method' => 'cash', 'cash' => 300000])->assertCreated();

        $response->assertJsonPath('data.tax_included', 16500.0);
        $sale = Transaction::sole();
        $this->assertSame(166500, (int) $sale->grand_total);
        $this->assertSame(16500, (int) $sale->tax_included);
    }

    private function product(int $sellPrice, int $buyPrice, string $taxType, float $taxRate): Product
    {
        $category = Category::create(['name' => 'KPPN '.Str::random(5), 'image' => '', 'description' => '']);
        $product = Product::create([
            'title' => 'Produk '.Str::random(5), 'barcode' => 'PPN-'.Str::upper(Str::random(8)),
            'sku' => 'SKU-'.Str::upper(Str::random(8)), 'image' => '', 'description' => '',
            'buy_price' => $buyPrice, 'sell_price' => $sellPrice, 'stock' => 100,
            'category_id' => $category->id, 'tax_type' => $taxType, 'tax_rate' => $taxRate,
            'min_stock' => 0, 'max_stock' => 1000, 'is_composite' => false,
        ]);
        $product->warehouses()->attach($this->warehouse->id, ['stock' => 100]);

        return $product;
    }

    private function cart(Product $product, int $qty): void
    {
        Cart::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id, 'qty' => $qty, 'price' => $product->sell_price * $qty,
        ]);
    }

    private function checkout(array $body)
    {
        return $this->actingAs($this->cashier)
            ->post(route('transactions.store'), $body + ['discount' => 0, 'shipping_cost' => 0, 'grand_total' => 999999]);
    }
}
