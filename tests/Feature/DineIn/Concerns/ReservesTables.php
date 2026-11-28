<?php

namespace Tests\Feature\DineIn\Concerns;

use App\Models\DiningTable;
use App\Services\TableStatusService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

trait ReservesTables
{
    /** Stands in for #431's reservations behind the one hook (#426 TC-2): each given table is booked, reservation 5 at 19:00. */
    private function reserve(DiningTable ...$tables): void
    {
        $this->app->instance(TableStatusService::class, new class(array_column($tables, 'id')) extends TableStatusService
        {
            /** @param array<int, int> $booked */
            public function __construct(private array $booked) {}

            protected function reservations(Collection $tableIds, CarbonInterface $now): Collection
            {
                return $tableIds->intersect($this->booked)->mapWithKeys(fn (int $id) => [$id => ['id' => 5, 'time' => '19:00']]);
            }
        });
    }
}
