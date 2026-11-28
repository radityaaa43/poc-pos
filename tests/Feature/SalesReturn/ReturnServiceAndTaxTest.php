<?php

namespace Tests\Feature\SalesReturn;

use App\Models\AuditLog;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Services\ReturnBillShareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * A return gives back the returned items' proportional share of the sale's service charge and tax (REQ-010 AC-10),
 * integer cumulative rounding, never the cash rounding. Money columns are whole-rupiah integers.
 */
class ReturnServiceAndTaxTest extends TestCase
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

    public function test_partial_return_refunds_items_plus_proportional_service_and_tax(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        // PB1 10 % + service 5 %: lines 40.000 (qty 2) and 10.000, total 50.000 -> service 2.500, tax 5.250.
        [$sale, $details] = $this->makeSale($user, [
            ['price' => 40000, 'qty' => 2],
            ['price' => 10000, 'qty' => 1],
        ], ['service_charge' => 2500, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 5250, 'grand_total' => 57750]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        // Return 1 of the first line: 20.000 items + 1.000 service + 2.100 tax.
        $this->assertSame(1000, $return->service_refunded);
        $this->assertSame(2100, $return->tax_refunded);
        $this->assertSame(23100, $return->total_return_amount);
        $this->assertSame(23100, $return->refund_amount);
    }

    public function test_ppn_sale_with_service_refunds_proportionally(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        // PPN 11 % per line + service 5 %: line 20.000 taxed 2.200, service 1.000 taxed 110 -> tax_total 2.310.
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'service_charge' => 1000, 'tax_kind' => 'ppn', 'tax_rate' => 11, 'tax_total' => 2310, 'grand_total' => 23310,
        ]);

        $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));
        $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $returns = SalesReturn::all();
        $this->assertSame(1000, $returns->sum('service_refunded'));
        $this->assertSame(2310, $returns->sum('tax_refunded'));
        $this->assertSame(0, $sale->fresh()->remainingTotal());
    }

    public function test_the_last_return_takes_the_remainder(): void
    {
        $user = $this->user();
        [$sale] = $this->makeSale($user, [['price' => 30000, 'qty' => 3]], [
            'service_charge' => 100, 'tax_kind' => 'ppn', 'tax_total' => 0, 'grand_total' => 30100,
        ]);

        $service = app(ReturnBillShareService::class);
        $shares = [];
        $returned = 0;
        for ($i = 0; $i < 3; $i++) {
            $share = $service->forReturn($sale, 10000)['service_refunded'];
            $shares[] = $share;
            $returned += 10000;
            $this->recordCompleted($sale, 10000, $share, 0);
        }

        // The cumulative shares add up to exactly the 100 charged; no part over-refunds.
        $this->assertSame(100, array_sum($shares));
        $this->assertSame(0, $service->forReturn($sale, 10000)['service_refunded']);
    }

    public function test_returns_never_refund_more_than_was_charged(): void
    {
        $user = $this->user();
        [$sale] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'service_charge' => 1000, 'tax_kind' => 'ppn', 'tax_rate' => 11, 'tax_total' => 2310, 'grand_total' => 23310,
        ]);

        $service = app(ReturnBillShareService::class);
        $first = $service->forReturn($sale, 10000);
        $this->recordCompleted($sale, 10000, $first['service_refunded'], $first['tax_refunded']);
        // Asking for more than is left (the base cap) refunds the rest, never a second full amount.
        $rest = $service->forReturn($sale, 20000);

        $this->assertSame(1000, $first['service_refunded'] + $rest['service_refunded']);
        $this->assertSame(2310, $first['tax_refunded'] + $rest['tax_refunded']);

        $this->recordCompleted($sale, 10000, $rest['service_refunded'], $rest['tax_refunded']);
        $this->assertSame(0, $service->forReturn($sale, 20000)['service_refunded']);
    }

    public function test_full_return_refunds_the_grand_total(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 10000, 'qty' => 1]], [
            'service_charge' => 500, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 1050, 'grand_total' => 11550,
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $this->assertSame(11550, $return->total_return_amount);
        $this->assertSame(11550, $return->refund_amount);
        $this->assertSame(0, $sale->fresh()->remainingTotal());
    }

    public function test_cash_rounding_is_not_refunded(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        // The bill carries a +250 cash rounding beyond items + service + tax; a return never gives it back (#452).
        [$sale, $details] = $this->makeSale($user, [['price' => 10000, 'qty' => 1]], [
            'service_charge' => 500, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 1050, 'grand_total' => 11800,
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $this->assertSame(11550, $return->refund_amount);
        $this->assertNotSame((int) $sale->grand_total, $return->refund_amount);
    }

    public function test_pre_release_sale_refunds_tax_but_never_service(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        // A pre-release sale: no tax_kind, so it is read as PPN with no service charge (A1/BR-2).
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 1]], [
            'service_charge' => 1000, 'tax_kind' => null, 'tax_rate' => 11, 'tax_total' => 2200, 'grand_total' => 23200,
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $this->assertSame(0, $return->service_refunded);
        $this->assertSame(2200, $return->tax_refunded);
        $this->assertSame(22200, $return->total_return_amount);
    }

    public function test_completed_old_returns_not_recomputed(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        // An items-only return completed before release: no shares stored (BR-1).
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'tax_kind' => null, 'tax_rate' => 11, 'tax_total' => 2200, 'grand_total' => 22200,
        ]);
        $old = $this->recordCompleted($sale, 10000, 0, 0);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        // The old return keeps its stored figures; the new one refunds only the tax for its own item.
        $this->assertSame([0, 0, 10000], [$old->fresh()->service_refunded, $old->fresh()->tax_refunded, $old->fresh()->total_return_amount]);
        $this->assertSame(1100, $return->tax_refunded);
        $this->assertSame(11100, $return->total_return_amount);
    }

    public function test_shipping_tax_is_not_refunded(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        // PPN 11 %: line 20.000 taxed 2.200, shipping 10.000 taxed 1.100 -> tax_total 3.300. Shipping is not a line.
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 1]], [
            'shipping_cost' => 10000, 'tax_kind' => 'ppn', 'tax_rate' => 11, 'tax_total' => 3300, 'grand_total' => 33300,
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $this->assertSame(2200, $return->tax_refunded);
        $this->assertSame(22200, $return->total_return_amount);
    }

    public function test_open_bill_return_lowers_what_is_owed_by_the_full_share(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100, 'grand_total' => 23100,
            'payment_method' => 'pay_later', 'payment_status' => 'unpaid',
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        // No cash out: the bill just owes items + share less.
        $this->assertSame(0, $return->refund_amount);
        $this->assertSame(11550, $return->total_return_amount);
        $this->assertSame(11550, $sale->fresh()->remainingTotal());
    }

    public function test_receivable_drops_by_items_plus_share(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        $customer = $this->customer();
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100, 'grand_total' => 23100,
            'payment_method' => 'pay_later', 'payment_status' => 'unpaid', 'customer_id' => $customer->id,
        ]);
        Receivable::create([
            'customer_id' => $customer->id, 'transaction_id' => $sale->id, 'invoice' => $sale->invoice,
            'total' => 23100, 'paid' => 0, 'due_date' => now()->addDays(7), 'status' => 'unpaid',
        ]);

        $return = $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $this->assertSame(11550, $sale->receivable()->first()->total);
        $this->assertSame(0, $return->refund_amount);
    }

    public function test_store_credit_includes_the_share(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        $customer = $this->customer();
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 1]], [
            'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100, 'grand_total' => 23100,
            'customer_id' => $customer->id,
        ]);

        $this->actingAs($user)->post(route('sales-returns.store', $sale), [
            'return_type' => 'store_credit',
            'items' => [$this->item($details[0], 1)],
        ])->assertSessionHasNoErrors();
        $return = $this->complete($user, SalesReturn::latest('id')->first());

        $this->assertSame(23100, $return->credited_amount);
        $this->assertSame(23100, CustomerCredit::where('sales_return_id', $return->id)->value('amount'));
    }

    public function test_drafts_completed_in_reverse_order_still_add_up(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], [
            'service_charge' => 100, 'tax_kind' => 'ppn', 'tax_total' => 0, 'grand_total' => 20100,
        ]);

        $this->actingAs($user)->post(route('sales-returns.store', $sale), ['return_type' => 'refund_cash', 'items' => [$this->item($details[0], 1)]])->assertSessionHasNoErrors();
        $first = SalesReturn::latest('id')->first();
        $this->actingAs($user)->post(route('sales-returns.store', $sale), ['return_type' => 'refund_cash', 'items' => [$this->item($details[0], 1)]])->assertSessionHasNoErrors();
        $second = SalesReturn::latest('id')->first();

        $this->complete($user, $second);
        $this->complete($user, $first);

        // Whatever the order, the two returns share the 100 service charge exactly.
        $this->assertSame(100, SalesReturn::sum('service_refunded'));
        $this->assertSame(20000, SalesReturn::all()->sum(fn (SalesReturn $return) => $return->total_return_amount - $return->service_refunded));
    }

    public function test_update_draft_recomputes_the_share(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 30000, 'qty' => 3]], [
            'service_charge' => 100, 'tax_kind' => 'ppn', 'tax_total' => 0, 'grand_total' => 30100,
        ]);

        $this->actingAs($user)->post(route('sales-returns.store', $sale), ['return_type' => 'refund_cash', 'items' => [$this->item($details[0], 1)]])->assertSessionHasNoErrors();
        $draft = SalesReturn::latest('id')->first();
        $this->assertSame(33, $draft->service_refunded);

        // Another return completes, then the draft is edited: it recomputes against the new completed total.
        $this->actingAs($user)->post(route('sales-returns.store', $sale), ['return_type' => 'refund_cash', 'items' => [$this->item($details[0], 1)]])->assertSessionHasNoErrors();
        $completed = $this->complete($user, SalesReturn::latest('id')->first());

        $this->actingAs($user)->patch(route('sales-returns.update', $draft), [
            'return_type' => 'refund_cash',
            'items' => [$this->item($details[0], 1)],
        ])->assertSessionHasNoErrors();

        $this->assertSame(34, $draft->fresh()->service_refunded);
        $this->assertSame(33, $completed->fresh()->service_refunded);
    }

    public function test_api_complete_matches_web(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        $bill = ['service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100, 'grand_total' => 23100];
        [$webSale, $webDetails] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], $bill);
        [$apiSale, $apiDetails] = $this->makeSale($user, [['price' => 20000, 'qty' => 2]], $bill);

        $webDraft = $this->draft($user, $webSale, [$this->item($webDetails[0], 1)]);
        $apiDraft = $this->draft($user, $apiSale, [$this->item($apiDetails[0], 1)]);

        $web = $this->complete($user, $webDraft);

        Sanctum::actingAs($user, ['sales-returns-access', 'sales-returns-create', 'sales-returns-complete']);
        $this->postJson(route('api.sales-returns.complete', $apiDraft))
            ->assertOk();
        $api = $apiDraft->fresh();

        $this->assertSame(
            [$web->service_refunded, $web->tax_refunded, $web->total_return_amount, $web->refund_amount],
            [$api->service_refunded, $api->tax_refunded, $api->total_return_amount, $api->refund_amount],
        );
    }

    public function test_audit_payload_has_the_shares(): void
    {
        $user = $this->user();
        $this->openShiftFor($user);
        [$sale, $details] = $this->makeSale($user, [['price' => 20000, 'qty' => 1]], [
            'service_charge' => 1000, 'tax_kind' => 'pb1', 'tax_rate' => 10, 'tax_total' => 2100, 'grand_total' => 23100,
        ]);

        $this->complete($user, $this->draft($user, $sale, [$this->item($details[0], 1)]));

        $after = AuditLog::where('event', 'sales_return.completed')->latest('id')->first()->after;
        $this->assertSame(1000, $after['service_refunded']);
        $this->assertSame(2100, $after['tax_refunded']);
    }

    public function test_zero_base_sale_refunds_no_share(): void
    {
        $user = $this->user();
        [$sale] = $this->makeSale($user, [['price' => 0, 'qty' => 1]], [
            'service_charge' => 500, 'tax_kind' => 'ppn', 'tax_rate' => 11, 'tax_total' => 55, 'grand_total' => 555,
        ]);

        $this->assertSame(
            ['service_refunded' => 0, 'tax_refunded' => 0],
            app(ReturnBillShareService::class)->forReturn($sale, 1000),
        );
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
            'discount' => 0,
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

    private function draft(User $user, Transaction $sale, array $items): SalesReturn
    {
        $this->actingAs($user)->post(route('sales-returns.store', $sale), [
            'return_type' => 'refund_cash',
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
    private function recordCompleted(Transaction $sale, int $itemValue, int $service, int $tax): SalesReturn
    {
        return SalesReturn::create([
            'code' => 'SR-OLD-'.Str::upper(Str::random(6)),
            'transaction_id' => $sale->id,
            'status' => 'completed',
            'return_type' => 'refund_cash',
            'refund_amount' => $itemValue + $service + $tax,
            'credited_amount' => 0,
            'service_refunded' => $service,
            'tax_refunded' => $tax,
            'total_return_amount' => $itemValue + $service + $tax,
            'completed_at' => now(),
        ]);
    }
}
