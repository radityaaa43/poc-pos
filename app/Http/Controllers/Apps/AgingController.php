<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Services\OutletAccessService;
use App\Services\ReceivableService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AgingController extends Controller
{
    public function index(Request $request, OutletAccessService $access, ReceivableService $receivables)
    {
        $user = $request->user();
        $outlets = $access->accessibleOutlets($user);

        // An outlet the user may not see is refused; absent means every outlet they may see.
        $data = $request->validate([
            'outlet_id' => ['nullable', 'integer', Rule::in($outlets->modelKeys())],
        ]);

        $warehouses = $access->warehousesFor($user);
        if (! empty($data['outlet_id'])) {
            $warehouses = $warehouses->where('outlet_id', $data['outlet_id']);
        }

        return Inertia::render('Dashboard/Aging/Index', [
            // REQ-007 "Piutang macet": the last 12 months' overdue, at the warehouse scope the user may see.
            'overdueTrend' => $receivables->overdueTrend($warehouses->pluck('id')),
            'overdueTrendOutlets' => $outlets->map->only(['id', 'name'])->values(),
            'overdueTrendOutletId' => $data['outlet_id'] ?? null,
            'overdueTrendAsOf' => now()->toDateString(),
        ]);
    }
}
