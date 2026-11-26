<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\KitchenStation;
use App\Models\Outlet;
use App\Models\Warehouse;
use App\Services\KitchenSpeedReportService;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class KitchenSpeedReportController extends Controller
{
    /**
     * Display the kitchen speed report (#243).
     */
    public function index(Request $request, OutletAccessService $outletAccessService, KitchenSpeedReportService $service)
    {
        $user = $request->user();
        $outlets = $outletAccessService->accessibleOutlets($user);
        $input = $request->validate([
            'outlet_id' => ['nullable', Rule::in($outlets->pluck('id'))],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            // No deleted_at clause on purpose: a deleted station keeps its history and can still be picked (#346).
            'station_id' => ['nullable', 'integer', Rule::exists('kitchen_stations', 'id')],
        ]);

        /** @var Outlet|null $outlet accessibleOutlets() returns an untyped Eloquent collection of outlets */
        $outlet = isset($input['outlet_id'])
            ? $outlets->firstWhere('id', (int) $input['outlet_id'])
            : $outletAccessService->activeOutlet($request);
        // The outlet was checked against the user's access above; a deactivated warehouse of it still holds past data.
        $warehouseIds = $outlet
            ? Warehouse::where('outlet_id', $outlet->id)->pluck('id')->all()
            : $outletAccessService->warehousesFor($user)->pluck('id')->all();

        // "Outlet local time" is the app timezone: outlets have no timezone of their own.
        $timezone = config('app.timezone');
        $startDate = $input['start_date'] ?? now($timezone)->startOfMonth()->toDateString();
        $endDate = $input['end_date'] ?? now($timezone)->endOfMonth()->toDateString();
        $stationId = isset($input['station_id']) ? (int) $input['station_id'] : null;
        $report = $service->build(
            $warehouseIds,
            Carbon::parse($startDate, $timezone)->startOfDay(),
            Carbon::parse($endDate, $timezone)->endOfDay(),
            $stationId,
        );

        return Inertia::render('Dashboard/Reports/KitchenSpeed', [
            'filters' => [
                'outlet_id' => $outlet?->id, 'start_date' => $startDate, 'end_date' => $endDate,
                'station_id' => $stationId,
            ],
            // Active stations first (the default one on top), then the deleted ones: the select labels those.
            'stations' => KitchenStation::withTrashed()
                ->orderByRaw('deleted_at IS NOT NULL')->ordered()
                ->get(['id', 'name', 'deleted_at'])
                ->map(fn (KitchenStation $station) => ['id' => $station->id, 'name' => $station->name, 'deleted' => $station->trashed()])
                ->values(),
            'outlets' => $outlets->map(fn ($accessible) => $accessible->only(['id', 'code', 'name']))->values(),
            ...$report,
        ]);
    }
}
