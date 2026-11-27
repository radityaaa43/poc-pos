<?php

namespace Tests\Feature\DineIn\Concerns;

use App\Models\CashierShift;
use App\Models\Category;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\DineOrderService;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

/**
 * A dine-in bill of 25000 (2 x Rujak, flagged for the kitchen, + 1 x Air) that a cashier with an open shift has accepted,
 * and the calls around it: the guest paying online, the cashier paying, a gateway notification, a shift closing.
 * Call setUpDineBill() from setUp().
 */
trait MakesDineBills
{
    private const TOTAL = 25000;

    private User $cashier;

    private Warehouse $warehouse;

    private Product $flagged;

    private Product $plain;

    private DiningTable $table;

    protected function setUpDineBill(): void
    {
        $this->cashier = User::factory()->create();
        $this->warehouse = Warehouse::create(['code' => 'PUSAT', 'name' => 'Gudang Pusat', 'type' => 'main', 'is_active' => true, 'sort_order' => 0]);
        $category = Category::create(['name' => 'Kategori', 'image' => 'categories/t.jpg', 'description' => 'x']);
        $this->table = DiningTable::create(['name' => 'Meja 7', 'is_active' => true, 'capacity' => 4, 'sort_order' => 0]);

        $this->flagged = $this->makeProduct('Rujak', 'FLAG', 10000, $category, true);
        $this->plain = $this->makeProduct('Air', 'PLAIN', 5000, $category, false);
    }

    private function makeProduct(string $title, string $code, int $price, Category $category, bool $needsPreparation): Product
    {
        $product = Product::create([
            'title' => $title, 'sku' => "SKU-{$code}", 'barcode' => "BC-{$code}", 'buy_price' => 2000, 'sell_price' => $price, 'stock' => 20,
            'image' => 'products/t.jpg', 'description' => 'x', 'tax_rate' => 0, 'category_id' => $category->id,
            'needs_preparation' => $needsPreparation,
        ]);
        $product->warehouses()->attach($this->warehouse->id, ['stock' => 20]);

        return $product;
    }

    /** A guest order for 2 x Rujak + 1 x Air, accepted by the cashier (an open shift is opened for her first). */
    private function acceptedOrder(): DineOrder
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
        app(DineOrderService::class)->accept($order);

        return $order->fresh();
    }

    private function bill(DineOrder $order): Transaction
    {
        return Transaction::findOrFail($order->transaction_id);
    }

    /** Enable one gateway ('midtrans' or 'xendit') and, unless told otherwise, fake its API. */
    private function useGateway(string $gateway, bool $fakeHttp = true): void
    {
        PaymentSetting::create($gateway === 'midtrans'
            ? ['default_gateway' => 'midtrans', 'midtrans_enabled' => true, 'midtrans_server_key' => 'sk-test', 'midtrans_client_key' => 'ck-test']
            : ['default_gateway' => 'xendit', 'xendit_enabled' => true, 'xendit_secret_key' => 'xnd-secret', 'xendit_public_key' => 'xnd-public', 'xendit_callback_token' => 'callback-token']);

        if ($fakeHttp) {
            Http::fake([
                'app.sandbox.midtrans.com/*' => Http::response(['token' => 'tok', 'redirect_url' => 'https://pay.test/midtrans', 'qr_string' => '000201MIDTRANS'], 201),
                'api.xendit.co/*' => Http::response(['id' => 'xnd-1', 'invoice_url' => 'https://pay.test/xendit', 'qr_string' => '000201XENDIT'], 200),
            ]);
        }
    }

    /** The guest presses Bayar sekarang (QRIS) on the status page: a new attempt, the bill goes pending. */
    private function guestPays(DineOrder $order): TestResponse
    {
        return $this->postJson(route('dine-order.pay', $order->access_token));
    }

    /**
     * The cashier pays in the POS ('cash' or a gateway method such as 'qris').
     *
     * @throws ValidationException
     */
    private function cashierPays(DineOrder $order, string $method = 'cash', ?User $cashier = null): Transaction
    {
        $tender = $method === 'cash'
            ? ['method' => 'cash', 'amount' => self::TOTAL, 'cash_received' => self::TOTAL]
            : ['method' => $method, 'amount' => self::TOTAL];

        return app(DineOrderService::class)->pay($order, $cashier ?? $this->cashier, [$tender]);
    }

    /**
     * A signed notification from the gateway. $state: paid, failed or expired. The amount defaults to the bill's total.
     */
    private function notify(string $gateway, string $orderId, string $state, ?int $amount = null, ?string $reference = null): TestResponse
    {
        $amount ??= self::TOTAL;

        if ($gateway === 'midtrans') {
            $gross = $amount.'.00';

            return $this->postJson(route('webhooks.midtrans'), [
                'order_id' => $orderId, 'status_code' => '200', 'gross_amount' => $gross,
                'transaction_status' => ['paid' => 'settlement', 'failed' => 'deny', 'expired' => 'expire'][$state],
                'transaction_id' => $reference ?? 'mt-'.$orderId,
                'signature_key' => hash('sha512', $orderId.'200'.$gross.'sk-test'),
            ]);
        }

        return $this->withHeader('X-CALLBACK-TOKEN', 'callback-token')->postJson(route('webhooks.xendit'), [
            'external_id' => $orderId, 'status' => ['paid' => 'PAID', 'failed' => 'FAILED', 'expired' => 'EXPIRED'][$state],
            'id' => $reference ?? 'xnd-'.$orderId, 'paid_amount' => $amount,
        ]);
    }

    /**
     * The shift the bill belongs to is closed and its stored recap taken. An unpaid bill or unhanded food holds a plain
     * close (REQ-003 AC-12), so this closes by force, with the note a forced close then needs (AC-13).
     */
    private function closeShiftOf(Transaction $bill): CashierShift
    {
        return app(CashierShiftService::class)->closeShift(CashierShift::findOrFail($bill->cashier_shift_id), $this->cashier, 100000, 'Tutup paksa', forceClose: true);
    }

    /** @return array<string, array<int, string>> the gateways the suites run against (a data provider) */
    public static function gateways(): array
    {
        return ['midtrans' => ['midtrans'], 'xendit' => ['xendit']];
    }
}
