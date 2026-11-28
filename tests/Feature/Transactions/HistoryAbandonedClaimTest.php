<?php

namespace Tests\Feature\Transactions;

use App\Models\CashierShift;
use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\DineOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * #532: Transactions History flags a pay-later sale or a dine bill whose gateway claim was abandoned (a pending
 * tender with no reference after two minutes), and a late payment of that claim is never booked twice.
 */
class HistoryAbandonedClaimTest extends TestCase
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
        foreach (['transactions-access', 'cashier-shifts-access'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        $this->outlet = Outlet::create(['code' => 'OUT-1', 'name' => 'Outlet 1', 'is_active' => true]);
        $this->warehouse = Warehouse::create([
            'code' => 'WH-1', 'name' => 'Gudang Outlet 1', 'status' => 'active', 'outlet_id' => $this->outlet->id,
        ]);
        $category = Category::create(['name' => 'Kategori', 'image' => '', 'description' => '']);
        $this->product = Product::create([
            'title' => 'Produk Kasir', 'barcode' => 'HAC-001', 'sku' => 'SKU-HAC-001', 'image' => '', 'description' => '',
            'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 20, 'category_id' => $category->id,
            'tax_type' => 'exclusive', 'tax_rate' => 0, 'min_stock' => 0, 'max_stock' => 100, 'is_composite' => false,
            'needs_preparation' => false,
        ]);
        $this->product->warehouses()->attach($this->warehouse->id, ['stock' => 20]);
        $this->cashier = $this->cashierAt($this->warehouse);
    }

    /** A cashier of the warehouse's outlet, with an open shift there. */
    private function cashierAt(Warehouse $warehouse): User
    {
        $user = User::factory()->create();
        $user->markEmailAsVerified();
        $user->givePermissionTo(['transactions-access', 'cashier-shifts-access']);
        $user->outlets()->attach($warehouse->outlet_id);
        app(CashierShiftService::class)->openShift($user, $user, 100000, null, $warehouse->id);

        return $user;
    }

    /** A guest pay-later sale of 10000 with no receivable, made by the cashier. */
    private function payLaterBill(?Warehouse $warehouse = null): Transaction
    {
        $warehouse ??= $this->warehouse;

        return Transaction::create([
            'cashier_id' => $this->cashier->id,
            'cashier_shift_id' => CashierShift::query()->open()->where('user_id', $this->cashier->id)->value('id'),
            'warehouse_id' => $warehouse->id,
            'invoice' => 'TRX-HAC-'.++$this->seq,
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => 10000,
            'payment_method' => 'pay_later', 'payment_status' => 'unpaid', 'is_pay_later' => true,
        ]);
    }

    /** A dine-in bill of 10000 accepted by the cashier, linked to its DineOrder. */
    private function dineBill(): Transaction
    {
        $table = DiningTable::create(['name' => 'Meja 7', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);
        $order = DineOrder::create([
            'dine_table_id' => $table->id, 'cashier_id' => $this->cashier->id, 'status' => DineOrder::STATUS_SUBMITTED,
            'submitted_at' => now(), 'payment_option' => DineOrder::PAY_AT_COUNTER, 'subtotal' => 10000, 'item_count' => 1,
        ]);
        $order->items()->create(['product_id' => $this->product->id, 'qty' => 1, 'price' => 10000]);
        app(DineOrderService::class)->accept($order);

        return Transaction::findOrFail($order->fresh()->transaction_id);
    }

    /** What a request that died between saving the payment and storing the charge leaves: pending, no reference. */
    private function pendingClaim(Transaction $bill, ?string $reference = null): TransactionTender
    {
        $bill->forceFill([
            'payment_method' => TransactionTender::METHOD_QRIS, 'payment_status' => 'pending', 'payment_attempt' => 1,
        ])->save();

        return $bill->tenders()->create([
            'method' => TransactionTender::METHOD_QRIS,
            'amount' => (int) $bill->grand_total,
            'payment_status' => TransactionTender::STATUS_PENDING,
            'payment_reference' => $reference,
        ]);
    }

    /** The same claim two minutes later, when the POS may replace it. */
    private function abandonedClaim(Transaction $bill): TransactionTender
    {
        $claim = $this->pendingClaim($bill);
        $this->travel(121)->seconds();

        return $claim;
    }

    /** @return Collection<int, array<string, mixed>> the rows the History page lists */
    private function historyRows(): Collection
    {
        $rows = collect();
        $this->actingAs($this->cashier)->get(route('transactions.history'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows) {
                $rows = collect($page->toArray()['props']['transactions']['data']);
            });

        return $rows;
    }

    private function settle(Transaction $bill): TestResponse
    {
        return $this->actingAs($this->cashier)->post(route('transactions.settle', $bill), [
            'tenders' => [['method' => 'cash', 'amount' => 10000, 'cash_received' => 10000]],
        ]);
    }

    private function enableMidtrans(): void
    {
        PaymentSetting::create([
            'default_gateway' => 'cash', 'midtrans_enabled' => true, 'midtrans_server_key' => 'sk-test', 'midtrans_client_key' => 'ck-test',
        ]);
    }

    /** The gateway's signed "paid" callback for one order id. */
    private function notifyMidtrans(Transaction $bill, string $orderId): TestResponse
    {
        $gross = (int) $bill->grand_total.'.00';

        return $this->postJson(route('webhooks.midtrans'), [
            'order_id' => $orderId, 'status_code' => '200', 'gross_amount' => $gross,
            'transaction_status' => 'settlement', 'transaction_id' => 'mt-'.$orderId,
            'signature_key' => hash('sha512', $orderId.'200'.$gross.'sk-test'),
        ]);
    }

    public function test_a_pay_later_sale_with_an_abandoned_claim_is_flagged(): void
    {
        $bill = $this->payLaterBill();
        $this->abandonedClaim($bill);

        $row = $this->historyRows()->firstWhere('id', $bill->id);

        // The flag the list offers Bayar from, and the columns the addSelect must not drop.
        $this->assertNotNull($row);
        $this->assertNotEmpty($row['abandoned_claim']);
        $this->assertSame($bill->invoice, $row['invoice']);
        $this->assertSame(10000, (int) $row['grand_total']);
        $this->assertSame(10000, (int) $row['owed_total']);
        $this->assertSame('pending', $row['payment_status']);
    }

    public function test_a_dine_bill_with_an_abandoned_claim_is_flagged(): void
    {
        $bill = $this->dineBill();
        $this->abandonedClaim($bill);

        $row = $this->historyRows()->firstWhere('id', $bill->id);

        $this->assertNotNull($row);
        $this->assertNotEmpty($row['abandoned_claim']);
        $this->assertSame(
            [$bill->invoice, 10000, 10000, 'pending'],
            [$row['invoice'], (int) $row['grand_total'], (int) $row['owed_total'], $row['payment_status']],
        );
    }

    public function test_a_fresh_or_stored_claim_is_not_abandoned(): void
    {
        $fresh = $this->payLaterBill();
        $this->pendingClaim($fresh); // younger than two minutes
        $stored = $this->payLaterBill();
        $this->pendingClaim($stored, 'gw-ref'); // the gateway answered

        $rows = $this->historyRows()->keyBy('id');

        $this->assertNull($rows[$fresh->id]['abandoned_claim']);
        $this->assertNull($rows[$stored->id]['abandoned_claim']);
    }

    public function test_a_late_webhook_of_an_abandoned_pay_later_claim_is_flagged_not_booked_twice(): void
    {
        $this->enableMidtrans();
        $bill = $this->payLaterBill();
        $claim = $this->abandonedClaim($bill);
        $orderId = $claim->order_reference; // invoice-r1, the attempt the cashier left behind

        $this->settle($bill)->assertSessionHasNoErrors();
        $bill->refresh();
        $this->assertSame(['paid', 'cash', 2], [$bill->payment_status, $bill->payment_method, $bill->payment_attempt]);

        // The gateway had created that first charge after all, and it is paid now.
        $this->notifyMidtrans($bill, $orderId)->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertNotNull($bill->late_superseded_payment_at);
        $this->assertSame(1, $bill->tenders()->where('payment_status', TransactionTender::STATUS_PAID)->count());
        $this->assertSame(1, $bill->tenders()->count());
    }

    public function test_the_abandoned_claims_own_payment_books_and_a_later_bayar_is_refused(): void
    {
        $this->enableMidtrans();
        $bill = $this->payLaterBill();
        $claim = $this->abandonedClaim($bill);

        $this->notifyMidtrans($bill, $claim->order_reference)->assertOk();
        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $tenders = $bill->tenders()->count();
        $this->assertSame(1, $tenders);

        $this->settle($bill)->assertSessionHasErrors('bill');
        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame($tenders, $bill->tenders()->count());
    }

    public function test_history_never_lists_another_outlets_claim(): void
    {
        $mine = $this->payLaterBill();
        $this->abandonedClaim($mine);
        $otherOutlet = Outlet::create(['code' => 'OUT-2', 'name' => 'Outlet 2', 'is_active' => true]);
        $otherWarehouse = Warehouse::create([
            'code' => 'WH-2', 'name' => 'Gudang Outlet 2', 'status' => 'active', 'outlet_id' => $otherOutlet->id,
        ]);
        $theirs = $this->payLaterBill($otherWarehouse);
        $this->abandonedClaim($theirs);

        $this->assertSame([$mine->invoice], $this->historyRows()->pluck('invoice')->all());
    }

    public function test_settling_another_outlets_bill_is_404(): void
    {
        $otherOutlet = Outlet::create(['code' => 'OUT-2', 'name' => 'Outlet 2', 'is_active' => true]);
        $otherWarehouse = Warehouse::create([
            'code' => 'WH-2', 'name' => 'Gudang Outlet 2', 'status' => 'active', 'outlet_id' => $otherOutlet->id,
        ]);
        $bill = $this->payLaterBill($otherWarehouse);

        // The controller's outlet scope answers before the service looks at the shift.
        $this->settle($bill)->assertNotFound();
        $this->assertSame('unpaid', $bill->fresh()->payment_status);
    }
}
