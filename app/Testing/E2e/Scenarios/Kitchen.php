<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\KitchenItem;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CheckoutService;
use App\Services\DineOrderService;
use App\Support\Checkout\CheckoutContext;
use App\Testing\E2e\Scenarios;

/**
 * Arrange scenarios for the kitchen: `kitchen.*` (flag a product, read the kitchen items back, change a payment
 * status) for the intake step, and `kitchen-sale` for the display. Items reach the kitchen through the real
 * intake: checkout (paid) or the dine-in bill payment, never by writing kitchen_items directly.
 */
class Kitchen
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // Flags --arg=product_id=<id> as needing preparation, like the product form does.
            'kitchen.flag' => function (array $options): array {
                Product::findOrFail($options['product_id'])->update(['needs_preparation' => true]);

                return ['product_id' => (int) $options['product_id']];
            },

            // The kitchen items of the sale --arg=invoice=<invoice>: how many, and each one's product and status.
            'kitchen.items' => function (array $options): array {
                $transaction = Transaction::where('invoice', $options['invoice'])->firstOrFail();
                $items = KitchenItem::where('transaction_id', $transaction->id)->orderBy('id')->get();

                return [
                    'count' => $items->count(),
                    'product_ids' => $items->pluck('product_id')->all(),
                    'statuses' => $items->pluck('status')->unique()->values()->all(),
                ];
            },

            // Sets the sale --arg=invoice=<invoice> to --arg=status=failed|expired|pending (default failed), as the
            // gateway's webhook or the expiry job does; `paid` flips it to paid again (a retried webhook).
            'kitchen.set-status' => function (array $options): array {
                $transaction = Transaction::where('invoice', $options['invoice'])->firstOrFail();
                $transaction->update(['payment_status' => $options['status'] ?? 'failed']);

                return ['invoice' => $transaction->invoice, 'payment_status' => $transaction->payment_status];
            },

            // A paid POS sale with --arg=prefix=<prefix> (one flagged line) whose kitchen intake never happened: sells it
            // for real, then removes its kitchen items, which is what a failed intake leaves behind. Returns what kitchen-sale does.
            'kitchen.paid-without-intake' => function (array $options): array {
                $sale = Scenarios::registry()['kitchen-sale'](['prefix' => $options['prefix'] ?? 'Dapur']);
                KitchenItem::where('transaction_id', $sale['transaction_id'])->delete();

                return $sale;
            },

            // Opens the cashier's shift (--arg=cashier=<email>, default cashier@e2e.test), creates products
            // "<prefix> Masak <i>" (needs_preparation) x --arg=flagged and "<prefix> Kemasan <i>" x --arg=plain,
            // and sells one of each. --arg=status=paid (default) or pending (a bank transfer nobody confirmed).
            // --arg=dine=1 instead has a guest order on a new table accepted and paid in cash, flagged lines only.
            // Returns the invoice (or table_name for dine), and the flagged and plain titles.
            'kitchen-sale' => function (array $options): array {
                $prefix = $options['prefix'] ?? 'Dapur';
                $email = $options['cashier'] ?? 'cashier@e2e.test';
                $cashier = User::where('email', $email)->firstOrFail();
                $make = fn (string $kind, int $count, bool $flagged) => array_map(function (int $i) use ($prefix, $kind, $flagged) {
                    $product = Product::findOrFail(Scenarios::registry()['product'](['title' => "$prefix $kind $i"])['product_id']);
                    $product->update(['needs_preparation' => $flagged]);

                    return $product;
                }, $count > 0 ? range(1, $count) : []);
                $flagged = $make('Masak', (int) ($options['flagged'] ?? 1), true);
                $plain = $make('Kemasan', (int) ($options['plain'] ?? 0), false);
                $titles = ['flagged' => array_column($flagged, 'title'), 'plain' => array_column($plain, 'title')];

                Scenarios::registry()['open-shift'](['cashier' => $email, 'cash' => 100000]);

                if (($options['dine'] ?? '0') === '1') {
                    return $titles + self::dineSale($cashier, $flagged, $prefix);
                }

                $transaction = self::sell($cashier, [...$flagged, ...$plain], ($options['status'] ?? 'paid') === 'pending');

                return $titles + ['invoice' => $transaction->invoice, 'transaction_id' => $transaction->id];
            },
        ];
    }

    /** A guest order on a new table, accepted by the cashier and paid in cash: the paid bill reaches the kitchen. */
    private static function dineSale(User $cashier, array $products, string $prefix): array
    {
        $table = Scenarios::registry()['dine.table'](['area' => "Area $prefix", 'name' => "Meja $prefix"]);
        $order = DineOrder::create([
            'dine_table_id' => $table['table_id'],
            'cashier_id' => $cashier->id,
            'status' => DineOrder::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'payment_option' => DineOrder::PAY_AT_COUNTER,
            'subtotal' => (int) collect($products)->sum('sell_price'),
            'item_count' => count($products),
        ]);
        foreach ($products as $product) {
            $order->items()->create(['product_id' => $product->id, 'qty' => 1, 'price' => (int) $product->sell_price]);
        }

        $service = app(DineOrderService::class);
        $service->accept($order);
        $service->pay($order->fresh(), $cashier, [['method' => 'cash', 'amount' => (int) $order->subtotal]]);

        return ['table_name' => $table['table_name'], 'order_id' => $order->id];
    }

    /** One POS sale of one unit of each product: paid in cash, or a bank transfer left pending. */
    private static function sell(User $cashier, array $products, bool $pending): Transaction
    {
        $shift = CashierShift::open()->where('user_id', $cashier->id)->with('warehouse.outlet')->firstOrFail();
        Cart::where('cashier_id', $cashier->id)->delete();
        foreach ($products as $product) {
            Cart::create([
                'cashier_id' => $cashier->id,
                'warehouse_id' => $shift->warehouse_id,
                'product_id' => $product->id,
                'unit_id' => $product->baseUnit()?->id ?: 1, // same fallback as TransactionController::addToCart
                'conversion_factor' => 1,
                'qty' => 1,
                'price' => $product->sell_price,
            ]);
        }

        $total = (int) collect($products)->sum('sell_price');
        $bankId = $pending ? Scenarios::registry()['pos.bank-account']([])['bank_account_id'] : null;

        return app(CheckoutService::class)->execute(new CheckoutContext(
            userId: $cashier->id,
            customer: null,
            voucher: null,
            manualDiscount: 0,
            shippingCost: 0,
            requestedRedeemPoints: 0,
            isPayLater: false,
            dueDate: null,
            orderType: 'in_store',
            note: null,
            customerNpwp: null,
            isCashPayment: ! $pending,
            cashAmount: $pending ? 0 : $total,
            paymentGateway: null,
            useTenders: $pending,
            tenderInput: $pending ? [['method' => 'bank_transfer', 'amount' => $total, 'bank_account_id' => $bankId]] : [],
            outlet: $shift->warehouse?->outlet,
            bankAccountId: null,
        ))->transaction;
    }
}
