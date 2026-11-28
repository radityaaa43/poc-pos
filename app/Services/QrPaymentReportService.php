<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * REQ-005 success metrics for the table-QR flow: the paid-online share, the median acceptance-to-paid time and the
 * "Bayar ganda" count, per outlet and month. Read-only; counts and minutes only.
 */
class QrPaymentReportService
{
    /**
     * @param  array<int, int|string>  $warehouseIds  the chosen outlet's warehouses
     * @return array{month: string, paid: int, paid_online: int, online_percent: ?float, median_minutes: ?float, timed: int, double_paid: int}
     */
    public function monthSummary(array $warehouseIds, CarbonInterface $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $start->copy()->addMonth();
        $gateway = TransactionTenderService::GATEWAY_METHODS;

        // A QR bill: a sale with a dine order, accepted in the month, in the outlet's warehouses.
        $cohort = fn () => Transaction::query()
            ->join('dine_orders', 'dine_orders.transaction_id', '=', 'transactions.id')
            ->whereIn('transactions.warehouse_id', $warehouseIds)
            ->where('dine_orders.accepted_at', '>=', $start)
            ->where('dine_orders.accepted_at', '<', $end);

        // Paid online = paid + a gateway method + no tender rows (a guest charge writes none; a cashier payment does).
        $placeholder = implode(', ', array_fill(0, count($gateway), '?'));
        $online = "transactions.payment_status = 'paid' and transactions.payment_method in ({$placeholder}) "
            .'and not exists (select 1 from transaction_tenders tt where tt.transaction_id = transactions.id)';

        $row = $cohort()->selectRaw(
            "sum(case when transactions.payment_status = 'paid' then 1 else 0 end) as paid, "
            .'sum(case when '.$online.' then 1 else 0 end) as paid_online, '
            .'sum(case when transactions.late_superseded_payment_at is not null then 1 else 0 end) as double_paid',
            $gateway
        )->first();

        $paid = (int) ($row->paid ?? 0);
        $paidOnline = (int) ($row->paid_online ?? 0);

        // Median acceptance-to-paid: online-paid Pay-now bills with a kitchen line take the first kitchen intake.
        $samples = $cohort()
            ->where('transactions.payment_status', 'paid')
            ->whereIn('transactions.payment_method', $gateway)
            ->where('transactions.is_pay_later', false)
            ->whereNotExists(fn ($query) => $query->from('transaction_tenders')->whereColumn('transaction_tenders.transaction_id', 'transactions.id')->select(DB::raw(1)))
            ->join('kitchen_items', 'kitchen_items.transaction_id', '=', 'transactions.id')
            ->groupBy('dine_orders.id', 'dine_orders.accepted_at')
            ->selectRaw('min(kitchen_items.accepted_at) as first_kitchen, dine_orders.accepted_at as accepted')
            ->get();

        $minutes = $samples
            ->map(fn ($sample) => (float) Carbon::parse($sample->accepted)->diffInMinutes(Carbon::parse($sample->first_kitchen), false))
            ->sort()
            ->values()
            ->all();

        return [
            'month' => $start->format('Y-m'),
            'paid' => $paid,
            'paid_online' => $paidOnline,
            'online_percent' => $paid > 0 ? round(100 * $paidOnline / $paid, 1) : null,
            'median_minutes' => $this->median($minutes),
            'timed' => count($minutes),
            'double_paid' => (int) ($row->double_paid ?? 0),
        ];
    }

    /** @param  list<float>  $values  sorted */
    private function median(array $values): ?float
    {
        $count = count($values);
        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        return round($count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2, 1);
    }
}
