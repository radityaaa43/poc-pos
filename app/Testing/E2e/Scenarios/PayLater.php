<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Outlet;
use App\Models\Receivable;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Setting;
use App\Models\Transaction;
use Database\Seeders\E2eSeeder;

/**
 * Arrange scenarios for Bayar Belakangan (`paylater.*`): the per-outlet setting, a credit customer, and the receivable
 * a sale left behind. The setting is off by default, so a step that sells on pay later turns it on first.
 */
class PayLater
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // Sets Bayar Belakangan for the E2E outlet: --arg=enabled=0|1 (default 1).
            'paylater.setting' => function (array $options): array {
                $enabled = ($options['enabled'] ?? '1') === '1';
                Setting::setForOutlet('pay_later_enabled', $enabled ? '1' : '0', Outlet::where('code', E2eSeeder::OUTLET_CODE)->firstOrFail());

                return ['enabled' => $enabled];
            },

            // Flags the customer --arg=customer_id=<id> "Boleh piutang" (it is not fillable: only its approver sets it).
            'paylater.credit' => function (array $options): array {
                Customer::findOrFail($options['customer_id'])->forceFill(['may_buy_on_credit' => true])->save();

                return ['customer_id' => (int) $options['customer_id']];
            },

            // The setting of the outlet --arg=code=<outlet code> (default the E2E outlet) and how many audit rows its changes left.
            'paylater.outlet-state' => function (array $options): array {
                $outlet = Outlet::where('code', $options['code'] ?? E2eSeeder::OUTLET_CODE)->firstOrFail();

                return [
                    'enabled' => Setting::getBoolForOutlet('pay_later_enabled', $outlet),
                    'audits' => AuditLog::where('event', 'outlet.pay_later_updated')->where('auditable_id', $outlet->id)->count(),
                ];
            },

            // A completed return of --arg=qty=<n> units of the sale's first line (--arg=invoice=<invoice>), as the Retur
            // page ends up: the return and its item are written directly so the step needs no second UI flow.
            'paylater.return' => function (array $options): array {
                $transaction = Transaction::where('invoice', $options['invoice'])->firstOrFail();
                $detail = $transaction->details()->firstOrFail();
                $qty = (int) ($options['qty'] ?? $detail->qty);
                $return = SalesReturn::create([
                    'code' => 'SR-E2E-'.strtoupper(substr(md5((string) microtime(true)), 0, 8)),
                    'transaction_id' => $transaction->id,
                    'status' => 'completed',
                    'total_return_amount' => $qty * (int) $detail->unit_price,
                ]);
                SalesReturnItem::create([
                    'sales_return_id' => $return->id,
                    'transaction_detail_id' => $detail->id,
                    'product_id' => $detail->product_id,
                    'qty_sold' => $detail->qty,
                    'qty_return' => $qty,
                    'unit_price' => $detail->unit_price,
                    'subtotal' => $qty * (int) $detail->unit_price,
                    'return_reason' => 'E2E',
                ]);

                return ['invoice' => $transaction->invoice, 'returned' => $qty];
            },

            // The sale --arg=invoice=<invoice>: its payment status and how many receivables it left.
            'paylater.sale-state' => function (array $options): array {
                $transaction = Transaction::where('invoice', $options['invoice'])->firstOrFail();

                return [
                    'payment_status' => $transaction->payment_status,
                    'payment_method' => $transaction->payment_method,
                    'is_pay_later' => $transaction->is_pay_later,
                    'cashier_shift_id' => $transaction->cashier_shift_id,
                    'tenders' => $transaction->tenders()->count(),
                    'receivables' => Receivable::where('transaction_id', $transaction->id)->count(),
                ];
            },
        ];
    }
}
