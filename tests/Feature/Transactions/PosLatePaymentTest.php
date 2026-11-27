<?php

namespace Tests\Feature\Transactions;

use App\Models\AuditLog;
use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\TransactionTender;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\TransactionSettlementService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #380: money a gateway settles on a POS sale whose own charge expired is either booked or flagged for a manual
 * refund, never silently dropped. The rule is the one of #327 for dine bills (see PaymentWebhookController::decide).
 */
class PosLatePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const TOTAL = 20000;

    private User $cashier;

    private Warehouse $warehouse;

    private CashierShift $shift;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, UserSeeder::class]);
        $this->cashier = User::where('email', 'cashier@gmail.com')->first();
        $this->cashier->markEmailAsVerified();
        $this->warehouse = Warehouse::create(['code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0, 'status' => 'active']);
        $this->shift = app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 0, null, $this->warehouse->id);
        $this->product = Product::create([
            'image' => 'test.png', 'barcode' => 'LATE-1', 'sku' => 'LATE-1', 'title' => 'Produk Late', 'description' => 'test',
            'category_id' => Category::create(['name' => 'Late'])->id, 'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 20,
            'tax_rate' => 0, 'tax_type' => 'exclusive',
        ]);
        $this->warehouse->products()->attach($this->product->id, ['stock' => 20]);

        PaymentSetting::create([
            'default_gateway' => 'midtrans', 'midtrans_enabled' => true, 'midtrans_server_key' => 'sk-test', 'midtrans_client_key' => 'ck-test',
            'xendit_enabled' => true, 'xendit_secret_key' => 'xnd-secret', 'xendit_public_key' => 'xnd-public', 'xendit_callback_token' => 'callback-token',
        ]);
    }

    /**
     * A POS sale whose own gateway charge (one QRIS tender) ended with $tenderStatus; the sale is failed.
     * $tenderStatus is 'pending' for a sale transactions:expire failed (it does not touch the tender).
     */
    private function sale(string $tenderStatus = 'failed', array $overrides = []): Transaction
    {
        $sale = Transaction::create(array_merge([
            'cashier_id' => $this->cashier->id, 'cashier_shift_id' => $this->shift->id, 'warehouse_id' => $this->warehouse->id,
            'invoice' => 'TRX-LATE-'.Str::upper(Str::random(6)), 'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0,
            'grand_total' => self::TOTAL, 'payment_method' => 'qris', 'payment_status' => 'failed',
        ], $overrides));
        $sale->tenders()->create(['method' => TransactionTender::METHOD_QRIS, 'amount' => self::TOTAL, 'payment_status' => $tenderStatus]);
        TransactionDetail::create([
            'transaction_id' => $sale->id, 'product_id' => $this->product->id, 'qty' => 2, 'conversion_factor' => 1,
            'base_unit_price' => 10000, 'unit_price' => 10000, 'price' => 10000,
        ]);

        return $sale;
    }

    /** A single-method QRIS sale through the real web POS checkout: one payment, no tenders[], so no tender rows. */
    private function checkoutWithQris(int $qty = 2): Transaction
    {
        Http::fake(['app.sandbox.midtrans.com/*' => Http::response(['token' => 'tok', 'redirect_url' => 'https://pay.test/m', 'qr_string' => '000201QR'], 201)]);
        Cart::create(['cashier_id' => $this->cashier->id, 'product_id' => $this->product->id, 'qty' => $qty, 'price' => $qty * 10000]);

        $this->actingAs($this->cashier)->post(route('transactions.store'), ['payment_gateway' => 'qris'])->assertSessionHasNoErrors();

        $sale = Transaction::latest('id')->firstOrFail();
        $this->assertSame('pending', $sale->payment_status);
        $this->assertSame('qris', $sale->payment_method);
        $this->assertSame(0, $sale->tenders()->count());
        $this->assertSame(0, $sale->payment_attempt);

        return $sale;
    }

    /** What transactions:expire does to a sale that is old enough: fails it and puts its goods back in stock. */
    private function expire(Transaction $sale): Transaction
    {
        $sale->forceFill(['created_at' => now()->subHours(25)])->save();
        $this->artisan('transactions:expire')->assertSuccessful();
        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertTrue($sale->wasRestocked());

        return $sale;
    }

    /** A credit sale the way the customer portal sees it: a receivable, charged under the bare invoice, no tender, no shift. */
    private function portalSale(string $status = 'failed', int $paid = 0): Transaction
    {
        $sale = Transaction::create([
            'cashier_id' => $this->cashier->id, 'warehouse_id' => $this->warehouse->id, 'invoice' => 'INV-PUB-'.Str::upper(Str::random(6)),
            'cash' => 0, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => self::TOTAL,
            'payment_method' => 'pay_later', 'payment_status' => $status, 'is_pay_later' => true,
        ]);
        $receivable = Receivable::create([
            'transaction_id' => $sale->id, 'invoice' => $sale->invoice, 'due_date' => now()->addDays(7), 'total' => self::TOTAL, 'paid' => $paid,
            'status' => $paid >= self::TOTAL ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ]);
        if ($paid > 0) { // what the cashier took at the counter
            ReceivablePayment::create(['receivable_id' => $receivable->id, 'paid_at' => now(), 'amount' => $paid, 'method' => 'cash']);
        }

        return $sale;
    }

    private function restock(Transaction $sale): void
    {
        StockMutation::create([
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'reference_type' => 'transaction_expire',
            'reference_id' => $sale->id, 'mutation_type' => 'in', 'qty' => 2, 'stock_before' => 0, 'stock_after' => 2,
        ]);
    }

    private function notify(string $gateway, Transaction $sale, int $amount = self::TOTAL, string $reference = 'gw-ref'): TestResponse
    {
        if ($gateway === 'midtrans') {
            $gross = $amount.'.00';

            return $this->postJson(route('webhooks.midtrans'), [
                'order_id' => $sale->invoice, 'status_code' => '200', 'gross_amount' => $gross, 'transaction_status' => 'settlement',
                'transaction_id' => $reference, 'signature_key' => hash('sha512', $sale->invoice.'200'.$gross.'sk-test'),
            ]);
        }

        return $this->withHeader('X-CALLBACK-TOKEN', 'callback-token')->postJson(route('webhooks.xendit'), [
            'external_id' => $sale->invoice, 'status' => 'PAID', 'id' => $reference, 'paid_amount' => $amount,
        ]);
    }

    /** @return array<string, array<int, string>> */
    public static function gateways(): array
    {
        return ['midtrans' => ['midtrans'], 'xendit' => ['xendit']];
    }

    #[DataProvider('gateways')]
    public function test_an_expired_pos_qris_paid_later_is_booked_when_nothing_was_restocked(string $gateway): void
    {
        $sale = $this->sale();

        $this->notify($gateway, $sale, reference: 'late-ref')->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('late-ref', $sale->payment_reference);
        $this->assertNull($sale->late_superseded_payment_at);
        $this->assertNull($sale->paid_after_close_at); // its shift is still open
        $this->assertSame('paid', $sale->tenders()->sole()->payment_status);
        $log = AuditLog::where('event', 'payment.late_after_failed')->sole();
        $this->assertTrue($log->meta['booked']);
        $this->assertSame('late-ref', $log->meta['reference']);

        $this->notify($gateway, $sale, reference: 'late-ref')->assertOk(); // redelivered: nothing more
        $this->assertSame(1, AuditLog::where('event', 'payment.late_after_failed')->count());
        $this->assertNull($sale->fresh()->late_superseded_payment_at);
    }

    #[DataProvider('gateways')]
    public function test_an_expired_pos_qris_paid_later_is_flagged_when_the_sale_was_restocked(string $gateway): void
    {
        $sale = $this->sale();
        $this->restock($sale);

        $this->notify($gateway, $sale)->assertOk();

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertNotNull($sale->late_superseded_payment_at);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    #[DataProvider('gateways')]
    public function test_an_expired_pos_qris_paid_later_is_flagged_on_an_amount_mismatch(string $gateway): void
    {
        $sale = $this->sale();

        $this->notify($gateway, $sale, amount: 15000)->assertOk();

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertNotNull($sale->late_superseded_payment_at);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    public function test_the_restock_guard_alone_flags_a_late_payment_of_a_sale_the_command_expired(): void
    {
        $sale = $this->sale('pending', ['payment_status' => 'pending']);
        $sale->tenders()->delete(); // no tender row, so only the restock guard can stop the booking
        $sale->forceFill(['created_at' => now()->subHours(25)])->save();

        $this->artisan('transactions:expire')->assertSuccessful();
        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertTrue($sale->wasRestocked());
        $this->assertSame(0, $sale->tenders()->count());

        $this->notify('midtrans', $sale)->assertOk();

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status); // goods are back in stock: not booked
        $this->assertNotNull($sale->late_superseded_payment_at);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    public function test_a_pending_tender_alone_blocks_the_booking_of_a_sale_that_was_not_restocked(): void
    {
        $sale = $this->sale('pending'); // failed sale, its QR tender still pending (what the command leaves), nothing restocked
        $this->assertFalse($sale->wasRestocked());

        $this->notify('midtrans', $sale)->assertOk();

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertNotNull($sale->late_superseded_payment_at);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    // ---- a single-method POS sale writes no tender rows -----------------------------------------------

    #[DataProvider('gateways')]
    public function test_a_single_method_sale_expired_and_restocked_by_the_command_is_flagged_when_paid_later(string $gateway): void
    {
        $sale = $this->expire($this->checkoutWithQris());

        $this->notify($gateway, $sale, reference: 'late-ref')->assertOk();

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status); // not booked: stock and money must not disagree
        $this->assertNotNull($sale->late_superseded_payment_at);
        $log = AuditLog::where('event', 'payment.late_after_failed')->sole();
        $this->assertFalse($log->meta['booked']);
        $this->assertSame('late-ref', $log->meta['reference']);
    }

    #[DataProvider('gateways')]
    public function test_a_single_method_sale_failed_but_not_restocked_is_booked_when_paid_later(string $gateway): void
    {
        $sale = $this->checkoutWithQris();
        $sale->update(['payment_status' => 'failed']); // the gateway denied or expired the charge

        $this->notify($gateway, $sale, reference: 'late-ref')->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('late-ref', $sale->payment_reference);
        $this->assertNull($sale->late_superseded_payment_at);
        $this->assertTrue(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    #[DataProvider('gateways')]
    public function test_a_single_method_sale_paid_later_with_another_amount_is_flagged(string $gateway): void
    {
        $sale = $this->checkoutWithQris();
        $sale->update(['payment_status' => 'failed']);

        $this->notify($gateway, $sale, amount: 15000)->assertOk();

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertNotNull($sale->late_superseded_payment_at);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    #[DataProvider('gateways')]
    public function test_a_failed_sale_without_a_gateway_charge_of_its_own_stays_failed(string $gateway): void
    {
        // No tender row, no receivable and a method that is not a gateway one: nothing says a gateway ever charged it.
        $sale = $this->sale();
        $sale->tenders()->delete();
        $sale->update(['payment_method' => 'bank_transfer']);

        $this->notify($gateway, $sale)->assertOk();

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertNull($sale->late_superseded_payment_at);
        $this->assertSame(0, AuditLog::where('event', 'payment.late_after_failed')->count());
    }

    public function test_a_pay_later_pos_sale_without_a_receivable_stays_on_the_old_rule(): void
    {
        // A guest bill (is_pay_later, no receivable, method pay_later) has no gateway charge until a cashier settles it.
        $sale = $this->sale();
        $sale->tenders()->delete();
        $sale->update(['payment_method' => 'pay_later', 'is_pay_later' => true]);

        $this->notify('midtrans', $sale)->assertOk();

        $this->assertSame('failed', $sale->fresh()->payment_status);
        $this->assertSame(0, AuditLog::where('event', 'payment.late_after_failed')->count());
    }

    // ---- portal sales (BR-4): a credit sale charged through its receivable -----------------------------

    #[DataProvider('gateways')]
    public function test_a_portal_sale_whose_charge_expired_is_booked_when_paid_later(string $gateway): void
    {
        $sale = $this->portalSale();

        $this->notify($gateway, $sale, reference: 'portal-ref')->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertNull($sale->paid_after_close_at); // it has no shift: nothing to flag
        $this->assertNull($sale->late_superseded_payment_at);
        $receivable = $sale->receivable()->firstOrFail();
        $this->assertSame('paid', $receivable->status);
        $this->assertEquals(self::TOTAL, $receivable->paid);
        $this->assertTrue(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    #[DataProvider('gateways')]
    public function test_a_restocked_portal_sale_paid_later_is_flagged(string $gateway): void
    {
        $sale = $this->portalSale();
        $this->restock($sale);

        $this->notify($gateway, $sale)->assertOk();

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertNotNull($sale->late_superseded_payment_at);
        $this->assertEquals(0, $sale->receivable()->firstOrFail()->paid);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    #[DataProvider('gateways')]
    public function test_a_portal_sale_paid_later_with_another_amount_is_flagged(string $gateway): void
    {
        $sale = $this->portalSale();

        $this->notify($gateway, $sale, amount: 5000)->assertOk();

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertNotNull($sale->late_superseded_payment_at);
        $this->assertEquals(0, $sale->receivable()->firstOrFail()->paid);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    #[DataProvider('gateways')]
    public function test_a_partly_paid_portal_sale_is_booked_for_what_the_receivable_still_owes(string $gateway): void
    {
        $sale = $this->portalSale(paid: 5000); // the cashier took 5000 at the counter; the portal charged the 15000 left

        $this->notify($gateway, $sale, amount: 15000, reference: 'portal-ref')->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertNull($sale->late_superseded_payment_at);
        $receivable = $sale->receivable()->firstOrFail();
        $this->assertSame('paid', $receivable->status);
        $this->assertEquals(self::TOTAL, $receivable->paid);
        $this->assertEquals(15000, $receivable->payments()->where('method', $gateway)->sole()->amount);
        $this->assertTrue(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);

        // The gateway sends the same payment again: not a second payment, though a cashier payment is also on the receivable.
        $this->notify($gateway, $sale, amount: 15000, reference: 'portal-ref')->assertOk();
        $this->assertNull($sale->fresh()->late_superseded_payment_at);
        $this->assertSame(0, AuditLog::where('event', 'payment.late_superseded')->count());
        $this->assertSame(2, $receivable->payments()->count());
    }

    #[DataProvider('gateways')]
    public function test_a_partly_paid_portal_sale_paid_the_whole_total_later_is_flagged(string $gateway): void
    {
        $sale = $this->portalSale(paid: 5000);

        $this->notify($gateway, $sale, amount: self::TOTAL)->assertOk(); // not the 15000 it was charged

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status);
        $this->assertNotNull($sale->late_superseded_payment_at);
        $this->assertEquals(5000, $sale->receivable()->firstOrFail()->paid);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    #[DataProvider('gateways')]
    public function test_a_portal_payment_on_a_receivable_the_cashier_already_paid_in_full_is_flagged(string $gateway): void
    {
        $sale = $this->portalSale('paid', paid: self::TOTAL); // the cashier's receivable payment set the bill paid

        $this->notify($gateway, $sale, reference: 'gw-late')->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertNull($sale->payment_reference);
        $this->assertNotNull($sale->late_superseded_payment_at); // Bayar ganda: refund by hand
        $this->assertSame('gw-late', AuditLog::where('event', 'payment.late_superseded')->sole()->meta['reference']);
        $this->assertSame(1, $sale->receivable()->firstOrFail()->payments()->count()); // nothing booked
    }

    #[DataProvider('gateways')]
    public function test_a_gateway_payment_beyond_the_receivable_balance_books_the_balance_and_flags_the_rest(string $gateway): void
    {
        // The gateway took 20000 although the cashier had taken 5000 meanwhile: 15000 is owed, so 5000 goes back by hand.
        $sale = $this->portalSale('unpaid', paid: 5000);

        $this->notify($gateway, $sale, amount: self::TOTAL, reference: 'gw-over')->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $receivable = $sale->receivable()->firstOrFail();
        $this->assertEquals(self::TOTAL, $receivable->paid);
        $this->assertEquals(15000, $receivable->payments()->where('method', $gateway)->sole()->amount);
        $this->assertNotNull($sale->late_superseded_payment_at);
        $log = AuditLog::where('event', 'payment.late_superseded')->sole();
        $this->assertSame('gw-over', $log->meta['reference']);
        $this->assertEquals(5000, $log->meta['excess']);

        $this->notify($gateway, $sale, amount: self::TOTAL, reference: 'gw-over')->assertOk(); // redelivered: nothing more
        $this->assertSame(1, AuditLog::where('event', 'payment.late_superseded')->count());
        $this->assertSame(2, $receivable->payments()->count());
    }

    // ---- a second payment of a paid sale (#391) ------------------------------------------------------------

    #[DataProvider('gateways')]
    public function test_a_second_reference_on_a_paid_single_qris_sale_is_flagged_once_and_never_booked(string $gateway): void
    {
        $sale = $this->checkoutWithQris();
        $this->notify($gateway, $sale, reference: 'first-ref')->assertOk();
        $paid = $sale->refresh()->only(['payment_status', 'payment_method', 'payment_reference', 'grand_total', 'cash', 'paid_after_close_at']);
        $this->assertSame('paid', $paid['payment_status']);
        $this->assertSame('first-ref', $paid['payment_reference']);
        $this->assertNull($sale->late_superseded_payment_at);

        $this->notify($gateway, $sale, reference: 'second-ref')->assertOk(); // the customer paid the same QR twice
        $this->notify($gateway, $sale, reference: 'second-ref')->assertOk();

        $sale->refresh();
        $this->assertEquals($paid, $sale->only(array_keys($paid)));
        $this->assertSame(0, $sale->tenders()->count());
        $this->assertNotNull($sale->late_superseded_payment_at); // Bayar ganda: refund by hand
        $this->assertSame('second-ref', AuditLog::where('event', 'payment.late_superseded')->sole()->meta['reference']);
    }

    #[DataProvider('gateways')]
    public function test_the_same_reference_redelivered_on_a_paid_single_qris_sale_is_ignored(string $gateway): void
    {
        $sale = $this->checkoutWithQris();

        $this->notify($gateway, $sale, reference: 'first-ref')->assertOk();
        $this->notify($gateway, $sale, reference: 'first-ref')->assertOk();

        $this->assertNull($sale->fresh()->late_superseded_payment_at);
        $this->assertSame(0, AuditLog::where('event', 'payment.late_superseded')->count());
    }

    #[DataProvider('gateways')]
    public function test_a_paid_sale_without_a_stored_reference_is_audited_once_per_reference_and_not_flagged(string $gateway): void
    {
        // A legacy sale: paid by its gateway, but no reference was ever stored, so a redelivery looks like a second payment.
        $sale = $this->sale(overrides: ['payment_status' => 'paid']);
        $sale->tenders()->delete();

        $this->notify($gateway, $sale, reference: 'ref-a')->assertOk();
        $this->notify($gateway, $sale, reference: 'ref-b')->assertOk();
        $this->notify($gateway, $sale, reference: 'ref-a')->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertNull($sale->payment_reference);
        $this->assertNull($sale->late_superseded_payment_at);
        $logs = AuditLog::where('event', 'payment.late_superseded')->orderBy('id')->get();
        $this->assertSame(['ref-a', 'ref-b'], $logs->pluck('meta.reference')->all());
        $this->assertSame([false, false], $logs->pluck('meta.flagged')->all());
    }

    #[DataProvider('gateways')]
    public function test_a_second_reference_on_a_portal_sale_the_gateway_paid_is_flagged(string $gateway): void
    {
        $sale = $this->portalSale('pending');
        $this->notify($gateway, $sale, reference: 'portal-ref')->assertOk();
        $this->assertSame('paid', $sale->fresh()->payment_status);

        $this->notify($gateway, $sale, reference: 'portal-again')->assertOk();

        $sale->refresh();
        $this->assertSame('portal-ref', $sale->payment_reference);
        $this->assertNotNull($sale->late_superseded_payment_at);
        $this->assertSame('portal-again', AuditLog::where('event', 'payment.late_superseded')->sole()->meta['reference']);
        $this->assertSame(1, $sale->receivable()->firstOrFail()->payments()->count()); // nothing booked
    }

    // ---- paid some other way after the QR expired ---------------------------------------------------------

    #[DataProvider('gateways')]
    public function test_a_sale_settled_by_cash_after_the_qr_expired_flags_the_late_payment(string $gateway): void
    {
        // Attempt 0 stays: settle() replaces the failed QR tender by a paid cash tender without moving the counter.
        $sale = $this->sale(overrides: ['is_pay_later' => true]);
        app(TransactionSettlementService::class)->settle($sale, $this->cashier, [['method' => 'cash', 'amount' => self::TOTAL, 'cash_received' => self::TOTAL]]);
        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame(0, $sale->payment_attempt);
        $this->assertSame('cash', $sale->tenders()->sole()->method);

        $this->notify($gateway, $sale, reference: 'gw-late')->assertOk(); // the customer paid the expired QR

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('cash', $sale->payment_method);
        $this->assertNull($sale->payment_reference);
        $this->assertSame(self::TOTAL, (int) $sale->grand_total);
        $this->assertSame('cash', $sale->tenders()->sole()->method);
        $this->assertNotNull($sale->late_superseded_payment_at);
        $log = AuditLog::where('event', 'payment.late_superseded')->sole();
        $this->assertSame('gw-late', $log->meta['reference']);
        $this->assertSame(0, AuditLog::where('event', 'payment.late_after_failed')->count());
    }

    #[DataProvider('gateways')]
    public function test_a_split_sale_with_a_paid_cash_part_and_a_failed_qr_part_is_flagged_when_the_qr_is_paid_later(string $gateway): void
    {
        $sale = $this->sale(overrides: ['payment_method' => 'split']);
        $sale->tenders()->update(['amount' => 12000]);
        $sale->tenders()->create(['method' => TransactionTender::METHOD_CASH, 'amount' => 8000, 'cash_received' => 8000, 'payment_status' => 'paid']);

        $this->notify($gateway, $sale, amount: 12000)->assertOk();

        $sale->refresh();
        $this->assertSame('failed', $sale->payment_status); // the paid cash part blocks a booking: refund by hand
        $this->assertNotNull($sale->late_superseded_payment_at);
        $this->assertFalse(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }

    #[DataProvider('gateways')]
    public function test_an_expired_qr_booked_after_the_shift_closed_is_flagged_paid_after_close_and_the_recap_is_unchanged(string $gateway): void
    {
        $sale = $this->sale();
        $closed = app(CashierShiftService::class)->closeShift($this->shift, $this->cashier, 0);
        $this->assertSame(0, (int) $closed->non_cash_sales_total); // a failed gateway sale is not in the snapshot

        $this->notify($gateway, $sale)->assertOk();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status);
        $this->assertNotNull($sale->paid_after_close_at);
        $this->assertSame($this->shift->id, $sale->cashier_shift_id);
        $this->assertSame(0, (int) CashierShift::findOrFail($this->shift->id)->non_cash_sales_total);
        $this->assertSame('payment.paid_after_close', AuditLog::where('event', 'payment.paid_after_close')->sole()->event);
        $this->assertTrue(AuditLog::where('event', 'payment.late_after_failed')->sole()->meta['booked']);
    }
}
