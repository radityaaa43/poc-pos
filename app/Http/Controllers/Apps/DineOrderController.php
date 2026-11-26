<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\DineOrder;
use App\Models\KitchenItem;
use App\Models\Outlet;
use App\Services\DineOrderService;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DineOrderController extends Controller
{
    public function __construct(
        protected DineOrderService $service,
        protected readonly OutletAccessService $outletAccessService,
    ) {}

    public function index(Request $request)
    {
        $includeLegacy = Outlet::active()->count() <= 1;
        $outletIds = $request->user()->isSuperAdmin()
            ? null
            : $this->outletAccessService->accessibleOutlets($request->user())->pluck('id')->all();

        // Fulfillment queue: new orders, accepted ones waiting for payment, the kitchen (paid accepted, preparing) and the handover (ready).
        // The UI splits them by payment_status; an unpaid order is also an Open Bill on the POS.
        $orders = DineOrder::with(['table.area', 'items.product', 'transaction:id,payment_status'])
            ->whereIn('status', [DineOrder::STATUS_SUBMITTED, DineOrder::STATUS_ACCEPTED, DineOrder::STATUS_PREPARING, DineOrder::STATUS_READY])
            ->when($outletIds !== null, function ($query) use ($outletIds, $includeLegacy) {
                $query->where(function ($query) use ($outletIds, $includeLegacy) {
                    $query->whereHas('table.area', fn ($area) => $area->whereIn('outlet_id', $outletIds));
                    if ($includeLegacy) {
                        $query->orWhereHas('table.area', fn ($area) => $area->whereNull('outlet_id'));
                    }
                });
            })
            ->latest()
            ->get();

        // Orders the kitchen display tracks hand over there; one without kitchen items (from before the display, or nothing to make)
        // keeps its Serahkan on this page, its only exit. One query for the page, not one per order.
        $tracked = KitchenItem::whereIn('transaction_id', $orders->pluck('transaction_id')->filter())->distinct()->pluck('transaction_id');
        $orders->each(fn (DineOrder $order) => $order->setAttribute('has_kitchen_items', $tracked->contains($order->transaction_id)));

        return Inertia::render('Dashboard/DineIn/Orders/Index', [
            'orders' => $orders,
        ]);
    }

    public function accept(Request $request, DineOrder $dineOrder)
    {
        $this->ensureOutletAccess($request, $dineOrder);
        $this->service->accept($dineOrder);

        return back()->with('success', 'Pesanan diterima dan siap diproses di kasir.');
    }

    public function prepare(Request $request, DineOrder $dineOrder)
    {
        $this->ensureOutletAccess($request, $dineOrder);
        $this->service->startPreparing($dineOrder);

        return back()->with('success', 'Pesanan mulai dibuat.');
    }

    public function ready(Request $request, DineOrder $dineOrder)
    {
        $this->ensureOutletAccess($request, $dineOrder);
        $this->service->markReady($dineOrder);

        return back()->with('success', 'Pesanan ditandai siap.');
    }

    public function serve(Request $request, DineOrder $dineOrder)
    {
        $this->ensureOutletAccess($request, $dineOrder);
        $this->service->serve($dineOrder);

        return back()->with('success', 'Pesanan ditandai sudah diserahkan.');
    }

    public function pay(Request $request, DineOrder $dineOrder)
    {
        $this->ensureOutletAccess($request, $dineOrder);
        $validated = $request->validate([
            'tenders' => ['required', 'array'],
            'tenders.*' => ['array'],
        ]);

        $transaction = $this->service->pay($dineOrder, $request->user(), $validated['tenders']);

        return to_route('transactions.print', $transaction->invoice)
            ->with('success', 'Pembayaran pesanan dine-in berhasil.');
    }

    public function reject(Request $request, DineOrder $dineOrder)
    {
        $this->ensureOutletAccess($request, $dineOrder);
        $this->service->reject($dineOrder, $request->input('reason'));

        return back()->with('success', 'Pesanan ditolak.');
    }

    protected function ensureOutletAccess(Request $request, DineOrder $dineOrder): void
    {
        $user = $request->user();
        if ($user->isSuperAdmin()) {
            return;
        }

        $accessibleOutletIds = $this->outletAccessService->accessibleOutlets($user)->pluck('id');

        // Legacy / unassigned users: defer to the service-level shift-vs-table outlet guard.
        if ($accessibleOutletIds->isEmpty()) {
            return;
        }

        $outletId = $dineOrder->table?->area?->outlet_id;

        if ($outletId === null) {
            abort_unless(Outlet::active()->count() <= 1, 404);

            return;
        }

        abort_unless($accessibleOutletIds->contains((int) $outletId), 404);
    }
}
