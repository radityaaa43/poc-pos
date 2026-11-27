<?php

namespace App\Testing\E2e\Scenarios;

use App\Http\Controllers\TourController;
use App\Models\AuditLog;
use App\Models\CashierShift;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\StockMutation;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\E2eSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Closing a shift that still holds work (REQ-003 AC-12, 13, 17, 20, 27). All names are `shiftclose.*`.
 */
class ShiftClose
{
    /**
     * @return array<string, \Closure(array<string, string>): array<string, mixed>>
     */
    public static function registry(): array
    {
        return [
            // The shift --arg=shift_id=<id>, or the one the sale --arg=invoice=<invoice> belongs to: its status, stored
            // recap, audit entries per event and what the forced close over a hold recorded (`override`, else null).
            'shiftclose.state' => function (array $options): array {
                $shift = CashierShift::findOrFail($options['shift_id']
                    ?? Transaction::where('invoice', $options['invoice'])->firstOrFail()->cashier_shift_id);

                return [
                    'shift_id' => $shift->id,
                    'status' => $shift->status,
                    'close_notes' => $shift->close_notes,
                    'expected_cash' => $shift->expected_cash,
                    'cash_difference' => $shift->cash_difference,
                    'unpaid_bills_total' => $shift->unpaid_bills_total,
                    'non_cash_sales_total' => $shift->non_cash_sales_total,
                    'transactions_count' => $shift->transactions_count,
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

            // A new manager of the outlet of the shift --arg=shift_id=<id> (password 'password'), for a shift in an outlet
            // the seeded manager@e2e.test is not in.
            'shiftclose.manager' => function (array $options): array {
                $outletId = CashierShift::with('warehouse')->findOrFail($options['shift_id'])->warehouse->outlet_id;
                $manager = User::create([
                    'name' => 'Manajer Tagihan '.Str::upper(Str::random(5)),
                    'email' => 'manager-'.Str::lower(Str::random(8)).'@e2e.test',
                    'password' => Hash::make(E2eSeeder::PASSWORD),
                    'completed_tours' => TourController::TOURS, // else a tour overlay blocks clicks
                ]);
                $manager->markEmailAsVerified();
                $manager->syncRoles(['manager']);
                $manager->outlets()->sync([$outletId => ['is_default' => true]]);

                return ['email' => $manager->email];
            },
        ];
    }

    /** @return array<string, int> */
    private static function auditCounts(Model $subject): array
    {
        return AuditLog::where('auditable_type', $subject->getMorphClass())->where('auditable_id', $subject->getKey())
            ->selectRaw('event, COUNT(*) as total')->groupBy('event')->pluck('total', 'event')
            ->map(fn ($total) => (int) $total)->all();
    }
}
