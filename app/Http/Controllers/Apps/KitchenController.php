<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\KitchenItem;
use App\Models\Warehouse;
use App\Services\OutletAccessService;
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
                ->whereIn('warehouse_id', Warehouse::where('outlet_id', $outlet->id)->select('id'))
                ->with([
                    'transaction:id,invoice,order_type,payment_status,created_at',
                    'transaction.dineOrder:id,transaction_id,dine_table_id',
                    'transaction.dineOrder.table:id,name',
                    'product:id,title',
                ])
                ->orderByRaw('COALESCE(accepted_at, created_at)')
                ->orderBy('id')
                ->get()
            : collect();

        // groupBy keeps first-seen order, so the order with the oldest item comes first.
        $orders = $items->groupBy('transaction_id')->map(function ($lines) {
            $first = $lines->first();
            $transaction = $first->transaction;
            $dineOrder = $transaction->dineOrder;

            return [
                'id' => $transaction->id,
                'number' => $dineOrder ? $dineOrder->table?->name : $transaction->invoice,
                'type' => $dineOrder ? 'dine_in' : $transaction->order_type,
                'unpaid' => $transaction->payment_status !== 'paid',
                'since' => $first->accepted_at ?? $first->created_at,
                'items' => $lines->map(fn (KitchenItem $item) => [
                    'id' => $item->id,
                    'title' => $item->product?->title,
                    'qty' => $item->qty,
                    'note' => $item->note ?? null, // the column arrives with a later #236 change
                    'status' => $item->status,
                ])->values(),
            ];
        })->values();

        return Inertia::render('Dashboard/Kitchen/Index', [
            'orders' => $orders,
            'outlet' => $outlet?->only('id', 'name'),
        ]);
    }
}
