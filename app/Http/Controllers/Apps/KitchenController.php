<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\KitchenItem;
use App\Models\Transaction;
use App\Models\Warehouse;
use App\Services\KitchenItemService;
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
        // Open items (and ones cancelled in the last 2 minutes) of the current outlet, oldest first. Release rows from the #236 backfill have no accepted_at.
        // Every warehouse of the outlet: a shift may sell from any of them, not only the first one.
        $items = $outlet
            ? KitchenItem::onDisplay()
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

    public function start(Request $request, KitchenItem $kitchenItem, KitchenItemService $service)
    {
        $this->ensureOutlet($request, $kitchenItem->warehouse_id);
        $service->start($kitchenItem, $request->user());

        return back();
    }

    public function ready(Request $request, KitchenItem $kitchenItem, KitchenItemService $service)
    {
        $this->ensureOutlet($request, $kitchenItem->warehouse_id);
        $service->ready($kitchenItem, $request->user());

        return back();
    }

    public function allReady(Request $request, Transaction $transaction, KitchenItemService $service)
    {
        $this->ensureOutlet($request, $transaction->warehouse_id);
        $service->allReady($transaction, $request->user());

        return back();
    }

    /** The display only works on the current outlet's items. */
    private function ensureOutlet(Request $request, int $warehouseId): void
    {
        $outlet = $this->outletAccessService->activeOutlet($request);

        // Any warehouse of the outlet, as on the display: a shift may sell from any of them.
        abort_unless($outlet && Warehouse::whereKey($warehouseId)->where('outlet_id', $outlet->id)->exists(), 403);
    }
}
