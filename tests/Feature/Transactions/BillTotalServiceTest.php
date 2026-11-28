<?php

namespace Tests\Feature\Transactions;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerVoucher;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Setting;
use App\Services\BillTotalService;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BillTotalServiceTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = Outlet::create(['code' => 'BILL', 'name' => 'Bill']);
        $this->category = Category::create(['name' => 'Kategori', 'image' => 'c.jpg', 'description' => 'c']);
    }

    /**
     * The numbers were captured from the code before service charge and PB1 existed (#450, TC-1):
     * an outlet that never saved bill settings keeps them, whatever the order type.
     */
    public function test_default_outlet_matches_pre_release_totals(): void
    {
        $member = Customer::create([
            'name' => 'Member', 'no_telp' => 628120000001, 'address' => 'a',
            'is_loyalty_member' => true, 'loyalty_points' => 20,
        ]);
        $voucher = CustomerVoucher::create([
            'customer_id' => $member->id, 'code' => 'VCR-GOLD', 'name' => 'Golden',
            'discount_type' => CustomerVoucher::TYPE_FIXED_AMOUNT, 'discount_value' => 5000,
            'minimum_order' => 0, 'is_active' => true,
        ]);
        $pricing = $this->pricing([
            [$this->product(11, 'exclusive'), 47250],
            [$this->product(0, 'exclusive'), 10000],
            [$this->product(10, 'inclusive'), 33000],
        ]);

        foreach ([null, 'in_store', 'delivery'] as $orderType) {
            $summary = app(LoyaltyService::class)->previewCheckout($pricing, $member, [
                'manual_discount' => 3000,
                'shipping_cost' => 12000,
                'redeem_points' => 20,
                'voucher' => $voucher,
                'order_type' => $orderType,
            ], null, $this->outlet)['summary'];

            $this->assertSame([
                'voucher_discount_total' => 5000,
                'loyalty_discount_total' => 2000,
                'manual_discount_total' => 3000,
                'tax_total' => 9398,
                'tax_rate' => 10.0,
                'grand_total' => 101648,
                'points_earned_preview' => 8,
            ], [
                'voucher_discount_total' => $summary['voucher_discount_total'],
                'loyalty_discount_total' => $summary['loyalty_discount_total'],
                'manual_discount_total' => $summary['manual_discount_total'],
                'tax_total' => $summary['tax_total'],
                'tax_rate' => $summary['tax_rate'],
                'grand_total' => $summary['grand_total'],
                'points_earned_preview' => $summary['points_earned_preview'],
            ]);
        }
    }

    #[DataProvider('billCases')]
    public function test_compute_matches_the_shared_table(array $case): void
    {
        foreach ($case['settings'] as $key => $value) {
            Setting::setForOutlet($key, (string) $value, $this->outlet);
        }

        $bill = app(BillTotalService::class)->compute(
            $this->outlet, $case['lines'], $case['after_discount'], $case['shipping'], $case['dine_in'],
        );

        $expected = $case['expected'];
        $expected['tax_rate'] = $expected['tax_rate'] === null ? null : (float) $expected['tax_rate'];
        $expected['bill_basis']['effective_rate'] = (float) $expected['bill_basis']['effective_rate'];
        $expected['service_charge_rate'] = (float) $expected['service_charge_rate'];
        ksort($expected);
        ksort($bill);

        $this->assertSame($expected, $bill);
    }

    public static function billCases(): array
    {
        $table = json_decode(file_get_contents(__DIR__.'/../../fixtures/bill-totals.json'), true);

        return array_column(array_map(fn (array $case) => [$case['name'], [$case]], $table['cases']), 1, 0);
    }

    public function test_compute_reads_only_the_bill_settings(): void
    {
        $service = app(BillTotalService::class);
        $lines = [['product_id' => 1, 'line_total' => 20000, 'tax_rate' => 11, 'tax_type' => 'exclusive']];

        DB::enableQueryLog();
        $service->compute($this->outlet, $lines, 20000, 0, true);

        $this->assertCount(1, DB::getQueryLog());
        $this->assertStringContainsString('settings', DB::getQueryLog()[0]['query']);
    }

    public function test_only_in_store_is_charged(): void
    {
        Setting::setForOutlet('service_charge_rate', '5', $this->outlet);
        $pricing = $this->pricing([[$this->product(0, 'exclusive'), 20000]]);

        foreach (['in_store' => 1000, 'takeaway' => 0, 'delivery' => 0, '' => 0] as $orderType => $service) {
            $summary = app(LoyaltyService::class)->previewCheckout(
                $pricing, null, ['order_type' => $orderType ?: null], null, $this->outlet,
            )['summary'];

            $this->assertSame($service, $summary['service_charge'], "order type '{$orderType}'");
            $this->assertSame(20000 + $service, $summary['grand_total']);
        }
    }

    public function test_service_charge_is_on_subtotal_after_all_discounts_and_points_keep_their_base(): void
    {
        Setting::setForOutlet('service_charge_rate', '5', $this->outlet);
        $member = Customer::create([
            'name' => 'Member', 'no_telp' => 628120000002, 'address' => 'a',
            'is_loyalty_member' => true, 'loyalty_points' => 20,
        ]);
        $voucher = CustomerVoucher::create([
            'customer_id' => $member->id, 'code' => 'VCR-SVC', 'name' => 'Service',
            'discount_type' => CustomerVoucher::TYPE_FIXED_AMOUNT, 'discount_value' => 5000,
            'minimum_order' => 0, 'is_active' => true,
        ]);

        $summary = app(LoyaltyService::class)->previewCheckout(
            $this->pricing([[$this->product(0, 'exclusive'), 50000]]),
            $member,
            ['voucher' => $voucher, 'redeem_points' => 20, 'manual_discount' => 3000, 'shipping_cost' => 12000, 'order_type' => 'in_store'],
            null,
            $this->outlet,
        )['summary'];

        // 50,000 - 5,000 - 2,000 - 3,000 = 40,000; 5 % of it. Points: floor((54,000 - 12,000) / 10,000), as before.
        $this->assertSame(2000, $summary['service_charge']);
        $this->assertSame(54000, $summary['grand_total']);
        $this->assertSame(4, $summary['points_earned_preview']);
    }

    public function test_tax_lines_take_the_product_rate_and_fall_back_to_the_outlet_default(): void
    {
        Setting::setForOutlet('tax_default_rate', '7', $this->outlet);
        $product = $this->product(5, 'inclusive');

        $lines = app(BillTotalService::class)->taxLines([
            ['product_id' => $product->id, 'line_total' => 1000],
            ['product_id' => $product->id + 1000, 'line_total' => 2000],
        ], $this->outlet);

        $this->assertSame([[5.0, 'inclusive'], [7.0, 'exclusive']], array_map(fn (array $line) => [$line['tax_rate'], $line['tax_type']], $lines));
    }

    private function product(float $taxRate, string $taxType): Product
    {
        return Product::create([
            'category_id' => $this->category->id, 'title' => 'P'.uniqid(), 'sku' => 'S'.uniqid(),
            'barcode' => 'B'.uniqid(), 'description' => 'p', 'image' => 'p.jpg',
            'buy_price' => 1000, 'sell_price' => 10000, 'stock' => 10,
            'tax_rate' => $taxRate, 'tax_type' => $taxType,
        ]);
    }

    /** @param array<int, array{Product, int}> $lines */
    private function pricing(array $lines): array
    {
        return [
            'items' => array_map(fn (array $line) => ['product_id' => $line[0]->id, 'line_total' => $line[1]], $lines),
            'summary' => ['subtotal_after_promo' => array_sum(array_column($lines, 1))],
        ];
    }
}
