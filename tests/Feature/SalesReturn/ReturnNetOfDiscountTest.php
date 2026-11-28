<?php

namespace Tests\Feature\SalesReturn;

use App\Models\AuditLog;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\LoyaltyPointHistory;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Services\ReturnBillShareService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A return refunds what the customer actually paid for the items: their price less the line, bill, promotion and
 * voucher discounts (#545). Redeemed points come back as points; earned points are taken back, clamped at 0, and a
 * shortfall is written to the return's audit entry (BR-7). Money is whole rupiah; points are whole integers.
 */
class ReturnNetOfDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
            'sales-returns-complete',
            'cashier-shifts-access',
            'cashier-shifts-open',
            'cashier-shifts-close',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    public function test_the_share_fixture_cases(): void
    {
        $table = json_decode(file_get_contents(__DIR__.'/../../fixtures/return-refund.json'), true);
        $service = app(ReturnBillShareService::class);

        foreach ($table['cases'] as $case) {
            $user = $this->user();
            [$sale] = $this->makeSale($user, [['price' => $case['bill']['base'], 'qty' => 1]], $this->billFrom($case));

            foreach ($case['steps'] as $i => $step) {
                $share = $service->forReturn($sale, $step['gross']);
                $label = $case['name'].' step '.$i;

                $this->assertSame($step['discount_deducted'], $share['discount_deducted'], $label);
                $this->assertSame($step['service_refunded'], $share['service_refunded'], $label);
                $this->assertSame($step['tax_refunded'], $share['tax_refunded'], $label);
                $this->assertSame($step['points_restored'], $share['points_restored'], $label);
                $this->assertSame($step['points_reversed'], $share['points_reversed'], $label);
                $this->assertSame(
                    $step['total'],
                    $step['gross'] - $share['discount_deducted'] + $share['service_refunded'] + $share['tax_refunded'],
                    $label,
                );

                $this->recordCompleted($sale, $step['gross'], $share['service_refunded'], $share['tax_refunded'], $share['discount_deducted']);
            }
        }
    }

    public function test_full_return_of_a_discounted_sale_refunds_what_was_paid(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'discount' => 2000, 'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100, 'grand_total' => 21100,
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 2)]));

        $this->assertSame(2000, $return->discount_deducted);
        $this->assertSame(1000, $return->service_refunded);
        $this->assertSame(2100, $return->tax_refunded);
        $this->assertSame(21100, $return->total_return_amount);
        $this->assertSame(21100, $return->refund_amount);
        $this->assertSame(0, $sale->fresh()->remainingTotal());
    }

    public function test_partial_returns_add_up_to_the_money_paid(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'discount' => 2000, 'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100, 'grand_total' => 21100,
        ]);

        $first = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));
        $second = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $this->assertSame(10550, $first->total_return_amount);
        $this->assertSame(10550, $second->total_return_amount);
        $this->assertSame(21100, $first->total_return_amount + $second->total_return_amount);
        $this->assertSame(2000, (int) SalesReturn::sum('discount_deducted'));
        $this->assertSame(0, $sale->fresh()->remainingTotal());
    }

    public function test_reverse_completion_order_adds_up(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'discount' => 2001, 'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100, 'grand_total' => 21099,
        ]);

        $early = $this->draft($user, $sale, [$this->item($details[0], 1)]);
        $late = $this->draft($user, $sale, [$this->item($details[0], 1)]);

        // Complete the later draft first: the cumulative share follows completion order, not creation order.
        $this->complete($user, $late);
        $this->complete($user, $early);

        $this->assertSame(21099, (int) SalesReturn::sum('total_return_amount'));
        $this->assertSame(0, $sale->fresh()->remainingTotal());
    }

    public function test_line_promotion_is_not_deducted_twice(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 1]], [
            'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100, 'grand_total' => 23100,
        ]);
        // A line promotion already lives in `price`: discount_total is information only and must not be subtracted.
        $details[0]->update(['discount_total' => 5000]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $this->assertSame(0, $return->discount_deducted);
        $this->assertSame(23100, $return->total_return_amount);
    }

    public function test_fully_discounted_sale_refunds_only_tax(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 1]], [
            'discount' => 20000, 'service_charge' => 0, 'tax_kind' => 'ppn', 'tax_rate' => 11, 'tax_total' => 2200, 'grand_total' => 2200,
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $this->assertSame(20000, $return->discount_deducted);
        $this->assertSame(0, $return->service_refunded);
        $this->assertSame(2200, $return->tax_refunded);
        $this->assertSame(2200, $return->total_return_amount);
    }

    public function test_pay_later_receivable_drops_by_the_net_amount(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'discount' => 2000, 'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100,
            'grand_total' => 21100, 'payment_method' => 'pay_later', 'payment_status' => 'unpaid', 'customer_id' => $this->customer()->id,
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $this->assertSame(0, $return->refund_amount);
        $this->assertSame(10550, (int) Receivable::where('transaction_id', $sale->id)->value('total'));
    }

    public function test_store_credit_is_the_net_total(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 1]], [
            'discount' => 2000, 'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100,
            'grand_total' => 21100, 'customer_id' => $this->customer()->id,
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)], 'store_credit'));

        $this->assertSame(21100, $return->credited_amount);
        $this->assertSame(21100, (int) CustomerCredit::where('sales_return_id', $return->id)->value('amount'));
    }

    public function test_redeemed_points_come_back_as_points(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        $customer = $this->member(points: 5);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'loyalty_discount_total' => 10000, 'loyalty_points_redeemed' => 100, 'customer_id' => $customer->id,
            'grand_total' => 10000,
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 2)]));

        $this->assertSame(100, $return->points_restored);
        $this->assertSame(105, (int) $customer->fresh()->loyalty_points);
        $this->assertSame(
            100,
            (int) LoyaltyPointHistory::where('type', LoyaltyPointHistory::TYPE_REDEEM)->where('points_delta', '>', 0)->sum('points_delta'),
        );
    }

    public function test_earned_points_are_taken_back(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        $customer = $this->member(points: 100);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'loyalty_points_earned' => 40, 'customer_id' => $customer->id, 'grand_total' => 20000,
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 2)]));

        $this->assertSame(40, $return->points_reversed);
        $this->assertSame(60, (int) $customer->fresh()->loyalty_points);
        $this->assertSame(
            -40,
            (int) LoyaltyPointHistory::where('type', LoyaltyPointHistory::TYPE_EARN)->where('points_delta', '<', 0)->sum('points_delta'),
        );
    }

    public function test_take_back_stops_at_zero_and_records_the_shortfall(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        // The customer spent the points this sale earned before bringing the item back (BR-7, TC-20).
        $customer = $this->member(points: 0);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 1]], [
            'loyalty_points_earned' => 40, 'customer_id' => $customer->id, 'grand_total' => 20000,
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $this->assertSame(0, (int) $customer->fresh()->loyalty_points);
        $this->assertSame(0, $return->points_reversed);
        $after = AuditLog::where('event', 'sales_return.completed')->latest('id')->first()->after;
        $this->assertSame(40, $after['points_shortfall']);
        $this->assertSame(0, $after['points_reversed']);
    }

    public function test_walk_in_touches_no_points(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'discount' => 2000, 'grand_total' => 18000,
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 2)]));

        $this->assertSame(18000, $return->total_return_amount);
        $this->assertSame(0, LoyaltyPointHistory::count());
    }

    public function test_old_completed_return_is_not_recomputed(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'discount' => 2000, 'grand_total' => 18000,
        ]);
        // A return completed before the release: gross item value, no discount recorded.
        $old = $this->recordCompleted($sale, 10000, 0, 0);

        $new = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $this->assertSame(0, $old->fresh()->discount_deducted);
        $this->assertSame(10000, $old->fresh()->total_return_amount);
        $this->assertSame(9000, $new->total_return_amount);
        $this->assertSame(1000, $new->discount_deducted);
    }

    public function test_draft_is_recomputed_at_complete(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'discount' => 2000, 'grand_total' => 18000,
        ]);
        $draft = $this->draft($user, $sale, [$this->item($details[0], 2)]);
        // A hand-edited draft figure is display only: complete recomputes from the locked sale.
        $draft->update(['total_return_amount' => 999, 'discount_deducted' => 0]);

        $return = $this->complete($user, $draft);

        $this->assertSame(18000, $return->total_return_amount);
        $this->assertSame(2000, $return->discount_deducted);
    }

    public function test_loyalty_total_spent_and_tier_follow_the_return(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        $customer = $this->member(points: 0, tier: 'gold', spent: 1510000);
        [$sale, $details] = $this->makeSale($user, [['price' => 30000, 'qty' => 1]], [
            'customer_id' => $customer->id, 'grand_total' => 30000,
        ]);

        $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $customer = $customer->fresh();
        $this->assertSame(1480000, (int) $customer->loyalty_total_spent);
        // 1.480.000 drops below the 1.500.000 Gold threshold: the tier re-syncs to Silver (Q1).
        $this->assertSame('silver', $customer->loyalty_tier);
    }

    public function test_customer_lock_comes_after_the_return_and_before_the_kitchen(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 1]], [
            'discount' => 2000, 'customer_id' => $this->member(points: 10)->id, 'grand_total' => 18000,
        ]);
        $return = $this->draft($user, $sale, [$this->item($details[0], 1)]);

        $order = [];
        DB::listen(function (QueryExecuted $query) use (&$order) {
            // `.` matches the quoting char: double quotes on SQLite, backticks on CI's MySQL job.
            if (DB::transactionLevel() >= 1 && preg_match('/from\s+.(sales_returns|customers|kitchen_items)./', $query->sql, $match)) {
                $order[] = $match[1];
            }
        });

        $this->actingAs($user)->post(route('sales-returns.complete', $return))->assertSessionHasNoErrors();

        $index = fn (string $table) => array_search($table, $order, true);
        $this->assertNotFalse($index('sales_returns'), 'the return row is locked');
        $this->assertNotFalse($index('customers'), 'the customer is locked');
        $this->assertNotFalse($index('kitchen_items'), 'the kitchen items are read');
        $this->assertLessThan($index('customers'), $index('sales_returns'), 'after the return row');
        $this->assertLessThan($index('kitchen_items'), $index('customers'), 'before the kitchen items');
    }

    public function test_migration_adds_the_columns_with_zero_defaults(): void
    {
        foreach (['discount_deducted', 'points_restored', 'points_reversed'] as $column) {
            $this->assertTrue(Schema::hasColumn('sales_returns', $column), $column);
        }

        $sale = $this->makeSale($this->user(), [['price' => 10000, 'qty' => 1]], [])[0];
        $return = SalesReturn::create([
            'code' => 'SR-DEFAULT', 'transaction_id' => $sale->id, 'status' => 'draft', 'return_type' => 'refund_cash',
        ]);

        $this->assertSame(0, (int) $return->fresh()->discount_deducted);
        $this->assertSame(0, (int) $return->fresh()->points_restored);
        $this->assertSame(0, (int) $return->fresh()->points_reversed);
    }

    public function test_api_complete_matches_web_with_discount_and_points(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        $customer = $this->member(points: 100);
        $bill = [
            'discount' => 2000, 'loyalty_discount_total' => 5000, 'loyalty_points_redeemed' => 50, 'loyalty_points_earned' => 30,
            'customer_id' => $customer->id, 'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100, 'grand_total' => 16100,
        ];
        [$webSale, $webDetails] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], $bill);
        [$apiSale, $apiDetails] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], $bill);

        $webDraft = $this->draft($user, $webSale, [$this->item($webDetails[0], 2)]);
        $apiDraft = $this->draft($user, $apiSale, [$this->item($apiDetails[0], 2)]);

        $web = $this->complete($user, $webDraft);

        Sanctum::actingAs($user, ['sales-returns-access', 'sales-returns-create', 'sales-returns-complete']);
        $this->postJson(route('api.sales-returns.complete', $apiDraft))->assertOk();
        $api = $apiDraft->fresh();

        foreach (['discount_deducted', 'points_restored', 'points_reversed', 'total_return_amount', 'refund_amount'] as $field) {
            $this->assertSame((int) $web->{$field}, (int) $api->{$field}, $field);
        }
    }

    // --- helpers -----------------------------------------------------------------------------------------

    private function billFrom(array $case): array
    {
        return [
            'discount' => $case['bill']['discount'],
            'loyalty_discount_total' => $case['bill']['loyalty_discount_total'],
            'customer_voucher_discount' => $case['bill']['customer_voucher_discount'],
            'service_charge' => $case['bill']['service_charge'],
            'tax_total' => $case['bill']['refundable_tax'],
            'tax_kind' => $case['bill']['tax_kind'],
            'tax_rate' => 0,
            'grand_total' => $case['bill']['grand_total'],
            'shipping_cost' => $case['bill']['shipping_cost'],
        ];
    }

    private function user(): User
    {
        return tap(User::factory()->create(), fn (User $user) => $user->givePermissionTo([
            'transactions-access',
            'sales-returns-access',
            'sales-returns-create',
            'sales-returns-complete',
            'cashier-shifts-access',
            'cashier-shifts-open',
            'cashier-shifts-close',
        ]));
    }

    private function customer(): Customer
    {
        return Customer::create(['name' => 'Pelanggan', 'no_telp' => '0812', 'address' => 'Jalan']);
    }

    private function member(int $points = 0, string $tier = 'regular', int $spent = 0): Customer
    {
        return Customer::create([
            'name' => 'Member '.Str::upper(Str::random(4)),
            'no_telp' => '0812',
            'address' => 'Jalan',
            'is_loyalty_member' => true,
            'member_code' => 'MEM-'.Str::upper(Str::random(6)),
            'loyalty_tier' => $tier,
            'loyalty_points' => $points,
            'loyalty_total_spent' => $spent,
            'loyalty_member_since' => now()->subMonth(),
        ]);
    }

    private function openShiftFor(User $user): CashierShift
    {
        return CashierShift::create([
            'user_id' => $user->id,
            'opened_by' => $user->id,
            'opened_at' => now(),
            'opening_cash' => 100000,
            'expected_cash' => 100000,
            'status' => 'open',
        ]);
    }

    /**
     * A finished sale with the given line totals (each line's `price` is the line total) and stored bill figures.
     *
     * @param  array<int, array{price:int, qty:int}>  $lines
     * @param  array<string, mixed>  $bill
     * @return array{0: Transaction, 1: array<int, TransactionDetail>}
     */
    private function makeSale(User $user, array $lines, array $bill): array
    {
        $category = Category::create(['name' => 'Kategori '.Str::upper(Str::random(5)), 'description' => 'x', 'image' => 'c.png']);
        $product = Product::create([
            'category_id' => $category->id, 'image' => 'p.png', 'barcode' => 'BRCD-'.Str::upper(Str::random(8)),
            'sku' => 'SKU-'.Str::upper(Str::random(8)), 'title' => 'Produk '.Str::upper(Str::random(4)),
            'description' => 'x', 'buy_price' => 10000, 'sell_price' => 10000, 'stock' => 100, 'tax_rate' => 0,
        ]);

        $grand = (int) ($bill['grand_total'] ?? array_sum(array_column($lines, 'price')));
        $transaction = Transaction::create([
            'cashier_id' => $user->id,
            'customer_id' => $bill['customer_id'] ?? null,
            'invoice' => 'TRX-'.Str::upper(Str::random(8)),
            'cash' => $grand,
            'change' => 0,
            'discount' => $bill['discount'] ?? 0,
            'loyalty_discount_total' => $bill['loyalty_discount_total'] ?? 0,
            'customer_voucher_discount' => $bill['customer_voucher_discount'] ?? 0,
            'loyalty_points_redeemed' => $bill['loyalty_points_redeemed'] ?? 0,
            'loyalty_points_earned' => $bill['loyalty_points_earned'] ?? 0,
            'shipping_cost' => $bill['shipping_cost'] ?? 0,
            'service_charge' => $bill['service_charge'] ?? 0,
            'tax_total' => $bill['tax_total'] ?? 0,
            'tax_rate' => $bill['tax_rate'] ?? null,
            'tax_kind' => $bill['tax_kind'] ?? null,
            'grand_total' => $grand,
            'payment_method' => $bill['payment_method'] ?? 'cash',
            'payment_status' => $bill['payment_status'] ?? 'paid',
        ]);

        $details = [];
        foreach ($lines as $line) {
            $details[] = $transaction->details()->create([
                'product_id' => $product->id,
                'qty' => $line['qty'],
                'conversion_factor' => 1,
                'base_unit_price' => 10000,
                'unit_price' => 10000,
                'price' => $line['price'],
                'cost_total' => 0,
            ]);
        }
        $transaction->profits()->create(['total' => 0]);

        if (($bill['payment_method'] ?? 'cash') === 'pay_later') {
            Receivable::create([
                'customer_id' => $bill['customer_id'] ?? null,
                'transaction_id' => $transaction->id,
                'invoice' => $transaction->invoice,
                'total' => $grand,
                'paid' => 0,
                'due_date' => now()->addDays(14),
                'status' => 'unpaid',
            ]);
        }

        return [$transaction->fresh('details'), $details];
    }

    private function item($detail, int $qty): array
    {
        return [
            'transaction_detail_id' => $detail->id,
            'qty_return' => $qty,
            'return_reason' => 'Kemasan rusak',
            'restock_to_inventory' => false,
        ];
    }

    private function draft(User $user, Transaction $sale, array $items, string $returnType = 'refund_cash'): SalesReturn
    {
        $this->actingAs($user)->post(route('sales-returns.store', $sale), [
            'return_type' => $returnType,
            'items' => $items,
        ])->assertSessionHasNoErrors();

        return SalesReturn::latest('id')->firstOrFail();
    }

    private function complete(User $user, SalesReturn $salesReturn): SalesReturn
    {
        $this->actingAs($user)->post(route('sales-returns.complete', $salesReturn))->assertSessionHasNoErrors();

        return $salesReturn->fresh();
    }

    /** A completed return already on the sale, as an earlier completion or one from before release would leave it. */
    private function recordCompleted(Transaction $sale, int $itemValue, int $service, int $tax, int $discount = 0): SalesReturn
    {
        return SalesReturn::create([
            'code' => 'SR-OLD-'.Str::upper(Str::random(6)),
            'transaction_id' => $sale->id,
            'status' => 'completed',
            'return_type' => 'refund_cash',
            'refund_amount' => $itemValue - $discount + $service + $tax,
            'credited_amount' => 0,
            'service_refunded' => $service,
            'tax_refunded' => $tax,
            'discount_deducted' => $discount,
            'total_return_amount' => $itemValue - $discount + $service + $tax,
            'completed_at' => now(),
        ]);
    }
}
