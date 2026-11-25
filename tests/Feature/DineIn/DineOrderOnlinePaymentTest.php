<?php

namespace Tests\Feature\DineIn;

use App\Models\CashierShift;
use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\KitchenItem;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\DineOrderService;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** REQ-005: a guest pays the open bill of an accepted order online, from the QR menu status page. */
class DineOrderOnlinePaymentTest extends TestCase
{
    use RefreshDatabase;

    private const TOTAL = 25000; // 2 x Rujak (10000, flagged for the kitchen) + 1 x Air (5000)

    private User $cashier;

    private Warehouse $warehouse;

    private Product $flagged;

    private Product $plain;

    private DiningTable $table;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cashier = User::factory()->create();
        $this->warehouse = Warehouse::create(['code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);
        $category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        $this->table = DiningTable::create(['name' => 'Meja 7', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);

        $this->flagged = $this->product('Rujak', 'FLAG', 10000, $category, true);
        $this->plain = $this->product('Air', 'PLAIN', 5000, $category, false);
    }

    private function product(string $title, string $code, int $price, Category $category, bool $needsPreparation): Product
    {
        $product = Product::create([
            'title' => $title, 'sku' => "SKU-{$code}", 'barcode' => "BC-{$code}", 'buy_price' => 2000, 'sell_price' => $price, 'stock' => 20,
            'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0, 'category_id' => $category->id,
            'needs_preparation' => $needsPreparation,
        ]);
        $product->warehouses()->attach($this->warehouse->id, ['stock' => 20]);

        return $product;
    }

    /** A guest order for 2 x Rujak + 1 x Air; accepted by the cashier (who has an open shift) unless $accept is false. */
    private function order(bool $accept = true): DineOrder
    {
        if (! CashierShift::open()->where('user_id', $this->cashier->id)->exists()) {
            app(CashierShiftService::class)->openShift($this->cashier, $this->cashier, 100000, null, $this->warehouse->id);
        }

        $order = DineOrder::create([
            'dine_table_id' => $this->table->id, 'cashier_id' => $this->cashier->id, 'status' => DineOrder::STATUS_SUBMITTED,
            'submitted_at' => now(), 'payment_option' => DineOrder::PAY_AT_COUNTER, 'subtotal' => self::TOTAL, 'item_count' => 3,
        ]);
        $order->items()->create(['product_id' => $this->flagged->id, 'qty' => 2, 'price' => 10000]);
        $order->items()->create(['product_id' => $this->plain->id, 'qty' => 1, 'price' => 5000]);

        if ($accept) {
            app(DineOrderService::class)->accept($order);
        }

        return $order->fresh();
    }

    private function enableMidtrans(): void
    {
        PaymentSetting::create(['default_gateway' => 'midtrans', 'midtrans_enabled' => true, 'midtrans_server_key' => 'sk-test', 'midtrans_client_key' => 'ck-test']);
    }

    private function enableXendit(): void
    {
        PaymentSetting::create([
            'default_gateway' => 'xendit', 'xendit_enabled' => true, 'xendit_secret_key' => 'xnd-secret', 'xendit_public_key' => 'xnd-public',
            'xendit_callback_token' => 'callback-token',
        ]);
    }

    private function fakeGateways(): void
    {
        Http::fake([
            'app.sandbox.midtrans.com/*' => Http::response(['token' => 'tok', 'redirect_url' => 'https://pay.test/midtrans', 'qr_string' => '000201MIDTRANS'], 201),
            'api.xendit.co/*' => Http::response(['id' => 'xnd-1', 'invoice_url' => 'https://pay.test/xendit', 'qr_string' => '000201XENDIT'], 200),
        ]);
    }

    /** @param array<string, mixed> $body */
    private function pay(DineOrder|string $order, array $body = []): TestResponse
    {
        $token = $order instanceof DineOrder ? $order->access_token : $order;

        return $this->postJson(route('dine-order.pay', $token), $body);
    }

    private function check(DineOrder $order): TestResponse
    {
        return $this->getJson(route('dine-order.status-check', $order->access_token));
    }

    private function bill(DineOrder $order): Transaction
    {
        return Transaction::findOrFail($order->transaction_id);
    }

    private function midtransWebhook(string $orderId, string $status): TestResponse
    {
        return $this->postJson(route('webhooks.midtrans'), [
            'order_id' => $orderId, 'status_code' => '200', 'gross_amount' => self::TOTAL.'.00', 'transaction_status' => $status,
            'transaction_id' => 'mt-'.$orderId, 'signature_key' => hash('sha512', $orderId.'200'.self::TOTAL.'.00'.'sk-test'),
        ]);
    }

    private function assertRefused(TestResponse $response, string $code): void
    {
        $response->assertStatus(422)->assertJsonPath('errors.payment.0', $code);
    }

    // ---- state shown on the status page -------------------------------------------------------------

    public function test_status_page_carries_payment_state(): void
    {
        $this->enableMidtrans();
        $submitted = $this->order(accept: false);

        $this->get(route('dine-order.status', $submitted->access_token))
            ->assertInertia(fn (Assert $page) => $page->where('payment', null));

        app(DineOrderService::class)->accept($submitted);

        $this->get(route('dine-order.status', $submitted->access_token))
            ->assertInertia(fn (Assert $page) => $page->has('payment', fn (Assert $payment) => $payment
                ->where('state', 'can_pay')->where('amount', self::TOTAL)->where('attempt', 0)->where('payment_url', null)->where('qr', null)));
    }

    public function test_check_response_carries_payment_without_the_qr_image(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();

        $this->check($order)->assertOk()->assertJsonPath('payment.state', 'can_pay')->assertJsonStructure(['order' => ['id', 'status', 'is_paid']]);

        $this->pay($order)->assertOk();

        $this->check($order)->assertOk()
            ->assertJsonPath('payment.state', 'pending')
            ->assertJsonPath('payment.payment_url', 'https://pay.test/midtrans')
            ->assertJsonPath('payment.qr', null);
        $this->get(route('dine-order.status', $order->access_token))
            ->assertInertia(fn (Assert $page) => $page->where('payment.state', 'pending')
                ->where('payment.qr', fn ($qr) => str_starts_with($qr, 'data:image/svg+xml;base64,')));
    }

    public function test_payment_payload_has_no_internal_fields(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();
        $invoice = $this->bill($order)->invoice;

        $payload = $this->pay($order)->assertOk()->json('payment');

        $this->assertSame(['state', 'amount', 'attempt', 'payment_url', 'qr'], array_keys($payload));
        $this->assertStringNotContainsString($invoice, json_encode($payload));
        $this->assertStringNotContainsString('-r1', json_encode($payload));
    }

    // ---- starting a charge --------------------------------------------------------------------------

    public function test_amount_comes_from_the_server(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();
        $bill = $this->bill($order);

        $this->pay($order, ['method' => 'qris', 'amount' => 1, 'grand_total' => 1])
            ->assertOk()
            ->assertJsonPath('payment.state', 'pending')
            ->assertJsonPath('payment.amount', self::TOTAL)
            ->assertJsonPath('payment.attempt', 1);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'midtrans')
            && $request['transaction_details']['gross_amount'] === self::TOTAL
            && $request['transaction_details']['order_id'] === "{$bill->invoice}-r1"
            && $request['enabled_payments'] === ['qris', 'gopay', 'shopeepay']);
        $bill->refresh();
        $this->assertSame('pending', $bill->payment_status);
        $this->assertSame('qris', $bill->payment_method);
        $this->assertSame(1, $bill->payment_attempt);
        $this->assertSame("{$bill->invoice}-r1", $bill->payment_reference);
        $this->assertSame('000201MIDTRANS', $bill->qr_string);
        $this->assertSame(0, $bill->tenders()->count());
    }

    public function test_xendit_charge_uses_the_server_amount_and_attempt_order_id(): void
    {
        $this->enableXendit();
        $this->fakeGateways();
        $order = $this->order();
        $bill = $this->bill($order);

        $this->pay($order, ['amount' => 1])->assertOk()->assertJsonPath('payment.amount', self::TOTAL)
            ->assertJsonPath('payment.payment_url', 'https://pay.test/xendit');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'xendit')
            && $request['amount'] === self::TOTAL && $request['external_id'] === "{$bill->invoice}-r1");
    }

    public function test_return_url_and_expiry_are_sent_to_the_gateway(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();
        $this->pay($order)->assertOk();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'midtrans')
            && $request['callbacks']['finish'] === route('dine-order.status', $order->access_token)
            && $request['expiry'] === ['unit' => 'minutes', 'duration' => 15]);

        PaymentSetting::query()->delete();
        $this->enableXendit();
        $second = $this->order();
        $this->pay($second)->assertOk();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'xendit')
            && $request['success_redirect_url'] === route('dine-order.status', $second->access_token)
            && $request['invoice_duration'] === 900);
    }

    public function test_other_callers_keep_the_print_route_and_no_expiry(): void
    {
        $this->fakeGateways();
        $bill = $this->bill($this->order());

        $this->enableMidtrans();
        app(PaymentGatewayManager::class)->createQrisPayment($bill, PaymentSetting::first(), self::TOTAL);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'midtrans')
            && $request['callbacks']['finish'] === route('transactions.print', $bill->invoice) && ! isset($request['expiry']));

        PaymentSetting::query()->delete();
        $this->enableXendit();
        app(PaymentGatewayManager::class)->createQrisPayment($bill, PaymentSetting::first(), self::TOTAL);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'xendit')
            && $request['success_redirect_url'] === route('transactions.print', $bill->invoice) && ! isset($request['invoice_duration']));
    }

    public function test_gateway_method_creates_the_hosted_charge(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();

        $this->pay($order, ['method' => 'gateway'])->assertOk()
            ->assertJsonPath('payment.state', 'pending')
            ->assertJsonPath('payment.payment_url', 'https://pay.test/midtrans');

        Http::assertSent(fn (Request $request) => ! isset($request['enabled_payments']) && $request['transaction_details']['gross_amount'] === self::TOTAL);
        $this->assertSame('midtrans', $this->bill($order)->payment_method);
    }

    public function test_an_unknown_method_is_a_validation_error(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();

        $this->pay($order, ['method' => 'cash'])->assertStatus(422)->assertJsonValidationErrors('method');
        Http::assertNothingSent();
    }

    public function test_second_press_supersedes_the_first(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();
        $bill = $this->bill($order);

        $this->pay($order)->assertOk()->assertJsonPath('payment.attempt', 1);
        $this->pay($order)->assertOk()->assertJsonPath('payment.attempt', 2);
        $this->assertSame("{$bill->invoice}-r2", $bill->fresh()->payment_reference);

        // The first charge is dead: its late paid callback is only marked, never booked.
        $this->midtransWebhook("{$bill->invoice}-r1", 'settlement')->assertOk();
        $bill->refresh();
        $this->assertSame('pending', $bill->payment_status);
        $this->assertNotNull($bill->late_superseded_payment_at);

        $this->midtransWebhook("{$bill->invoice}-r2", 'settlement')->assertOk();
        $this->assertSame('paid', $bill->fresh()->payment_status);
    }

    public function test_paid_webhook_settles_once_and_the_bill_leaves_open_bills(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();
        $bill = $this->bill($order);
        $this->assertTrue(Transaction::openBills()->whereKey($bill->id)->exists());
        $this->pay($order)->assertOk();

        $this->midtransWebhook("{$bill->invoice}-r1", 'settlement')->assertOk();
        $this->midtransWebhook("{$bill->invoice}-r1", 'settlement')->assertOk(); // redelivered

        $this->assertSame('paid', $bill->fresh()->payment_status);
        $this->assertSame(1, KitchenItem::where('transaction_id', $bill->id)->count()); // the flagged line only, once
        $this->assertFalse(Transaction::openBills()->whereKey($bill->id)->exists());
        $this->check($order)->assertJsonPath('payment.state', 'paid')->assertJsonPath('payment.payment_url', null);
    }

    public function test_guest_charge_keeps_the_bills_shift(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();
        $bill = $this->bill($order);
        $shiftId = $bill->cashier_shift_id;
        $this->assertNotNull($shiftId);

        $this->pay($order)->assertOk();
        $this->midtransWebhook("{$bill->invoice}-r1", 'settlement')->assertOk();

        $bill->refresh();
        $this->assertSame('paid', $bill->payment_status);
        $this->assertSame($shiftId, $bill->cashier_shift_id);
        $this->assertSame($this->cashier->id, $bill->cashier_id);
    }

    public function test_failed_and_expired_attempts_can_be_retried_for_the_same_amount(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();
        $bill = $this->bill($order);

        $this->pay($order)->assertOk();
        $this->midtransWebhook("{$bill->invoice}-r1", 'deny')->assertOk();
        $this->check($order)->assertJsonPath('payment.state', 'failed')->assertJsonPath('payment.amount', self::TOTAL);
        $this->assertNull($bill->fresh()->payment_failure_reason);

        $this->pay($order)->assertOk()->assertJsonPath('payment.attempt', 2);
        $this->midtransWebhook("{$bill->invoice}-r2", 'expire')->assertOk();
        $this->check($order)->assertJsonPath('payment.state', 'expired');
        $this->assertSame('expired', $bill->fresh()->payment_failure_reason);

        $this->pay($order)->assertOk()->assertJsonPath('payment.attempt', 3)->assertJsonPath('payment.amount', self::TOTAL);
        $this->assertNull($bill->fresh()->payment_failure_reason);
        Http::assertSent(fn (Request $request) => ($request['transaction_details']['order_id'] ?? null) === "{$bill->invoice}-r3"
            && $request['transaction_details']['gross_amount'] === self::TOTAL);

        // A late paid callback of the first attempt is marked, not booked.
        $this->midtransWebhook("{$bill->invoice}-r1", 'settlement')->assertOk();
        $this->assertSame('pending', $bill->fresh()->payment_status);
        $this->assertNotNull($bill->fresh()->late_superseded_payment_at);
    }

    // ---- refusals -----------------------------------------------------------------------------------

    public function test_refuses_before_accept(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order(accept: false);

        $this->assertRefused($this->pay($order), 'not_payable');
        $this->check($order)->assertJsonPath('payment', null);
        Http::assertNothingSent();
    }

    public function test_refuses_rejected_order(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order(accept: false);
        app(DineOrderService::class)->reject($order, 'habis');

        $this->assertRefused($this->pay($order), 'not_payable');
        Http::assertNothingSent();
    }

    public function test_unknown_token_is_not_found(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();

        $this->pay((string) Str::uuid())->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_paid_bill_is_refused_as_already_paid(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();
        app(DineOrderService::class)->pay($order, $this->cashier, [['method' => 'cash', 'amount' => self::TOTAL, 'cash_received' => self::TOTAL]]);

        $this->assertRefused($this->pay($order), 'already_paid');
        $this->check($order)->assertJsonPath('payment.state', 'paid');
        Http::assertNothingSent();
        $this->assertSame(0, $this->bill($order)->payment_attempt);
    }

    public function test_no_gateway_shows_counter_and_refuses_pay(): void
    {
        $this->fakeGateways();
        $order = $this->order();

        $this->check($order)->assertJsonPath('payment.state', 'counter');
        $this->assertRefused($this->pay($order), 'no_gateway');

        // Only bank transfer is enabled: that is not a gateway either.
        PaymentSetting::create(['default_gateway' => 'cash', 'bank_transfer_enabled' => true]);
        $this->check($order)->assertJsonPath('payment.state', 'counter');
        $this->assertRefused($this->pay($order), 'no_gateway');
        Http::assertNothingSent();
        $this->assertSame('unpaid', $this->bill($order)->payment_status);
    }

    public function test_pay_later_bill_shows_counter_and_start_refuses_it(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();
        $this->bill($order)->update(['is_pay_later' => true]);

        $this->check($order)->assertJsonPath('payment.state', 'counter');
        $this->assertRefused($this->pay($order), 'not_payable');
        Http::assertNothingSent();
    }

    public function test_fully_returned_bill_cannot_be_paid_online(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();
        $bill = $this->bill($order);
        SalesReturn::create([
            'code' => 'SR-'.Str::upper(Str::random(6)), 'transaction_id' => $bill->id, 'cashier_id' => $this->cashier->id, 'status' => 'completed',
            'return_type' => 'refund_cash', 'refund_amount' => self::TOTAL, 'credited_amount' => 0, 'total_return_amount' => self::TOTAL,
            'completed_at' => now(),
        ]);

        $this->check($order)->assertJsonPath('payment', null);
        $this->assertRefused($this->pay($order), 'not_payable');
        Http::assertNothingSent();
    }

    public function test_partly_returned_bill_is_charged_for_what_is_left(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();
        SalesReturn::create([
            'code' => 'SR-'.Str::upper(Str::random(6)), 'transaction_id' => $order->transaction_id, 'cashier_id' => $this->cashier->id, 'status' => 'completed',
            'return_type' => 'refund_cash', 'refund_amount' => 5000, 'credited_amount' => 0, 'total_return_amount' => 5000, 'completed_at' => now(),
        ]);

        $this->pay($order)->assertOk()->assertJsonPath('payment.amount', 20000);
        Http::assertSent(fn (Request $request) => $request['transaction_details']['gross_amount'] === 20000);
    }

    public function test_cashier_started_pending_blocks_guest_charge(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();
        $bill = $this->bill($order);
        // The cashier's own QRIS: pending, with a tender row (a guest charge never has one), whatever its attempt number.
        TransactionTender::create(['transaction_id' => $bill->id, 'method' => 'qris', 'amount' => self::TOTAL, 'payment_status' => 'pending']);
        $bill->update(['payment_method' => 'qris', 'payment_status' => 'pending', 'payment_attempt' => 1]);

        $this->check($order)->assertJsonPath('payment.state', 'counter');
        $this->assertRefused($this->pay($order), 'not_payable');
        Http::assertNothingSent();
        $this->assertSame(1, $bill->fresh()->payment_attempt);
        $this->assertSame(1, $bill->tenders()->count());
    }

    public function test_a_split_payment_with_money_taken_is_not_charged_again_online(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $order = $this->order();
        $bill = $this->bill($order);
        TransactionTender::create(['transaction_id' => $bill->id, 'method' => 'cash', 'amount' => 10000, 'payment_status' => 'paid']);
        $bill->update(['payment_status' => 'failed']);

        $this->assertRefused($this->pay($order), 'not_payable');
        Http::assertNothingSent();
        $this->assertSame(1, $bill->tenders()->count());
    }

    public function test_limiter_is_per_token_and_ip(): void
    {
        $this->enableMidtrans();
        $this->fakeGateways();
        $first = $this->order(accept: false);
        $second = DineOrder::create([
            'dine_table_id' => $this->table->id, 'status' => DineOrder::STATUS_SUBMITTED, 'submitted_at' => now(),
            'payment_option' => DineOrder::PAY_AT_COUNTER, 'subtotal' => 10000, 'item_count' => 1,
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->assertRefused($this->pay($first), 'not_payable');
        }
        $this->pay($first)->assertStatus(429);
        // Another token, and the same token from another IP, are not affected.
        $this->assertRefused($this->pay($second), 'not_payable');
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9']);
        $this->assertRefused($this->pay($first), 'not_payable');
        Http::assertNothingSent();
    }

    // ---- the gateway call ---------------------------------------------------------------------------

    public function test_gateway_error_leaves_the_cashier_able_to_pay(): void
    {
        $this->enableMidtrans();
        Http::fake(['*' => Http::response(['status_message' => 'down'], 500)]);
        $order = $this->order();
        $bill = $this->bill($order);

        $this->assertRefused($this->pay($order), 'gateway_error');

        $bill->refresh();
        $this->assertSame('failed', $bill->payment_status);
        $this->assertSame('gateway_error', $bill->payment_failure_reason);
        $this->assertSame(1, $bill->payment_attempt);
        $this->check($order)->assertJsonPath('payment.state', 'gateway_error');
        $this->assertTrue(Transaction::openBills()->whereKey($bill->id)->exists());

        app(DineOrderService::class)->pay($order, $this->cashier, [['method' => 'cash', 'amount' => self::TOTAL, 'cash_received' => self::TOTAL]]);
        $this->assertSame('paid', $bill->fresh()->payment_status);
    }

    public function test_any_failure_of_the_gateway_call_ends_as_a_gateway_error_and_can_be_retried(): void
    {
        $this->enableMidtrans();
        Http::fake(fn () => throw new \RuntimeException('timeout'));
        $order = $this->order();
        $bill = $this->bill($order);

        $this->assertRefused($this->pay($order), 'gateway_error');
        $this->assertRefused($this->pay($order), 'gateway_error'); // allowed again from failed: a new attempt

        $bill->refresh();
        $this->assertSame('failed', $bill->payment_status);
        $this->assertSame('gateway_error', $bill->payment_failure_reason);
        $this->assertSame(2, $bill->payment_attempt);
    }

    public function test_a_charge_with_no_page_and_no_qr_is_a_gateway_error_not_a_pending_bill(): void
    {
        $this->enableMidtrans();
        Http::fake(['*' => Http::response(['token' => 'tok'], 201)]);
        $order = $this->order();
        $bill = $this->bill($order);

        $this->assertRefused($this->pay($order), 'gateway_error');

        $bill->refresh();
        $this->assertSame('failed', $bill->payment_status);
        $this->assertSame('gateway_error', $bill->payment_failure_reason);
    }

    public function test_gateway_is_called_with_no_transaction_open(): void
    {
        $this->enableMidtrans();
        $order = $this->order();
        $baseline = DB::transactionLevel(); // RefreshDatabase wraps every test in one transaction
        $levelInCall = null;
        Http::fake(function () use (&$levelInCall) {
            $levelInCall = DB::transactionLevel();

            return Http::response(['redirect_url' => 'https://pay.test/midtrans', 'qr_string' => '000201'], 201);
        });

        $this->pay($order)->assertOk();

        $this->assertSame($baseline, $levelInCall);
    }

    public function test_a_superseded_result_is_dropped(): void
    {
        $this->enableMidtrans();
        $order = $this->order();
        $bill = $this->bill($order);
        // While the first charge is out, a second tap claims attempt 2.
        Http::fake(function () use ($bill) {
            $bill->newQuery()->whereKey($bill->id)->update(['payment_attempt' => 2]);

            return Http::response(['redirect_url' => 'https://pay.test/old', 'qr_string' => '000201OLD'], 201);
        });

        $this->pay($order)->assertOk()->assertJsonPath('payment.attempt', 2)->assertJsonPath('payment.payment_url', null);

        $bill->refresh();
        $this->assertNull($bill->payment_reference);
        $this->assertNull($bill->qr_string);
        $this->assertSame('pending', $bill->payment_status);
    }

    public function test_a_superseded_failure_does_not_fail_the_newer_attempt(): void
    {
        $this->enableMidtrans();
        $order = $this->order();
        $bill = $this->bill($order);
        Http::fake(function () use ($bill) {
            $bill->newQuery()->whereKey($bill->id)->update(['payment_attempt' => 2]);

            return Http::response(['status_message' => 'down'], 500);
        });

        $this->assertRefused($this->pay($order), 'gateway_error');

        $bill->refresh();
        $this->assertSame('pending', $bill->payment_status);
        $this->assertSame(2, $bill->payment_attempt);
        $this->assertNull($bill->payment_failure_reason);
    }

    // ---- schema -------------------------------------------------------------------------------------

    public function test_failure_reason_migration_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_12_04_200001_add_payment_failure_reason_to_transactions_table.php');
        $this->assertTrue(Schema::hasColumn('transactions', 'payment_failure_reason'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('transactions', 'payment_failure_reason'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('transactions', 'payment_failure_reason'));
    }
}
