<?php

namespace App\Testing\E2e\Scenarios;

use App\Http\Controllers\TourController;
use App\Models\CashierShift;
use App\Models\DineArea;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Receivable;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\E2eSeeder;
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

            // A submitted guest order: --arg=token=<table token> --arg=product_id=<id> --arg=qty=<n> --arg=notes=<text>.
            'dine.order' => function (array $options): array {
                $table = DiningTable::where('token', $options['token'])->firstOrFail();
                $product = Product::findOrFail($options['product_id']);
                $qty = (int) ($options['qty'] ?? 1);

                $order = DineOrder::create([
                    'dine_table_id' => $table->id,
                    'status' => DineOrder::STATUS_SUBMITTED,
                    'submitted_at' => now(),
                    'payment_option' => DineOrder::PAY_AT_COUNTER,
                    'notes' => $options['notes'] ?? null,
                    'subtotal' => (int) $product->sell_price * $qty,
                    'item_count' => $qty,
                ]);
                $order->items()->create([
                    'product_id' => $product->id, 'qty' => $qty, 'price' => (int) $product->sell_price,
                ]);

                return ['order_id' => $order->id, 'access_token' => $order->access_token, 'subtotal' => $order->subtotal];
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
                    'notes' => $order->notes,
                    'subtotal' => $order->subtotal,
                    'item_count' => $order->item_count,
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
