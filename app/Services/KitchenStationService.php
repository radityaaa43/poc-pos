<?php

namespace App\Services;

use App\Models\KitchenStation;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KitchenStationService
{
    public function __construct(
        protected readonly AuditLogService $auditLogService,
    ) {}

    /** Case-insensitive on every database (MySQL's default collation is, SQLite's is not); deleted stations do not count. */
    public function nameTaken(string $name, ?int $ignoreId = null): bool
    {
        return KitchenStation::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }

    public function create(string $name): KitchenStation
    {
        $station = $this->guardDuplicate(fn () => KitchenStation::create(['name' => $name]));

        $this->auditLogService->log(
            event: 'kitchen_station.created',
            module: 'kitchen_stations',
            auditable: $station,
            description: 'Stasiun dapur ditambahkan.',
            after: ['name' => $station->name]
        );

        return $station;
    }

    public function rename(KitchenStation $station, string $name): KitchenStation
    {
        $before = $station->name;

        if ($before === $name) {
            return $station;
        }

        $this->guardDuplicate(fn () => $station->update(['name' => $name]));

        $this->auditLogService->log(
            event: 'kitchen_station.updated',
            module: 'kitchen_stations',
            auditable: $station,
            description: 'Stasiun dapur diubah.',
            before: ['name' => $before],
            after: ['name' => $station->name]
        );

        return $station;
    }

    /**
     * Soft delete: its products fall back to the default station; kitchen items keep their station id, so
     * the name still resolves for them. The default station cannot be deleted.
     */
    public function delete(KitchenStation $station): void
    {
        DB::transaction(function () use ($station) {
            // Re-read under lock: two admins deleting at once move the products once and log once (the loser gets 404).
            $station = KitchenStation::lockForUpdate()->findOrFail($station->getKey());

            if ($station->is_default) {
                throw ValidationException::withMessages(['station' => __('messages.kitchen_stations.default_locked')]);
            }

            $productsMoved = Product::where('kitchen_station_id', $station->id)->update(['kitchen_station_id' => null]);

            // The unique key (name, deleted_key) is what frees the name for reuse once the station is deleted.
            $station->forceFill(['deleted_key' => $station->id])->save();
            $station->delete();

            $this->auditLogService->log(
                event: 'kitchen_station.deleted',
                module: 'kitchen_stations',
                auditable: $station,
                description: 'Stasiun dapur dihapus.',
                before: ['name' => $station->name],
                meta: ['products_moved' => $productsMoved]
            );
        });
    }

    /** Two requests can pass the name check together; the unique key stops the second and it gets the same 422. */
    private function guardDuplicate(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('messages.kitchen_stations.name_taken')]);
        }
    }
}
