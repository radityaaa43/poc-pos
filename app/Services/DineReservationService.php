<?php

namespace App\Services;

use App\Models\DineReservation;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Table reservations (REQ-009 AC-16, AC-18). In every transaction the locking reads come first: on MySQL the
 * first plain read fixes what the transaction sees, and it must see what the winner of a lock committed.
 */
class DineReservationService
{
    public function __construct(
        protected readonly AuditLogService $auditLogService,
    ) {}

    /** @param array<string, mixed> $data validated by DineReservationRequest */
    public function create(Outlet $outlet, array $data, User $by): DineReservation
    {
        return DB::transaction(function () use ($outlet, $data, $by) {
            $table = $this->lockTable($outlet, (int) $data['dine_table_id']);
            $reservation = new DineReservation($this->attributes($data) + ['outlet_id' => $outlet->id, 'status' => 'booked']);
            $this->refuseClash($reservation);
            $reservation->save();

            $this->auditLogService->log(
                event: 'dine_reservation.created',
                module: 'dine_reservations',
                auditable: $reservation,
                description: 'Reservasi meja dicatat.',
                after: $this->snapshot($reservation, $table) + ['phone_changed' => true],
                actor: $by
            );

            return $reservation;
        });
    }

    /** @param array<string, mixed> $data validated by DineReservationRequest */
    public function update(DineReservation $reservation, Outlet $outlet, array $data, User $by): void
    {
        DB::transaction(function () use ($reservation, $outlet, $data, $by) {
            $reservation = $this->lockBooked($reservation);
            $table = $this->lockTable($outlet, (int) $data['dine_table_id']);
            $before = $this->snapshot($reservation, $reservation->diningTable);

            $reservation->fill($this->attributes($data));
            if (! $reservation->isDirty()) {
                return;
            }
            $this->refuseClash($reservation);
            $phoneChanged = $reservation->isDirty('phone');
            $reservation->save();

            $this->auditLogService->log(
                event: 'dine_reservation.updated',
                module: 'dine_reservations',
                auditable: $reservation,
                description: 'Reservasi meja diubah.',
                before: $before,
                after: $this->snapshot($reservation, $table) + ($phoneChanged ? ['phone_changed' => true] : []),
                actor: $by
            );
        });
    }

    public function mark(DineReservation $reservation, string $status, User $by): void
    {
        DB::transaction(function () use ($reservation, $status, $by) {
            $reservation = $this->lockBooked($reservation);
            $reservation->update(['status' => $status]);

            $this->auditLogService->log(
                event: 'dine_reservation.marked',
                module: 'dine_reservations',
                auditable: $reservation,
                description: 'Status reservasi meja ditandai.',
                before: ['status' => 'booked'],
                after: ['status' => $status],
                meta: $this->snapshot($reservation, $reservation->diningTable),
                actor: $by
            );
        });
    }

    /** Only a booked reservation may change, so of two staff acting at once the first wins. */
    private function lockBooked(DineReservation $reservation): DineReservation
    {
        $locked = DineReservation::lockForUpdate()->findOrFail($reservation->id);

        if ($locked->status !== 'booked') {
            throw ValidationException::withMessages(['status' => __('messages.dine_reservations.not_booked')]);
        }

        return $locked;
    }

    /** The table row is what every booking of a table shares: locking it queues the competing ones. */
    private function lockTable(Outlet $outlet, int $tableId): DiningTable
    {
        // By key alone, so MySQL locks exactly this row; whether the outlet may book it is asked afterwards.
        DiningTable::whereKey($tableId)->lockForUpdate()->first();

        return DiningTable::active()->forOutlet($outlet)->findOrFail($tableId);
    }

    /** Booked and arrived hold the table; cancelled and no-show free it. Exactly the minimum gap is allowed. */
    private function refuseClash(DineReservation $reservation): void
    {
        $gap = DineReservation::MIN_GAP_MINUTES;
        $other = DineReservation::where('dine_table_id', $reservation->dine_table_id)
            ->whereIn('status', ['booked', 'arrived'])
            ->where('reserved_at', '>', $reservation->reserved_at->copy()->subMinutes($gap))
            ->where('reserved_at', '<', $reservation->reserved_at->copy()->addMinutes($gap))
            ->when($reservation->exists, fn ($query) => $query->whereKeyNot($reservation->id))
            ->orderBy('reserved_at')
            ->first();

        if ($other) {
            throw ValidationException::withMessages(['reserved_time' => __('messages.dine_reservations.clash', [
                'name' => $other->guest_name,
                'time' => $other->reserved_at->format('d/m/Y H:i'),
            ])]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'dine_table_id' => $data['dine_table_id'],
            'guest_name' => $data['guest_name'],
            'phone' => $data['phone'],
            'party_size' => $data['party_size'],
            'reserved_at' => Carbon::parse($data['reserved_date'].' '.$data['reserved_time']),
            'note' => $data['note'] ?? null,
        ];
    }

    /**
     * What the audit log keeps. The phone number is never part of it, only the phone_changed flag (REQ-009 BR-1).
     *
     * @return array<string, mixed>
     */
    private function snapshot(DineReservation $reservation, DiningTable $table): array
    {
        return [
            'guest_name' => $reservation->guest_name,
            'party_size' => $reservation->party_size,
            'table' => $table->name,
            'reserved_at' => $reservation->reserved_at->format('Y-m-d H:i'),
            'note' => $reservation->note,
        ];
    }
}
