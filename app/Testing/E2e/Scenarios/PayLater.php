<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\AuditLog;
use App\Models\CashierShift;
use App\Models\CreditApprovalRequest;
use App\Models\Customer;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Models\Receivable;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TransactionTenderService;
use Database\Seeders\E2eSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

            // Flags the customer --arg=customer_id=<id> "Boleh piutang" and, with --arg=limit=<rupiah>, sets their credit
            // limit (neither is fillable: only their approver sets them).
            'paylater.credit' => function (array $options): array {
                $limit = isset($options['limit']) ? ['credit_limit' => (int) $options['limit']] : [];
                Customer::findOrFail($options['customer_id'])->forceFill(['may_buy_on_credit' => true] + $limit)->save();

                return ['customer_id' => (int) $options['customer_id']];
            },

            // The customer --arg=customer_id=<id>: credit limit, sale and receivable counts and every audited limit change.
            'paylater.credit-state' => function (array $options): array {
                $customer = Customer::findOrFail($options['customer_id']);

                return [
                    'credit_limit' => $customer->credit_limit,
                    'sales' => Transaction::where('customer_id', $customer->id)->count(),
                    'receivables' => $customer->receivables()->count(),
                    'audits' => AuditLog::where('event', 'customer.credit_limit_updated')
                        ->where('auditable_type', $customer->getMorphClass())
                        ->where('auditable_id', $customer->id)
                        ->orderBy('id')->get()
                        ->map(fn (AuditLog $log): array => [
                            'user' => User::whereKey($log->user_id)->value('email'),
                            'at' => $log->created_at->toIso8601String(),
                            'before' => $log->before['credit_limit'],
                            'after' => $log->after['credit_limit'],
                        ])->all(),
                ];
            },

            // The credit exceptions of the customer --arg=customer_id=<id>: each request and the sale it became, how many
            // kitchen items their sales have, and the audit rows.
            'paylater.credit-requests' => function (array $options): array {
                $customer = Customer::findOrFail($options['customer_id']);

                return [
                    'requests' => CreditApprovalRequest::where('customer_id', $customer->id)->orderBy('id')->get()
                        ->map(fn (CreditApprovalRequest $request): array => [
                            'status' => $request->status,
                            'amount' => $request->amount,
                            'invoice' => Transaction::whereKey($request->transaction_id)->value('invoice'),
                        ])->all(),
                    'kitchen_items' => KitchenItem::whereIn('transaction_id', Transaction::where('customer_id', $customer->id)->select('id'))->count(),
                    'audits' => AuditLog::where('event', 'like', 'credit_approval.%')
                        ->where('auditable_type', $customer->getMorphClass())
                        ->where('auditable_id', $customer->id)
                        ->orderBy('id')->get()
                        ->map(fn (AuditLog $log): array => [
                            'event' => $log->event,
                            'user' => User::whereKey($log->user_id)->value('email'),
                            'amount' => $log->after['amount'],
                            'reason' => $log->after['reason'] ?? null,
                        ])->all(),
                ];
            },

            // A pending credit exception for a new credit customer, asked in the cashier's open shift (open one first).
            'paylater.pending-credit-request' => function (): array {
                $cashier = User::where('email', 'cashier@e2e.test')->firstOrFail();
                $shift = CashierShift::query()->open()->where('user_id', $cashier->id)->firstOrFail();
                $customer = Customer::create(['name' => 'Menunggu '.Str::upper(Str::random(5)), 'no_telp' => '08'.random_int(100000000, 999999999), 'address' => 'Jl. Uji E2E 1']);
                $customer->forceFill(['may_buy_on_credit' => true, 'credit_limit' => 0])->save();
                $request = CreditApprovalRequest::create([
                    'customer_id' => $customer->id,
                    'cashier_id' => $cashier->id,
                    'cashier_shift_id' => $shift->id,
                    'warehouse_id' => $shift->warehouse_id,
                    'hold_id' => 'HOLD-'.Str::upper(Str::random(12)),
                    'amount' => 10000,
                    'reason_code' => 'credit_limit_exceeded',
                    'remaining_limit' => 0,
                    'payload' => [],
                ]);

                return ['request_id' => $request->id, 'customer_id' => $customer->id];
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

            // The gateway claim of the sale --arg=invoice=<invoice> as a request that died before storing its charge
            // leaves it: no reference, claimed over two minutes ago. A query update, so the model cannot move the age.
            'paylater.abandon-claim' => function (array $options): array {
                $transaction = Transaction::where('invoice', $options['invoice'])->firstOrFail();
                DB::table('transaction_tenders')
                    ->where('transaction_id', $transaction->id)
                    ->whereIn('method', TransactionTenderService::GATEWAY_METHODS)
                    ->update(['payment_reference' => null, 'created_at' => now()->subMinutes(3)]);

                return ['invoice' => $transaction->invoice];
            },
        ];
    }
}
