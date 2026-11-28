<?php

namespace App\Testing\E2e\Scenarios;

use App\Models\AuditLog;
use App\Models\CashierShift;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\User;
use App\Models\Warehouse;
use App\Testing\E2e\Scenarios;
use Database\Seeders\E2eSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** Closing a shift that still holds work (REQ-003 AC-12, 13, 17, 20, 27). All names are `shiftclose.*`. */
class ShiftClose
{
    /** @return array<string, \Closure(array<string, string>): array<string, mixed>> */
    public static function registry(): array
    {
        return [
            // The shift --arg=shift_id=<id>, or the one the sale --arg=invoice=<invoice> belongs to: its status, stored
            // recap, audit entries per event and what the forced close over a hold recorded (`override`, else null).
            'shiftclose.state' => function (array $options): array {
                $shift = CashierShift::findOrFail($options['shift_id'] ?? Transaction::where('invoice', $options['invoice'])->firstOrFail()->cashier_shift_id);

                return [
                    'shift_id' => $shift->id,
                    ...$shift->only(['status', 'close_notes', 'expected_cash', 'cash_difference']),
                    ...$shift->only(['unpaid_bills_total', 'non_cash_sales_total', 'transactions_count']),
                    'audit' => self::auditCounts($shift),
                    'override' => AuditLog::where('auditable_type', $shift->getMorphClass())->where('auditable_id', $shift->id)
                        ->where('event', 'cashier_shift.hold_overridden')->first()?->meta,
                ];
            },

            // The sale --arg=invoice=<invoice>: payment status, both stock columns of its first line's product, how many
            // times its stock was put back by a cancellation, and its audit entries per event.
            'shiftclose.sale-state' => function (array $options): array {
                $transaction = Transaction::where('invoice', $options['invoice'])->firstOrFail();
                $productId = $transaction->details()->firstOrFail()->product_id;

                return [
                    'payment_status' => $transaction->payment_status,
                    'product_stock' => (int) Product::findOrFail($productId)->stock,
                    'warehouse_stock' => (int) ProductWarehouse::where('product_id', $productId)
                        ->where('warehouse_id', $transaction->warehouse_id)->value('stock'),
                    'restocks' => StockMutation::where('reference_type', 'transaction_expire')->where('reference_id', $transaction->id)->count(),
                    'audit' => self::auditCounts($transaction),
                ];
            },

            // A partly paid Bayar sekarang split sale of 20.000 in the open shift of --arg=cashier=<email> (default
            // cashier@e2e.test): 5.000 paid in cash, 15.000 by a bank transfer still to be confirmed. Stock already taken.
            'shiftclose.split-sale' => function (array $options): array {
                $sale = Scenarios::registry()['posretry.failed-sale']([...$options, 'qty' => '2', 'status' => 'pending']);
                $accountId = Scenarios::registry()['pos.bank-account']([])['bank_account_id'];
                $transaction = Transaction::findOrFail($sale['transaction_id']);
                $transaction->update(['payment_method' => 'split', 'bank_account_id' => $accountId, 'cash' => 5000]);
                $transaction->tenders()->createMany([
                    ['method' => TransactionTender::METHOD_CASH, 'amount' => 5000, 'cash_received' => 5000, 'payment_status' => TransactionTender::STATUS_PAID],
                    ['method' => TransactionTender::METHOD_BANK_TRANSFER, 'amount' => 15000, 'bank_account_id' => $accountId, 'payment_status' => TransactionTender::STATUS_PENDING],
                ]);

                return $sale + ['owed' => 15000];
            },

            // A new manager of the outlet of the shift --arg=shift_id=<id> (password 'password'), for a shift in an outlet
            // the seeded manager@e2e.test is not in.
            'shiftclose.manager' => function (array $options): array {
                $outletId = CashierShift::with('warehouse')->findOrFail($options['shift_id'])->warehouse->outlet_id;
                $manager = Scenarios::registry()['platform.user']([]);
                User::findOrFail($manager['id'])->outlets()->sync([$outletId => ['is_default' => true]]);

                return ['email' => $manager['email']];
            },

            // Three shifts of the E2E outlet closed in 2024-02 (a fixed month no other step uses): a plain close, a
            // force close that was not held, and a force close over a hold with a note. --arg=marker makes a rerun
            // replace them instead of adding a second set.
            'shiftclose.summary-shifts' => function (array $options): array {
                $marker = $options['marker'] ?? 'shiftclose-summary';
                $warehouse = Warehouse::where('code', E2eSeeder::WAREHOUSE_CODE)->firstOrFail();
                $cashier = User::where('email', 'cashier@e2e.test')->firstOrFail();
                CashierShift::where('notes', $marker)->delete();

                $make = function (string $status, string $day, ?string $note, bool $overHold) use ($warehouse, $cashier, $marker): CashierShift {
                    $closedAt = Carbon::parse($day.' 10:00:00', config('app.timezone'));
                    $shift = CashierShift::create([
                        'user_id' => $cashier->id, 'warehouse_id' => $warehouse->id, 'opened_by' => $cashier->id,
                        'opened_at' => $closedAt->copy()->subHours(8),
                        'closed_at' => $closedAt,
                        'opening_cash' => 100000, 'expected_cash' => 100000, 'actual_cash' => 100000,
                        'status' => $status, 'close_notes' => $note, 'notes' => $marker,
                    ]);
                    if ($overHold) {
                        AuditLog::create([
                            'event' => 'cashier_shift.hold_overridden', 'module' => 'cashier_shifts',
                            'auditable_type' => $shift->getMorphClass(), 'auditable_id' => $shift->id,
                            'description' => 'Shift ditutup paksa dengan item belum diserahkan.',
                            'meta' => ['note' => $note],
                        ]);
                    }

                    return $shift;
                };

                $plain = $make(CashierShift::STATUS_CLOSED, '2024-02-10', 'Tutup biasa', false);
                $forced = $make(CashierShift::STATUS_FORCE_CLOSED, '2024-02-11', 'Ditutup paksa', false);
                $held = $make(CashierShift::STATUS_FORCE_CLOSED, '2024-02-12', 'Tamu sudah pulang', true);

                return [
                    'month' => '2024-02', 'outlet_id' => $warehouse->outlet_id,
                    'plain_id' => $plain->id, 'forced_id' => $forced->id, 'held_id' => $held->id,
                ];
            },
        ];
    }

    /** @return array<string, int> */
    private static function auditCounts(Model $subject): array
    {
        return AuditLog::where('auditable_type', $subject->getMorphClass())->where('auditable_id', $subject->getKey())
            ->selectRaw('event, COUNT(*) as total')->groupBy('event')->pluck('total', 'event')->map(fn ($total) => (int) $total)->all();
    }
}
