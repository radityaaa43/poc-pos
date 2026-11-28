<?php

namespace App\Services;

use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one answer to "is this table free" (#426, REQ-009 BR-1): computed from the table's orders, never stored.
 * `occupied` (Terisi) beats `reserved` (Dipesan); anything else is `free` (Kosong).
 */
class TableStatusService
{
    /**
     * `open_bills` is the dine half of Transaction::scopeOpenBills, so it matches the POS "Belum Lunas" list.
     *
     * @param  Collection<int, DiningTable>  $tables
     * @return Collection<int, array{status: string, open_bills: int, active_orders: int, order_id: ?int, reservation: ?array{id: int, time: string}}> keyed by table id
     */
    public function forTables(Collection $tables, ?CarbonInterface $now = null): Collection
    {
        $ids = $tables->pluck('id');
        $billed = DineOrder::IN_PROGRESS;
        $unpaid = [...Transaction::OPEN_BILL_PAYABLE, 'pending'];
        $marks = fn (array $values) => implode(', ', array_fill(0, count($values), '?'));

        $orders = DineOrder::query()
            ->leftJoin('transactions', 'transactions.id', '=', 'dine_orders.transaction_id')
            ->whereIn('dine_orders.dine_table_id', $ids)
            ->whereIn('dine_orders.status', [DineOrder::STATUS_SUBMITTED, ...DineOrder::IN_PROGRESS])
            ->groupBy('dine_orders.dine_table_id')
            ->selectRaw(
                'dine_orders.dine_table_id, count(*) as active_orders, min(dine_orders.id) as order_id,'
                ." sum(case when dine_orders.status in ({$marks($billed)}) and transactions.payment_status in ({$marks($unpaid)}) then 1 else 0 end) as open_bills",
                [...$billed, ...$unpaid],
            )
            ->toBase()
            ->get()
            ->keyBy('dine_table_id');
        $reservations = $this->reservations($ids, $now ?? now());

        return $tables->mapWithKeys(function (DiningTable $table) use ($orders, $reservations) {
            $row = $orders->get($table->id);
            $reservation = $reservations->get($table->id);

            return [$table->id => [
                'status' => $row ? 'occupied' : ($reservation ? 'reserved' : 'free'),
                'open_bills' => (int) ($row->open_bills ?? 0),
                'active_orders' => (int) ($row->active_orders ?? 0),
                'order_id' => $row ? (int) $row->order_id : null,
                'reservation' => $reservation,
            ]];
        });
    }

    /**
     * AC-23: runs a write that switches a table off or deletes it, only while the table is Kosong. The look and the
     * write share the row lock a table order takes, so neither can pass the other. `$when` false just writes.
     */
    public function retire(DiningTable $table, Closure $write, bool $when = true): void
    {
        if (! $when) {
            $write();

            return;
        }

        DB::transaction(function () use ($table, $write) {
            // First, so the status below is read after whoever held the lock has committed.
            DiningTable::whereKey($table->id)->lockForUpdate()->first();
            $this->assertCanRetire($table);
            $write();
        });
    }

    /** Refuses a Terisi or Dipesan table, naming the order or the reservation. Call it under the table's row lock. */
    public function assertCanRetire(DiningTable $table): void
    {
        $state = $this->forTables(collect([$table]))[$table->id];

        if ($state['status'] === 'free') {
            return;
        }

        throw ValidationException::withMessages(['table' => $state['status'] === 'occupied'
            ? __('messages.dine_tables.in_use_order', ['order' => $state['order_id']])
            : __('messages.dine_tables.in_use_reservation', ['reservation' => $state['reservation']['id'], 'time' => $state['reservation']['time']])]);
    }

    /**
     * The single hook for reservations (#431, wired by #443): per table id, the booked reservation that starts within
     * 30 minutes of `$now`, with its start as `H:i`.
     *
     * @param  Collection<int, int>  $tableIds
     * @return Collection<int, array{id: int, time: string}>
     */
    protected function reservations(Collection $tableIds, CarbonInterface $now): Collection
    {
        return collect(); // shortcut: no table is reserved until #443 reads #431's reservations here
    }
}
