<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Customer;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Models\TransactionDetail;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Data for the public, login-free flows (customer portal, public invoice, payment webhooks).
 */
class PublicFlows
{
    /** Server key of the outlet created by `public.sale`; the test signs Midtrans webhooks with it. */
    public const MIDTRANS_SERVER_KEY = 'SB-Mid-server-public-e2e';

    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // The public routes share one per-IP throttle bucket (throttle:10,1 and 5,1 count the same
            // hits), so steps that call them start from an empty bucket. Cache holds nothing else a step needs.
            'public.reset-throttle' => function (): array {
                Cache::flush();

                return ['flushed' => true];
            },

            // A sale in its own outlet (so its payment settings never touch other groups' steps), with
            // one item of --arg=qty (default 2) at 10000. --arg=method=pay_later (default) adds a
            // receivable of the grand total with --arg=paid already paid (all of it = a settled receivable); method=cash is a settled sale.
            // --arg=gateway=midtrans (default) | xendit | none picks the outlet's default payment gateway.
            // --arg=tax / --arg=discount / --arg=shipping add those lines (default 0).
            'public.sale' => function (array $options): array {
                $suffix = Str::upper(Str::random(5));
                $method = $options['method'] ?? 'pay_later';
                $gateway = $options['gateway'] ?? 'midtrans';
                $qty = (int) ($options['qty'] ?? 2);
                $tax = (int) ($options['tax'] ?? 0);
                $discount = (int) ($options['discount'] ?? 0);
                $shipping = (int) ($options['shipping'] ?? 0);
                $paid = (int) ($options['paid'] ?? 0);
                $subtotal = $qty * 10000;
                $grandTotal = $subtotal - $discount + $tax + $shipping;

                return DB::transaction(function () use ($suffix, $method, $gateway, $qty, $tax, $discount, $shipping, $paid, $grandTotal, $options) {
                    // --arg=outlet=e2e puts the sale in the shared E2E warehouse, where staff can open it on
                    // the dashboard; the default is a private outlet with its own payment settings.
                    if (($options['outlet'] ?? '') === 'e2e') {
                        $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                    } else {
                        $outlet = Outlet::create(['code' => "PUB{$suffix}", 'name' => "Outlet Publik {$suffix}", 'is_active' => true, 'is_sales_enabled' => true]);
                        $warehouse = Warehouse::create([
                            'code' => "WH-PUB{$suffix}", 'name' => "Gudang Publik {$suffix}", 'type' => 'branch',
                            'is_active' => true, 'sort_order' => 1, 'outlet_id' => $outlet->id,
                        ]);

                        PaymentSetting::create([
                            'outlet_id' => $outlet->id,
                            'default_gateway' => $gateway === 'none' ? 'cash' : $gateway,
                            'midtrans_enabled' => $gateway === 'midtrans',
                            'midtrans_server_key' => self::MIDTRANS_SERVER_KEY,
                            'midtrans_client_key' => 'SB-Mid-client-public-e2e',
                            'xendit_enabled' => $gateway === 'xendit',
                            'xendit_secret_key' => 'xnd_development_public_e2e',
                            'xendit_public_key' => 'xnd_public_development_public_e2e',
                            'xendit_callback_token' => E2eSeeder::XENDIT_CALLBACK_TOKEN,
                        ]);
                    }

                    $customer = Customer::create(['name' => $options['customer'] ?? "Pelanggan Publik {$suffix}", 'no_telp' => '081200000000', 'address' => 'Jl. Publik 1']);
                    $product = Product::where('barcode', 'E2E-001')->firstOrFail();

                    $transaction = Transaction::create([
                        'cashier_id' => User::where('email', 'cashier@e2e.test')->firstOrFail()->id,
                        'warehouse_id' => $warehouse->id,
                        'customer_id' => $customer->id,
                        'invoice' => "INV-PUB-{$suffix}",
                        'cash' => $method === 'cash' ? $grandTotal + 5000 : 0,
                        'change' => $method === 'cash' ? 5000 : 0,
                        'discount' => $discount,
                        'tax_total' => $tax,
                        'shipping_cost' => $shipping,
                        'grand_total' => $grandTotal,
                        'payment_method' => $method,
                        'payment_status' => $method === 'cash' ? 'paid' : 'unpaid',
                    ]);
                    TransactionDetail::create([
                        'transaction_id' => $transaction->id,
                        'product_id' => $product->id,
                        'qty' => $qty,
                        'price' => $qty * 10000,
                    ]);

                    $receivable = $method === 'pay_later' ? Receivable::create([
                        'customer_id' => $customer->id,
                        'transaction_id' => $transaction->id,
                        'invoice' => $transaction->invoice,
                        'due_date' => now()->addDays(7),
                        'total' => $grandTotal,
                        'paid' => $paid,
                        'status' => $paid >= $grandTotal ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
                    ]) : null;

                    return [
                        'invoice' => $transaction->invoice,
                        'token' => $transaction->access_token,
                        'transaction_id' => $transaction->id,
                        'receivable_id' => $receivable?->id,
                        'customer' => $customer->name,
                        'product' => $product->title,
                        'grand_total' => $grandTotal,
                        'midtrans_server_key' => self::MIDTRANS_SERVER_KEY,
                        'xendit_callback_token' => E2eSeeder::XENDIT_CALLBACK_TOKEN,
                    ];
                });
            },
        ];
    }
}
