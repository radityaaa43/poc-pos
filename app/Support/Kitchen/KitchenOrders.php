<?php

namespace App\Support\Kitchen;

use Illuminate\Support\Collection;

/** Groups kitchen items into orders; shared by the display and the kitchen tickets so both show the same number. */
class KitchenOrders
{
    /** Relations fromItems() reads, with only the columns it needs (no prices). */
    public const RELATIONS = [
        'transaction:id,invoice,order_type,payment_status,created_at',
        'transaction.dineOrder:id,transaction_id,dine_table_id',
        'transaction.dineOrder.table:id,name',
        'product:id,title',
        'detail.modifiers:id,transaction_detail_id,option_name', // snapshot order: the options the guest or cashier picked
        'kitchenStation:id,name', // withTrashed: an item of a deleted station keeps its old name
    ];

    /** $items: kitchen items in queue order, with RELATIONS loaded. */
    // Untyped on purpose: typing it makes larastan flag every relation read below (the controller code had no baseline entries).
    public static function fromItems($items): Collection
    {
        // groupBy keeps first-seen order, so the order with the oldest item comes first.
        return $items->groupBy('transaction_id')->map(function ($lines) {
            $first = $lines->first();
            $transaction = $first->transaction;
            $dineOrder = $transaction->dineOrder;

            return [
                'id' => $transaction->id,
                'number' => $dineOrder ? $dineOrder->table?->name : $transaction->invoice,
                'type' => $dineOrder ? 'dine_in' : $transaction->order_type,
                'unpaid' => $transaction->payment_status !== 'paid',
                'since' => $first->accepted_at ?? $first->created_at,
                'items' => $lines->map(fn ($item) => [
                    'id' => $item->id,
                    'title' => $item->product?->title,
                    'qty' => $item->qty,
                    'note' => $item->note ?? null, // the column arrives with a later #236 change
                    'modifiers' => $item->detail?->modifiers->pluck('option_name')->values()->all() ?? [], // free options included
                    'status' => $item->status,
                    'ready_at' => $item->ready_at,
                    'station_id' => $item->kitchen_station_id,
                    'station' => $item->kitchenStation?->name,
                ])->values(),
            ];
        })->values();
    }
}
