<?php

namespace App\Testing\E2e\Scenarios;

use App\Http\Controllers\Api\V1\SalesReturnController;
use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\DineOrder;
use App\Models\KitchenCancellation;
use App\Models\KitchenItem;
use App\Models\KitchenStation;
use App\Models\Product;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CheckoutService;
use App\Services\DineOrderService;
use App\Services\KitchenStationService;
use App\Support\Checkout\CheckoutContext;
use App\Testing\E2e\Scenarios;
use Database\Seeders\E2eSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

            // The station name each kitchen item of the sale --arg=invoice=<invoice> was given at intake (a deleted station's name still shows).
            // Separate from kitchen.items, whose exact output other specs assert.
            'kitchen.item-stations' => fn (array $options): array => [
                'station_names' => KitchenItem::with('kitchenStation')
                    ->where('transaction_id', Transaction::where('invoice', $options['invoice'])->firstOrFail()->id)
                    ->orderBy('id')->get()
                    ->map(fn ($item) => $item->kitchenStation?->name)->all(),
            ],

            // The status of each kitchen item of the sale --arg=invoice=<invoice>, keyed by the station name it was given (a deleted station's name still shows).
            'kitchen.status-by-station' => fn (array $options): array => [
                'status_by_station' => KitchenItem::with('kitchenStation')
                    ->where('transaction_id', Transaction::where('invoice', $options['invoice'])->firstOrFail()->id)
                    ->orderBy('id')->get()
                    ->mapWithKeys(fn ($item) => [$item->kitchenStation?->name => $item->status])->all(),
            ],

            // Soft-deletes the kitchen station --arg=name=<name> through the service the settings page uses (its products move to Dapur).
            'kitchen.station-delete' => function (array $options): array {
                app(KitchenStationService::class)->delete(KitchenStation::where('name', $options['name'])->firstOrFail());

                return ['name' => $options['name']];
            },

            // Creates the kitchen station --arg=name=<name> and maps the products --arg=products=<id>,<id> to it.
            'kitchen.station' => function (array $options): array {
                $station = KitchenStation::create(['name' => $options['name']]);
                Product::whereIn('id', array_filter(explode(',', $options['products'] ?? '')))->update(['kitchen_station_id' => $station->id]);

                return ['station_id' => $station->id, 'name' => $station->name];
            },

            // A menu with a 5 minute target and finished kitchen items on --arg=date (default 2025-04-14) in the E2E outlet, one sale each:
            // a 4 min make, a qty-3 line of 7 min, a 3 min make, plus a cancelled and a not collected item that
            // the speed report must leave out. Report: 3 orders, 5 portions, make median 4.0 / avg 4.7, wait + make
            // median 5.0 / avg 6.0, within target 66.7%; handover avg 7.0 over 3 orders.
            // --arg=station=<name> puts the items at that kitchen station (created if missing) and adds `station` to the result.
            'kitchen.speed-items' => function (array $options): array {
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $cashier = User::where('email', 'cashier@e2e.test')->firstOrFail();
                $product = Product::create([
                    'category_id' => Category::firstOrCreate(['name' => 'E2E Kategori'])->id,
                    'barcode' => 'E2E-'.Str::upper(Str::random(10)),
                    'title' => 'Soto '.Str::upper(Str::random(6)),
                    'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 20, 'tax_type' => 'exclusive', 'tax_rate' => 0,
                    'needs_preparation' => true, 'prep_target_minutes' => 5,
                ]);
                $warehouse->products()->attach($product->id, ['stock' => 20]);

                // [status, qty, minutes after 10:00 of: preparing, ready, served]
                $lines = [
                    ['served', 1, 1, 5, 6],
                    ['served', 3, 62, 69, 70],
                    ['served', 1, 121, 124, 125],
                    ['cancelled', 1, null, null, null],
                    ['not_collected', 1, 181, 185, null],
                ];
                $day = $options['date'] ?? '2025-04-14'; // a step passes its own day, so reruns on one database do not add up
                $station = isset($options['station']) ? KitchenStation::firstOrCreate(['name' => $options['station']]) : null;
                $at = fn (?int $minutes) => $minutes === null ? null : Carbon::parse($day.' 10:00:00')->addMinutes($minutes);
                foreach ($lines as $n => [$status, $qty, $preparing, $ready, $served]) {
                    $transaction = Transaction::create([
                        'cashier_id' => $cashier->id, 'warehouse_id' => $warehouse->id, 'invoice' => 'KS'.Str::upper(Str::random(8)),
                        'cash' => 10000 * $qty, 'change' => 0, 'discount' => 0, 'shipping_cost' => 0, 'grand_total' => 10000 * $qty,
                        'payment_method' => 'cash', 'payment_status' => 'paid', 'tax_rate' => 0, 'tax_total' => 0,
                    ]);
                    $detail = $transaction->details()->create([
                        'product_id' => $product->id, 'conversion_factor' => 1, 'qty' => $qty, 'base_unit_price' => 10000,
                        'unit_price' => 10000, 'price' => 10000 * $qty, 'discount_total' => 0, 'cost_total' => 5000 * $qty,
                    ]);
                    KitchenItem::create([
                        'transaction_id' => $transaction->id, 'transaction_detail_id' => $detail->id, 'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id, 'qty' => $qty, 'status' => $status, 'accepted_at' => $at($n * 60),
                        'preparing_at' => $at($preparing), 'ready_at' => $at($ready), 'served_at' => $at($served),
                        'kitchen_station_id' => $station?->id,
                    ]);
                }

                return [
                    'title' => $product->title, 'date' => $day, 'target' => 5, 'count_rows' => 3, 'count_portions' => 5,
                    'make_median' => 4.0, 'make_avg' => 4.7, 'wait_make_median' => 5.0, 'wait_make_avg' => 6.0,
                    'within_target_pct' => 66.7, 'orders' => 3, 'handover_avg' => 7.0,
                ] + ($station ? ['station' => $station->name] : []);
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

            // Moves item --arg=index (default 0) of the sale --arg=invoice to --arg=status=accepted|preparing|ready|served
            // (default preparing), and sets the sold quantity of its line to --arg=qty when given (the sale line, its
            // price and the kitchen item together), so a step can start a half-made item or return part of a line.
            'kitchen.set-item' => function (array $options): array {
                $transaction = Transaction::where('invoice', $options['invoice'])->firstOrFail();
                $item = KitchenItem::where('transaction_id', $transaction->id)->orderBy('id')->skip((int) ($options['index'] ?? 0))->firstOrFail();
                $status = $options['status'] ?? KitchenItem::STATUS_PREPARING;
                $now = now();
                $item->forceFill([
                    'status' => $status,
                    'preparing_at' => in_array($status, [KitchenItem::STATUS_PREPARING, KitchenItem::STATUS_READY, KitchenItem::STATUS_SERVED], true) ? $now : null,
                    'ready_at' => in_array($status, [KitchenItem::STATUS_READY, KitchenItem::STATUS_SERVED], true) ? $now : null,
                    'served_at' => $status === KitchenItem::STATUS_SERVED ? $now : null,
                ]);
                if (isset($options['qty'])) {
                    $qty = (int) $options['qty'];
                    $detail = TransactionDetail::findOrFail($item->transaction_detail_id);
                    $detail->update(['qty' => $qty, 'price' => $detail->unit_price * $qty]);
                    $item->qty = $qty;
                }
                $item->save();

                return ['status' => $item->status, 'qty' => $item->qty];
            },

            // Opens the cashier's shift (--arg=cashier=<email>, default cashier@e2e.test), creates products
            // "<prefix> Masak <i>" (needs_preparation) x --arg=flagged and "<prefix> Kemasan <i>" x --arg=plain,
            // and sells one of each. --arg=status=paid (default) or pending (a bank transfer nobody confirmed).
            // --arg=pay_later=1 sells it on "Bayar belakangan" instead (unpaid, a guest, with the note the rule needs).
            // --arg=dine=1 instead has a guest order on a new table accepted and paid in cash, flagged lines only;
            // --arg=note=<text> is then the note on every dine-in line (it reaches the kitchen item).
            // Returns the invoice (or table_name for dine), and the flagged and plain titles.
            // --arg=station=<name> maps the LAST flagged product to that station (created if missing) before the sale
            // and adds `station` to the result; the others stay on the default station.
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

                $stationName = $options['station'] ?? null;
                if ($stationName !== null && $flagged) {
                    $station = KitchenStation::firstOrCreate(['name' => $stationName]);
                    $flagged[array_key_last($flagged)]->update(['kitchen_station_id' => $station->id]);
                    $titles['station'] = $stationName;
                }

                Scenarios::registry()['open-shift'](['cashier' => $email, 'cash' => '100000']);

                if (($options['dine'] ?? '0') === '1') {
                    return $titles + self::dineSale($cashier, $flagged, $prefix, $options['note'] ?? null);
                }

                $transaction = self::sell(
                    $cashier,
                    [...$flagged, ...$plain],
                    ($options['status'] ?? 'paid') === 'pending',
                    ($options['pay_later'] ?? '0') === '1',
                );

                return $titles + ['invoice' => $transaction->invoice, 'transaction_id' => $transaction->id];
            },

            // Saves a DRAFT return of --arg=qty (default the whole line) units of the line of kitchen item --arg=index (default 0)
            // of the sale --arg=invoice, as the Retur page does: nothing leaves the kitchen until it is completed.
            'kitchen.return-draft' => fn (array $options): array => ['sales_return_id' => self::draftReturn($options)->id],

            // Saves that return and COMPLETES it through the controller the POS uses, so the kitchen hook, the refund and the
            // restock run for real (the cashier who made the sale needs the open shift kitchen-sale left). Returns the return,
            // what it cancelled in the kitchen (0 for a served item) and the item's status afterwards.
            'kitchen.return' => function (array $options): array {
                $return = self::draftReturn($options);
                $cashier = User::findOrFail($return->cashier_id);
                $request = Request::create('/', 'POST');
                $request->setUserResolver(fn () => $cashier);

                app(SalesReturnController::class)->completeReturn($request, $return);

                $item = self::returnedItem($options);

                return [
                    'sales_return_id' => $return->id,
                    'cancelled_qty' => (int) KitchenCancellation::where('sales_return_id', $return->id)->sum('qty_cancelled'),
                    'item_status' => $item->status,
                ];
            },
        ];
    }

    /** The kitchen item --arg=index (default 0) of the sale --arg=invoice, in the order kitchen.set-item counts them. */
    private static function returnedItem(array $options): KitchenItem
    {
        $transaction = Transaction::where('invoice', $options['invoice'])->firstOrFail();

        return KitchenItem::where('transaction_id', $transaction->id)->orderBy('id')->skip((int) ($options['index'] ?? 0))->firstOrFail();
    }

    /** A draft return of the item's sale line, written the way the controller's draft step does. */
    private static function draftReturn(array $options): SalesReturn
    {
        $item = self::returnedItem($options);
        $detail = TransactionDetail::findOrFail($item->transaction_detail_id);
        $transaction = Transaction::findOrFail($item->transaction_id);
        $qty = (int) ($options['qty'] ?? $detail->qty);
        $before = (int) SalesReturnItem::where('transaction_detail_id', $detail->id)
            ->whereHas('salesReturn', fn ($query) => $query->where('status', 'completed'))->sum('qty_return');
        $amount = $detail->returnAmount($before, $qty);

        $return = SalesReturn::create([
            'code' => 'SR-E2E-'.Str::upper(Str::random(8)),
            'transaction_id' => $transaction->id,
            'customer_id' => $transaction->customer_id,
            'cashier_id' => $transaction->cashier_id,
            'status' => 'draft',
            'return_type' => 'refund_cash',
            'refund_amount' => $transaction->payment_status === 'paid' ? $amount : 0,
            'credited_amount' => 0,
            'total_return_amount' => $amount,
        ]);
        $return->items()->create([
            'transaction_detail_id' => $detail->id,
            'product_id' => $detail->product_id,
            'qty_sold' => (int) $detail->qty,
            'qty_returned_before' => $before,
            'qty_return' => $qty,
            'unit_price' => (int) round((int) $detail->price / max(1, (int) $detail->qty)),
            'subtotal' => $amount,
            'return_reason' => 'E2E',
            'restock_to_inventory' => true,
        ]);

        return $return;
    }

    /** A guest order on a new table, accepted by the cashier and paid in cash: the paid bill reaches the kitchen. */
    private static function dineSale(User $cashier, array $products, string $prefix, ?string $note): array
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
            $order->items()->create(['product_id' => $product->id, 'qty' => 1, 'price' => (int) $product->sell_price, 'note' => $note]);
        }

        $service = app(DineOrderService::class);
        $service->accept($order);
        $service->pay($order->fresh(), $cashier, [['method' => 'cash', 'amount' => (int) $order->subtotal]]);

        return ['table_name' => $table['table_name'], 'order_id' => $order->id];
    }

    /** One POS sale of one unit of each product: paid in cash, a bank transfer left pending, or on pay-later (unpaid). */
    private static function sell(User $cashier, array $products, bool $pending, bool $payLater = false): Transaction
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
            isPayLater: $payLater,
            dueDate: null,
            orderType: 'in_store',
            note: $payLater ? 'Bayar belakangan e2e' : null, // a guest on pay-later needs a note: who owes
            customerNpwp: null,
            isCashPayment: ! $pending && ! $payLater,
            cashAmount: $pending || $payLater ? 0 : $total,
            paymentGateway: null,
            useTenders: $pending,
            tenderInput: $pending ? [['method' => 'bank_transfer', 'amount' => $total, 'bank_account_id' => $bankId]] : [],
            outlet: $shift->warehouse?->outlet,
            bankAccountId: null,
        ))->transaction;
    }
}
