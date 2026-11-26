<?php

namespace App\Testing\E2e\Scenarios;

use App\Http\Controllers\TourController;
use App\Models\AuditLog;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\DineArea;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Receivable;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CashierShiftService;
use App\Services\DineOrderService;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Arrange scenarios for the dine-in group (areas, tables, guest menu, orders queue).
 */
class DineIn
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // An area plus one table in the E2E outlet (or --arg=outlet=<code>).
            // --arg=area=<name> --arg=name=<table> --arg=active=0|1.
            'dine.table' => function (array $options): array {
                $outlet = Outlet::where('code', $options['outlet'] ?? E2eSeeder::OUTLET_CODE)->firstOrFail();
                $area = DineArea::create([
                    'name' => $options['area'] ?? 'Area '.Str::upper(Str::random(5)),
                    'outlet_id' => $outlet->id,
                    'sort_order' => 0,
                    'is_active' => true,
                ]);
                $table = DiningTable::create([
                    'dine_area_id' => $area->id,
                    'name' => $options['name'] ?? 'M'.Str::upper(Str::random(5)),
                    'capacity' => 4,
                    'is_active' => ($options['active'] ?? '1') === '1',
                ]);

                return [
                    'area_id' => $area->id, 'area_name' => $area->name,
                    'table_id' => $table->id, 'table_name' => $table->name, 'token' => $table->token,
                ];
            },

            // A submitted guest order: --arg=token=<table token> --arg=product_id=<id> --arg=qty=<n> --arg=notes=<text>
            // and --arg=option=pay_later (default pay_at_counter), which skips the outlet setting the menu button needs.
            'dine.order' => function (array $options): array {
                $table = DiningTable::where('token', $options['token'])->firstOrFail();
                $product = Product::findOrFail($options['product_id']);
                $qty = (int) ($options['qty'] ?? 1);

                $order = DineOrder::create([
                    'dine_table_id' => $table->id,
                    'status' => DineOrder::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'payment_option' => ($options['option'] ?? DineOrder::PAY_AT_COUNTER) === DineOrder::PAY_LATER
                        ? DineOrder::PAY_LATER : DineOrder::PAY_AT_COUNTER,
                    'notes' => $options['notes'] ?? null,
                    'subtotal' => (int) $product->sell_price * $qty,
                    'item_count' => $qty,
                ]);
                $order->items()->create([
                    'product_id' => $product->id, 'qty' => $qty, 'price' => (int) $product->sell_price,
                ]);

                return ['order_id' => $order->id, 'access_token' => $order->access_token, 'subtotal' => $order->subtotal];
            },

            // A guest bill to pay online, in its own outlet (so its payment settings never touch other groups' steps):
            // a warehouse, a table, a cashier with an open shift there, one product (10000, stock 20) and a submitted
            // order of --arg=qty (default 2). --arg=accepted=1 (default) accepts it through the real service, 0 leaves it submitted.
            // --arg=gateway=midtrans (default) | xendit | none; --arg=needs_prep=0|1 flags the product for the kitchen;
            // --arg=staff=1 lets that cashier sign in (POS, her shift, history, sales report; this outlet only).
            // Returns access_token, invoice (null until accepted), grand_total, her shift_id and cashier_email, and the keys that sign the gateway's webhooks.
            'dine.bill' => function (array $options): array {
                $suffix = Str::upper(Str::random(5));
                $gateway = $options['gateway'] ?? 'midtrans';
                $qty = (int) ($options['qty'] ?? 2);

                return DB::transaction(function () use ($suffix, $gateway, $qty, $options) {
                    // Codes must sort AFTER the shared E2E outlet and warehouse ('E2E', 'WH-E2E'): the super admin's default
                    // outlet and the first warehouse are the first by code, and specs of other features rely on the shared ones.
                    $outlet = Outlet::create(['code' => "PAY{$suffix}", 'name' => "Outlet Tagihan {$suffix}", 'is_active' => true, 'is_sales_enabled' => true]);
                    $warehouse = Warehouse::create([
                        'code' => "WH-PAY{$suffix}", 'name' => "Gudang Tagihan {$suffix}", 'type' => 'branch',
                        'is_active' => true, 'sort_order' => 1, 'outlet_id' => $outlet->id,
                    ]);
                    // Its own row, also for 'none': else the outlet would inherit the shared Midtrans or Xendit setting.
                    PaymentSetting::create([
                        'outlet_id' => $outlet->id,
                        'default_gateway' => $gateway === 'none' ? 'cash' : $gateway,
                        'midtrans_enabled' => $gateway === 'midtrans',
                        'midtrans_server_key' => PublicFlows::MIDTRANS_SERVER_KEY,
                        'midtrans_client_key' => 'SB-Mid-client-public-e2e',
                        'xendit_enabled' => $gateway === 'xendit',
                        'xendit_secret_key' => 'xnd_development_public_e2e',
                        'xendit_public_key' => 'xnd_public_development_public_e2e',
                        'xendit_callback_token' => E2eSeeder::XENDIT_CALLBACK_TOKEN,
                    ]);

                    $cashier = User::create([
                        'name' => "Kasir Tagihan {$suffix}",
                        'email' => 'bill-'.Str::lower($suffix).'@e2e.test',
                        'password' => Hash::make(E2eSeeder::PASSWORD),
                    ]);
                    $shift = app(CashierShiftService::class)->openShift($cashier, $cashier, 100000, null, $warehouse->id);

                    // A cashier who can sign in: the POS list, her shift, the history and the sales report of this outlet only.
                    if (($options['staff'] ?? '0') === '1') {
                        $cashier->markEmailAsVerified();
                        $cashier->update(['completed_tours' => TourController::TOURS]); // else a tour overlay blocks clicks
                        $cashier->syncPermissions(['dashboard-access', 'transactions-access', 'dine-orders-process', 'cashier-shifts-access', 'cashier-shifts-close', 'reports-access']);
                        $cashier->outlets()->sync([$outlet->id => ['is_default' => true]]);
                    }

                    $product = Product::create([
                        'category_id' => Category::where('name', 'E2E Kategori')->firstOrFail()->id,
                        'barcode' => "E2E-DB{$suffix}", 'title' => "Hidangan {$suffix}",
                        'buy_price' => 5000, 'sell_price' => 10000, 'stock' => 20, 'tax_type' => 'exclusive', 'tax_rate' => 0,
                        'needs_preparation' => ($options['needs_prep'] ?? '0') === '1',
                    ]);
                    $warehouse->products()->attach($product->id, ['stock' => 20]);

                    $area = DineArea::create(['name' => "Area {$suffix}", 'outlet_id' => $outlet->id, 'sort_order' => 0, 'is_active' => true]);
                    $table = DiningTable::create(['dine_area_id' => $area->id, 'name' => "M{$suffix}", 'capacity' => 4, 'is_active' => true]);
                    $order = DineOrder::create([
                        'dine_table_id' => $table->id,
                        'cashier_id' => $cashier->id, // accept() takes the open shift of this user
                        'status' => DineOrder::STATUS_SUBMITTED,
                        'submitted_at' => now(),
                        'payment_option' => DineOrder::PAY_AT_COUNTER,
                        'subtotal' => 10000 * $qty,
                        'item_count' => $qty,
                    ]);
                    $order->items()->create(['product_id' => $product->id, 'qty' => $qty, 'price' => 10000]);

                    if (($options['accepted'] ?? '1') === '1') {
                        app(DineOrderService::class)->accept($order);
                    }

                    return [
                        'order_id' => $order->id,
                        'access_token' => $order->access_token,
                        'invoice' => Transaction::find($order->fresh()->transaction_id)?->invoice,
                        'grand_total' => 10000 * $qty,
                        'shift_id' => $shift->id,
                        'cashier_email' => $cashier->email, // password 'password'; can sign in only with --arg=staff=1
                        'midtrans_server_key' => PublicFlows::MIDTRANS_SERVER_KEY,
                        'xendit_callback_token' => E2eSeeder::XENDIT_CALLBACK_TOKEN,
                    ];
                });
            },

            // What staff do to the order of --arg=access_token=<uuid>: --arg=action=reject (a submitted order),
            // cash-paid (the cashier settles the accepted bill in cash, as the POS list does) or qris-pending
            // (the cashier starts her own QRIS charge: the bill is pending with a tender row, and takes over a guest charge).
            'dine.bill-act' => function (array $options): array {
                $order = DineOrder::where('access_token', $options['access_token'])->firstOrFail();
                $service = app(DineOrderService::class);

                // Each action by name: a typo must fail, not become a cash payment.
                match ($options['action'] ?? null) {
                    'reject' => $service->reject($order),
                    'cash-paid' => $service->pay($order, User::findOrFail($order->cashier_id), [['method' => 'cash', 'amount' => (int) $order->subtotal]]),
                    'qris-pending' => $service->pay($order, User::findOrFail($order->cashier_id), [['method' => 'qris', 'amount' => (int) $order->subtotal]]),
                    default => throw new \InvalidArgumentException('dine.bill-act: unknown action '.($options['action'] ?? '(none)').' (reject, cash-paid, qris-pending).'),
                };

                return ['status' => $order->fresh()->status];
            },

            // Lowers a product's stock (product and E2E warehouse together): --arg=product_id --arg=stock.
            'dine.set-stock' => function (array $options): array {
                $stock = (int) $options['stock'];
                Product::whereKey($options['product_id'])->update(['stock' => $stock]);
                ProductWarehouse::where('product_id', $options['product_id'])->update(['stock' => $stock]);

                return ['stock' => $stock];
            },

            // Reads back what a step cannot see on one page: --arg=order_id=<id> or --arg=access_token=<uuid>,
            // and optionally --arg=product_id=<id> for its stock.
            'dine.order-state' => function (array $options): array {
                $order = isset($options['access_token'])
                    ? DineOrder::where('access_token', $options['access_token'])->firstOrFail()
                    : DineOrder::findOrFail($options['order_id']);
                $transaction = $order->transaction_id ? Transaction::find($order->transaction_id) : null;

                return [
                    'order_id' => $order->id,
                    'status' => $order->status,
                    'payment_option' => $order->payment_option,
                    'notes' => $order->notes,
                    'subtotal' => $order->subtotal,
                    'item_count' => $order->item_count,
                    'payment_attempt' => $transaction?->payment_attempt,
                    'payment_failure_reason' => $transaction?->payment_failure_reason,
                    'payment_reference' => $transaction?->payment_reference,
                    'late_superseded_payment_at' => $transaction?->late_superseded_payment_at?->toIso8601String(),
                    'paid_after_close_at' => $transaction?->paid_after_close_at?->toIso8601String(),
                    'shift_id' => $transaction?->cashier_shift_id,
                    // How many audit entries of each event the bill has (a step checks "once", not "some").
                    'audit' => $transaction
                        ? AuditLog::where('auditable_type', $transaction->getMorphClass())->where('auditable_id', $transaction->id)
                            ->selectRaw('event, COUNT(*) as total')->groupBy('event')->pluck('total', 'event')->map(fn ($total) => (int) $total)->all()
                        : [],
                    'in_open_bills' => $transaction ? Transaction::openBills()->whereKey($transaction->id)->exists() : false,
                    'orders_on_table' => DineOrder::where('dine_table_id', $order->dine_table_id)->count(),
                    'invoice' => $transaction?->invoice,
                    'payment_status' => $transaction?->payment_status,
                    'payment_method' => $transaction?->payment_method,
                    'cash' => $transaction?->cash,
                    'change' => $transaction?->change,
                    'preparing' => $order->preparing_at !== null,
                    'ready' => $order->ready_at !== null,
                    'served' => $order->served_at !== null,
                    'receivables' => $transaction ? Receivable::where('transaction_id', $transaction->id)->count() : 0,
                    'kitchen_items' => $transaction ? $transaction->kitchenItems()->count() : 0,
                    'grand_total' => $transaction?->grand_total,
                    'product_stock' => isset($options['product_id']) ? (int) Product::find($options['product_id'])->stock : null,
                    'warehouse_stock' => isset($options['product_id'])
                        ? (int) ProductWarehouse::where('product_id', $options['product_id'])->sum('stock') : null,
                ];
            },

            // A table looked up by name (the UI never shows the token): --arg=name=<table name>.
            'dine.table-state' => function (array $options): array {
                $table = DiningTable::with('area')->where('name', $options['name'])->first();

                return [
                    'exists' => (bool) $table, 'token' => $table?->token, 'is_active' => $table?->is_active,
                    'area_name' => $table?->area?->name, 'capacity' => $table?->capacity,
                    'orders' => $table?->orders()->count(),
                ];
            },

            // How many dine areas have a blank (empty or whitespace) name, so a step checks its own claim instead of
            // counting rows that other steps in the shared e2e database add.
            'dine.blank-areas' => fn (): array => ['count' => DineArea::whereRaw("TRIM(name) = ''")->count()],

            // A user holding only the listed permissions (password 'password'), in the E2E outlet:
            // --arg=email=<email> --arg=permissions=dine-orders-access,dine-tables-access.
            'dine.user' => function (array $options): array {
                $user = User::updateOrCreate(['email' => $options['email']], [
                    'name' => 'E2E Dine '.Str::upper(Str::random(4)),
                    'password' => Hash::make(E2eSeeder::PASSWORD),
                    'completed_tours' => TourController::TOURS, // else a tour overlay blocks clicks
                ]);
                $user->markEmailAsVerified();
                $user->syncRoles([]);
                $user->syncPermissions(explode(',', $options['permissions']));
                $user->outlets()->sync([Outlet::where('code', E2eSeeder::OUTLET_CODE)->firstOrFail()->id => ['is_default' => true]]);

                return ['user_id' => $user->id, 'email' => $user->email];
            },

            // Closes every open shift of --arg=cashier=<email>, so accepting needs a fresh open-shift.
            'dine.close-shifts' => function (array $options): array {
                $user = User::where('email', $options['cashier'])->firstOrFail();
                $closed = CashierShift::open()->where('user_id', $user->id)
                    ->update(['status' => CashierShift::STATUS_CLOSED, 'closed_at' => now()]);

                return ['closed' => $closed];
            },

            // Toggles the guest self-order setting: --arg=enabled=0|1.
            'dine.self-order' => function (array $options): array {
                Setting::set('dine_in_self_order_enabled', ($options['enabled'] ?? '1') === '1' ? '1' : '0');

                return ['enabled' => $options['enabled'] ?? '1'];
            },
        ];
    }
}
