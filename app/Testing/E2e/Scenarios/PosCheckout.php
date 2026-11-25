<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\BankAccount;
use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Customer;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CheckoutService;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Checkout\CheckoutContext;
use App\Testing\E2e\Scenarios;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Arrange scenarios for the POS checkout group (`pos.*`). They build on `open-shift` and `product`
 * from Scenarios.php: the step opens the shift first, then adds a cart or a sale.
 */
class PosCheckout
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // `open-shift` plus an empty cart: carts belong to the cashier, not to the shift, so a step
            // would otherwise inherit the items an earlier step left behind. Same options as `open-shift`.
            'pos.open-shift' => function (array $options): array {
                $cashier = User::where('email', $options['cashier'] ?? 'cashier@e2e.test')->firstOrFail();
                Cart::where('cashier_id', $cashier->id)->delete();

                return Scenarios::registry()['open-shift']($options);
            },

            // A customer: --arg=name=<text>, --arg=phone=<digits>. Names and phones are unique per call.
            'pos.customer' => function (array $options): array {
                $customer = Customer::create([
                    'name' => $options['name'] ?? 'Pelanggan E2E '.Str::upper(Str::random(5)),
                    'no_telp' => $options['phone'] ?? '08'.random_int(100000000, 999999999),
                    'address' => 'Jl. Uji E2E 1',
                ]);

                return ['customer_id' => $customer->id, 'name' => $customer->name];
            },

            // Turns bank transfer on and adds an active account named --arg=bank=<text> (bank transfer
            // is offered at the POS only with an active account).
            'pos.bank-account' => function (array $options): array {
                PaymentSetting::forOutletOrCreate(null)->update(['bank_transfer_enabled' => true]);

                $account = BankAccount::create([
                    'bank_name' => $options['bank'] ?? 'Bank E2E '.Str::upper(Str::random(4)),
                    'account_number' => (string) random_int(1000000000, 9999999999),
                    'account_name' => 'PT Uji E2E',
                    'is_active' => true,
                    'sort_order' => 0,
                ]);

                return ['bank_account_id' => $account->id, 'bank' => $account->bank_name, 'account_number' => $account->account_number];
            },

            // Deactivates every bank account, as the owner would: bank transfer then disappears from the POS.
            'pos.no-bank-account' => function (array $options): array {
                return ['deactivated' => BankAccount::query()->update(['is_active' => false])];
            },

            // A product sold in pcs (base, 10.000) and box (--arg=factor=<n> pcs, default 12, 108.000)
            // with --arg=stock=<pcs>. Stock is kept in the base unit.
            'pos.unit-product' => function (array $options): array {
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $category = Category::where('name', 'E2E Kategori')->firstOrFail();
                $pcs = Unit::where('code', 'PCS')->firstOrFail();
                $box = Unit::where('code', 'BOX')->firstOrFail();
                $factor = (int) ($options['factor'] ?? 12);
                $stock = (int) ($options['stock'] ?? 50);

                $product = DB::transaction(function () use ($options, $warehouse, $category, $pcs, $box, $factor, $stock) {
                    $product = Product::create([
                        'category_id' => $category->id,
                        'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                        'title' => $options['title'] ?? 'Produk Satuan '.Str::upper(Str::random(5)),
                        'buy_price' => 5000,
                        'sell_price' => 10000,
                        'stock' => $stock,
                        'tax_type' => 'exclusive',
                        'tax_rate' => 0,
                    ]);
                    $warehouse->products()->attach($product->id, ['stock' => $stock]);
                    $product->units()->attach([
                        $pcs->id => ['is_base' => true, 'conversion_factor' => 1, 'buy_price' => 5000, 'sell_price' => 10000, 'barcode' => $product->barcode],
                        $box->id => ['is_base' => false, 'conversion_factor' => $factor, 'buy_price' => 5000 * $factor, 'sell_price' => 108000, 'barcode' => $product->barcode.'-BOX'],
                    ]);

                    return $product;
                });

                return ['product_id' => $product->id, 'title' => $product->title, 'barcode' => $product->barcode, 'box_unit_id' => $box->id];
            },

            // Puts --arg=product_id=<id> x --arg=qty=<n> (default 1) in the cashier's cart
            // (--arg=cashier=<email>, default cashier@e2e.test) at the product's sell price.
            'pos.cart' => function (array $options): array {
                $cashier = User::where('email', $options['cashier'] ?? 'cashier@e2e.test')->firstOrFail();

                return ['cart_id' => self::addToCart($cashier, Product::findOrFail($options['product_id']), (int) ($options['qty'] ?? 1))->id];
            },

            // Sets --arg=product_id=<id> to --arg=stock=<n> in the E2E warehouse and on the product (both,
            // as stock moves do): the shelf was emptied by someone else while a cart was open.
            'pos.set-stock' => function (array $options): array {
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $product = Product::findOrFail($options['product_id']);
                $stock = (int) $options['stock'];

                DB::transaction(function () use ($warehouse, $product, $stock) {
                    $warehouse->products()->updateExistingPivot($product->id, ['stock' => $stock]);
                    $product->update(['stock' => $stock]);
                });

                return ['product_id' => $product->id, 'stock' => $stock];
            },

            // A paid cash sale of --arg=product_id=<id> x --arg=qty=<n> by the cashier, who must have an open shift.
            // Optional --arg=customer_id=<id>. Returns the invoice.
            'pos.sale' => function (array $options): array {
                $transaction = self::sell($options, qris: false);

                return ['invoice' => $transaction->invoice, 'transaction_id' => $transaction->id];
            },

            // The same sale paid by QRIS, as the API and mobile POS do it (the web POS offers Midtrans and Xendit
            // links, not QRIS): the transaction waits for the gateway with a QR string from the faked gateway.
            'pos.qris-sale' => function (array $options): array {
                $transaction = self::sell($options, qris: true);

                return ['invoice' => $transaction->invoice, 'transaction_id' => $transaction->id];
            },
        ];
    }

    private static function sell(array $options, bool $qris): Transaction
    {
        $cashier = User::where('email', $options['cashier'] ?? 'cashier@e2e.test')->firstOrFail();
        $product = Product::findOrFail($options['product_id']);
        $qty = (int) ($options['qty'] ?? 1);
        self::addToCart($cashier, $product, $qty);

        $shift = CashierShift::open()->where('user_id', $cashier->id)->with('warehouse.outlet')->firstOrFail();
        $outlet = $shift->warehouse?->outlet;
        $transaction = app(CheckoutService::class)->execute(new CheckoutContext(
            userId: $cashier->id,
            customer: isset($options['customer_id']) ? Customer::find($options['customer_id']) : null,
            voucher: null,
            manualDiscount: 0,
            shippingCost: 0,
            requestedRedeemPoints: 0,
            isPayLater: false,
            dueDate: null,
            orderType: 'in_store',
            note: null,
            customerNpwp: null,
            isCashPayment: ! $qris,
            cashAmount: $qris ? 0 : (int) $product->sell_price * $qty,
            paymentGateway: $qris ? 'qris' : null,
            useTenders: false,
            tenderInput: [],
            outlet: $outlet,
            bankAccountId: null,
        ))->transaction;

        if ($qris) {
            $payment = app(PaymentGatewayManager::class)->createQrisPayment($transaction, PaymentSetting::forOutlet($outlet));
            $transaction->update([
                'payment_reference' => $payment['reference'] ?? null,
                'payment_url' => $payment['payment_url'] ?? null,
                'qr_string' => $payment['qr_string'] ?? null,
            ]);
        }

        return $transaction;
    }

    private static function addToCart(User $cashier, Product $product, int $qty): Cart
    {
        $shift = CashierShift::open()->where('user_id', $cashier->id)->firstOrFail();

        return Cart::create([
            'cashier_id' => $cashier->id,
            'warehouse_id' => $shift->warehouse_id,
            'product_id' => $product->id,
            'unit_id' => $product->baseUnit()?->id ?: 1, // same fallback as TransactionController::addToCart
            'conversion_factor' => 1,
            'qty' => $qty,
            'price' => $product->sell_price * $qty,
        ]);
    }
}
