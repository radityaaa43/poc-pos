<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\Warehouse;
use App\Services\OutletAccessService;
use App\Services\QrPaymentReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class QrPaymentReportController extends Controller
{
    /** REQ-005 success metrics: the table-QR online-payment figures for one outlet and month. */
    public function index(Request $request, OutletAccessService $outletAccessService, QrPaymentReportService $service)
    {
        $user = $request->user();
        $outlets = $outletAccessService->accessibleOutlets($user);
        $input = $request->validate([
            'outlet_id' => ['nullable', Rule::in($outlets->pluck('id'))],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        /** @var Outlet|null $outlet accessibleOutlets() returns an untyped Eloquent collection */
        $outlet = isset($input['outlet_id'])
            ? $outlets->firstWhere('id', (int) $input['outlet_id'])
            : $outletAccessService->activeOutlet($request);
        // Deactivated warehouses of the outlet still hold past bills.
        $warehouseIds = $outlet
            ? Warehouse::where('outlet_id', $outlet->id)->pluck('id')->all()
            : $outletAccessService->warehousesFor($user)->pluck('id')->all();

        $timezone = config('app.timezone');
        $month = isset($input['month'])
            ? Carbon::createFromFormat('Y-m', $input['month'], $timezone)->startOfMonth()
            : now($timezone)->startOfMonth();

        return Inertia::render('Dashboard/Reports/QrPayment', [
            'filters' => ['outlet_id' => $outlet?->id, 'month' => $month->format('Y-m')],
            'outlets' => $outlets->map->only(['id', 'code', 'name'])->values(),
            'summary' => $service->monthSummary($warehouseIds, $month),
        ]);
    }
}
