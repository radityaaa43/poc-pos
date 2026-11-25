<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\E2eSeeder;

/**
 * Arrange scenarios for the offline-mode feature group (`offline.*`).
 */
class Offline
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // Empties the server-side POS cart of --arg=cashier (default cashier@e2e.test): earlier steps leave items in it.
            'offline.clear-cart' => function (array $options): array {
                $cashier = User::where('email', $options['cashier'] ?? 'cashier@e2e.test')->firstOrFail();

                return ['deleted' => Cart::where('cashier_id', $cashier->id)->delete()];
            },

            // Creates a customer named --arg=name (the POS refuses to check out without one).
            'offline.customer' => function (array $options): array {
                $customer = Customer::create([
                    'name' => $options['name'] ?? 'Pelanggan Offline',
                    'no_telp' => 81200000000 + random_int(1, 99999999),
                    'address' => 'Jl. E2E Offline',
                ]);

                return ['customer_id' => $customer->id, 'name' => $customer->name];
            },

            // Reports what the server holds for a queued sale: how many transactions carry
            // --arg=uuid, and the stock of --arg=product_id in both stock columns.
            'offline.sync-state' => function (array $options): array {
                $transactions = Transaction::where('client_uuid', $options['uuid'])->get();
                $state = ['count' => $transactions->count(), 'invoice' => $transactions->first()?->invoice];

                if (isset($options['product_id'])) {
                    $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                    $state['warehouse_stock'] = (int) $warehouse->products()->whereKey($options['product_id'])->first()->pivot->stock;
                    $state['product_stock'] = (int) Product::findOrFail($options['product_id'])->stock;
                }

                return $state;
            },
        ];
    }
}
