<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\Cart;
use App\Models\CashierShift;
use App\Models\Customer;
use App\Models\DiscountApprovalLog;
use App\Models\Receivable;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Warehouse;
use App\Testing\E2e\Scenarios;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Str;

/**
 * POS operations (cashier shifts, held transactions, sales returns, discount approval, receivables).
 * All names are `posops.*`; a step arranges its own rows and finds them by their unique names.
 */
class PosOperations
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // Closes every open shift of --arg=cashier=<email> (default cashier@e2e.test) and empties their carts.
            'posops.no-shift' => function (array $options): array {
                $cashier = self::user($options['cashier'] ?? 'cashier@e2e.test');
                CashierShift::open()->where('user_id', $cashier->id)
                    ->update(['status' => CashierShift::STATUS_CLOSED, 'closed_at' => now()]);
                Cart::where('cashier_id', $cashier->id)->delete();

                return ['cashier_id' => $cashier->id];
            },

            // Empties the carts (active and held) of --arg=cashier=<email>, so a step starts from an empty POS.
            'posops.clear-carts' => function (array $options): array {
                $cashier = self::user($options['cashier'] ?? 'cashier@e2e.test');
                Cart::where('cashier_id', $cashier->id)->delete();

                return ['cashier_id' => $cashier->id];
            },

            // A product (--arg=title, default unique) plus a held cart of --arg=qty (default 2) of it
            // labelled --arg=label, for --arg=cashier. Clears that cashier's other carts first.
            'posops.held-cart' => function (array $options): array {
                $cashier = self::user($options['cashier'] ?? 'cashier@e2e.test');
                $product = self::product($options['title'] ?? null, 20);
                $qty = (int) ($options['qty'] ?? 2);
                $label = $options['label'] ?? 'Tahan '.Str::upper(Str::random(5));

                Cart::where('cashier_id', $cashier->id)->delete();
                Cart::create([
                    'cashier_id' => $cashier->id,
                    'warehouse_id' => self::warehouse()->id,
                    'product_id' => $product['product_id'],
                    'qty' => $qty,
                    'price' => $qty * 10000,
                    'hold_id' => (string) Str::uuid(),
                    'hold_label' => $label,
                    'held_at' => now(),
                ]);

                return $product + ['label' => $label, 'qty' => $qty];
            },

            // A finished sale: --arg=qty (default 3) of a new 10.000 product, --arg=left stock after the sale
            // (default 7), --arg=method=cash|pay_later, --arg=customer=<name>|none, --arg=guest=1 (a pay-later guest
            // bill: no customer, no receivable, a note --arg=note=<text>). The sale belongs to the
            // cashier's open shift when there is one. Stock already reflects the sale.
            'posops.sale' => function (array $options): array {
                $qty = (int) ($options['qty'] ?? 3);
                $product = self::product($options['title'] ?? null, (int) ($options['left'] ?? 7));
                $guest = ($options['guest'] ?? '0') === '1';
                $customerName = $options['customer'] ?? 'Pelanggan '.Str::upper(Str::random(5));
                $customer = $guest || $customerName === 'none' ? null : self::customer($customerName);
                $payLater = $guest || ($options['method'] ?? 'cash') === 'pay_later';
                $cashier = self::user($options['cashier'] ?? 'cashier@e2e.test');
                $total = $qty * 10000;

                $transaction = Transaction::create([
                    'cashier_id' => $cashier->id,
                    'cashier_shift_id' => CashierShift::open()->where('user_id', $cashier->id)->value('id'),
                    'warehouse_id' => self::warehouse()->id,
                    'customer_id' => $customer?->id,
                    'invoice' => 'TRX-E2E'.Str::upper(Str::random(8)),
                    'cash' => $payLater ? 0 : $total,
                    'change' => 0,
                    'discount' => 0,
                    'shipping_cost' => 0,
                    'grand_total' => $total,
                    'payment_method' => $payLater ? 'pay_later' : 'cash',
                    'payment_status' => $payLater ? 'unpaid' : 'paid',
                    'is_pay_later' => $payLater,
                    'note' => $guest ? ($options['note'] ?? 'Meja luar 2') : null,
                ]);
                $transaction->details()->create([
                    'product_id' => $product['product_id'],
                    'qty' => $qty,
                    'conversion_factor' => 1,
                    'base_unit_price' => 10000,
                    'unit_price' => 10000,
                    'price' => $total,
                    'cost_total' => $qty * 5000,
                ]);
                $transaction->profits()->create(['total' => $qty * 5000]);

                $receivable = $payLater && ! $guest ? Receivable::create([
                    'customer_id' => $customer->id,
                    'transaction_id' => $transaction->id,
                    'invoice' => $transaction->invoice,
                    'total' => $total,
                    'paid' => 0,
                    'due_date' => now()->addDays(14),
                    'status' => 'unpaid',
                ]) : null;

                return $product + [
                    'transaction_id' => $transaction->id,
                    'invoice' => $transaction->invoice,
                    'customer' => $customer?->name,
                    'receivable_id' => $receivable?->id,
                    'total' => $total,
                ];
            },

            // A cash sale of --arg=total (default 20000) waiting for discount approval: --arg=discount
            // (default 5000) is already taken off the total, as checkout does.
            'posops.pending-discount' => function (array $options): array {
                $cashier = self::user($options['cashier'] ?? 'cashier@e2e.test');
                $discount = (int) ($options['discount'] ?? 5000);
                $grandTotal = (int) ($options['total'] ?? 20000) - $discount;

                $transaction = Transaction::create([
                    'cashier_id' => $cashier->id,
                    'warehouse_id' => self::warehouse()->id,
                    'invoice' => 'TRX-E2E'.Str::upper(Str::random(8)),
                    'cash' => $grandTotal,
                    'change' => 0,
                    'discount' => $discount,
                    'shipping_cost' => 0,
                    'grand_total' => $grandTotal,
                    'payment_method' => 'cash',
                    'payment_status' => 'pending_approval',
                    'discount_approval_status' => 'pending',
                ]);
                DiscountApprovalLog::create([
                    'transaction_id' => $transaction->id,
                    'cashier_id' => $cashier->id,
                    'requested_discount' => $discount,
                    'status' => 'pending',
                ]);

                return ['transaction_id' => $transaction->id, 'invoice' => $transaction->invoice, 'grand_total' => $grandTotal];
            },

            // A customer named --arg=name, so the POS customer picker has someone to choose.
            'posops.customer' => fn (array $options): array => [
                'customer' => self::customer($options['name'] ?? 'Pelanggan '.Str::upper(Str::random(5)))->name,
            ],

            // Sets the checkout discount that needs approval (--arg=value in rupiah, 0 switches it off).
            'posops.discount-threshold' => function (array $options): array {
                Setting::set('discount_approval_threshold', (int) ($options['value'] ?? 0));

                return ['threshold' => (int) ($options['value'] ?? 0)];
            },

            // A pay-later sale with its receivable: --arg=total (default 100000), --arg=paid (default 0),
            // --arg=due_days from today (negative = overdue, default 14), --arg=customer=<name> (a new
            // customer) or --arg=customer_id=<id> (an existing one).
            'posops.receivable' => function (array $options): array {
                $total = (int) ($options['total'] ?? 100000);
                $paid = (int) ($options['paid'] ?? 0);
                $customer = isset($options['customer_id'])
                    ? Customer::findOrFail($options['customer_id'])
                    : self::customer($options['customer'] ?? 'Pelanggan '.Str::upper(Str::random(5)));

                $transaction = Transaction::create([
                    'cashier_id' => self::user('cashier@e2e.test')->id,
                    'warehouse_id' => self::warehouse()->id,
                    'customer_id' => $customer->id,
                    'invoice' => 'TRX-E2E'.Str::upper(Str::random(8)),
                    'cash' => 0,
                    'change' => 0,
                    'discount' => 0,
                    'shipping_cost' => 0,
                    'grand_total' => $total,
                    'payment_method' => 'pay_later',
                    'payment_status' => 'unpaid',
                ]);
                $receivable = Receivable::create([
                    'customer_id' => $customer->id,
                    'transaction_id' => $transaction->id,
                    'invoice' => $transaction->invoice,
                    'total' => $total,
                    'paid' => $paid,
                    'due_date' => now()->addDays((int) ($options['due_days'] ?? 14)),
                    'status' => $paid > 0 ? 'partial' : 'unpaid',
                ]);

                return [
                    'receivable_id' => $receivable->id,
                    'invoice' => $transaction->invoice,
                    'customer' => $customer->name,
                    'total' => $total,
                ];
            },
        ];
    }

    private static function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private static function warehouse(): Warehouse
    {
        return Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
    }

    private static function customer(string $name): Customer
    {
        return Customer::create(['name' => $name, 'no_telp' => random_int(81000000000, 81999999999), 'address' => 'Jl. Uji E2E']);
    }

    /** @return array<string, mixed> product_id, title, barcode (reuses the shared `product` scenario) */
    private static function product(?string $title, int $stock): array
    {
        return Scenarios::registry()['product'](array_filter(['title' => $title, 'stock' => (string) $stock]));
    }
}
