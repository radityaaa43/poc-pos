<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\KitchenItem;
use App\Services\OutletAccessService;
use App\Support\Kitchen\KitchenOrders;
use Illuminate\Http\Request;
use Inertia\Inertia;

class KitchenController extends Controller
{
    public function __construct(protected readonly OutletAccessService $outletAccessService) {}

    public function index(Request $request)
    {
        $outlet = $this->outletAccessService->activeOutlet($request);
        // Open items of the current outlet, oldest first. Release rows from the #236 backfill have no accepted_at.
        // Every warehouse of the outlet: a shift may sell from any of them, not only the first one.
        $items = $outlet
            ? KitchenItem::open()
                ->inOutlet($outlet->id)
                ->with(KitchenOrders::RELATIONS)
                ->orderByRaw('COALESCE(accepted_at, created_at)')
                ->orderBy('id')
                ->get()
            : collect();

        return Inertia::render('Dashboard/Kitchen/Index', [
            'orders' => KitchenOrders::fromItems($items),
            'outlet' => $outlet?->only('id', 'name'),
        ]);
    }
}
