<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Setting;
use App\Services\CashierShiftService;
use App\Services\OutletAccessService;
use App\Services\PosTableOrderService;
use App\Services\TableStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** The POS "Pesanan meja" list (#426, REQ-009 AC-01, AC-04): the outlet's active tables and what can be done at each. */
class PosTableListController extends Controller
{
    public function __invoke(
        Request $request,
        TableStatusService $tableStatus,
        PosTableOrderService $tableOrders,
        CashierShiftService $shifts,
        OutletAccessService $outletAccess,
    ): JsonResponse {
        $cashierId = $request->user()->id;
        // The outlet an order would be placed in: the open shift's, as PosTableOrderService reads it.
        $shift = $shifts->getActiveShiftForUser($cashierId);
        $outlet = $shift ? $shift->warehouse?->outlet : $outletAccess->activeOutlet($request);

        $tables = DiningTable::with('area:id,name')->active()->forOutlet($outlet)->orderBy('dine_area_id')->orderBy('sort_order')->get();
        $states = $tableStatus->forTables($tables);
        // What an order can be added to: one running order per bill (an order and its add-ons share one).
        $running = DineOrder::with('transaction:id,parent_transaction_id')
            ->whereIn('dine_table_id', $tables->modelKeys())
            ->whereIn('status', DineOrder::IN_PROGRESS)
            ->orderBy('id')
            ->get()
            ->unique(fn (DineOrder $order) => data_get($order, 'transaction.parent_transaction_id') ?? $order->transaction_id)
            ->groupBy('dine_table_id');
        $carts = Cart::where('cashier_id', $cashierId)->active()->get();

        // Field by field: a table's QR token lets anyone order, so it never leaves here.
        return response()->json([
            'tables' => $tables->map(fn (DiningTable $table) => [
                'id' => $table->id,
                'name' => $table->name,
                'area_name' => $table->area?->name,
                'capacity' => $table->capacity,
                ...Arr::only($states[$table->id], ['status', 'open_bills']),
                'orders' => $running->get($table->id, collect())->map(fn (DineOrder $order) => $order->only(['id', 'status', 'item_count']))->values(),
            ]),
            'payLaterEnabled' => $outlet && Setting::getBoolForOutlet('pay_later_enabled', $outlet),
            'cartTotal' => $tableOrders->total($tableOrders->lines($carts, $outlet)),
        ]);
    }
}
