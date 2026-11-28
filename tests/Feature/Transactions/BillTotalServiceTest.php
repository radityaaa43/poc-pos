<?php

namespace Tests\Feature\Transactions;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerVoucher;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Setting;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
