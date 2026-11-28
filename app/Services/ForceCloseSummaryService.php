<?php

namespace App\Services;

use App\Models\CashierShift;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/** REQ-003 AC-13 (#564): how many shifts were force-closed over a hold in one outlet and month. */
class ForceCloseSummaryService
{
    /**
     * Counts shifts closed in the given month (app timezone) among the outlet's warehouses. The rows are the
     * force-closed shifts that carry a `cashier_shift.hold_overridden` audit; the count is their number, so the
     * figure and the links cannot disagree.
     *
     * @return array{month: string, closed: int, force_closed: int, held: int, held_shifts: array<int, array{id: int, cashier: ?string, closed_at: ?string, close_notes: ?string}>}
     */
    public function monthSummary(Builder $visibleShifts, array $warehouseIds, CarbonInterface $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $start->copy()->addMonth();

        $counts = (clone $visibleShifts)
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('status', [CashierShift::STATUS_CLOSED, CashierShift::STATUS_FORCE_CLOSED])
            ->where('closed_at', '>=', $start)
            ->where('closed_at', '<', $end)
            ->selectRaw('count(*) as closed, sum(case when status = ? then 1 else 0 end) as force_closed', [CashierShift::STATUS_FORCE_CLOSED])
            ->first();

        /** @var Collection<int, CashierShift> $heldShifts */
        $heldShifts = (clone $visibleShifts)
            ->join('users', 'users.id', '=', 'cashier_shifts.user_id')
            ->whereIn('cashier_shifts.warehouse_id', $warehouseIds)
            ->where('cashier_shifts.status', CashierShift::STATUS_FORCE_CLOSED)
            ->where('cashier_shifts.closed_at', '>=', $start)
            ->where('cashier_shifts.closed_at', '<', $end)
            ->whereExists(function ($query) {
                $query->select('audit_logs.id')
                    ->from('audit_logs')
                    ->whereColumn('audit_logs.auditable_id', 'cashier_shifts.id')
                    ->where('audit_logs.auditable_type', (new CashierShift)->getMorphClass())
                    ->where('audit_logs.module', 'cashier_shifts')
                    ->where('audit_logs.event', 'cashier_shift.hold_overridden');
            })
            ->orderBy('cashier_shifts.closed_at')
            ->get(['cashier_shifts.id', 'users.name as cashier', 'cashier_shifts.closed_at', 'cashier_shifts.close_notes']);

        $rows = [];
        foreach ($heldShifts as $shift) {
            $rows[] = [
                'id' => $shift->id,
                'cashier' => $shift->getAttribute('cashier'),
                'closed_at' => $shift->closed_at?->toISOString(),
                'close_notes' => $shift->close_notes,
            ];
        }

        return [
            'month' => $start->format('Y-m'),
            'closed' => (int) ($counts->closed ?? 0),
            'force_closed' => (int) ($counts->force_closed ?? 0),
            'held' => $heldShifts->count(),
            'held_shifts' => $rows,
        ];
    }
}
