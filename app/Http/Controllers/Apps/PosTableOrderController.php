<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\DineOrder;
use App\Services\PosTableOrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Sends the cashier's cart to a table (#426, REQ-009 AC-02, AC-03). The items come from the server cart only. */
class PosTableOrderController extends Controller
{
    public function __invoke(Request $request, PosTableOrderService $tableOrders): RedirectResponse
    {
        $data = $request->validate([
            'dine_table_id' => ['required', 'integer'],
            'payment_option' => ['required', Rule::in([DineOrder::PAY_AT_COUNTER, DineOrder::PAY_LATER])],
            'add_on_to_dine_order_id' => ['nullable', 'integer'],
        ]);

        $tableOrders->place(
            $request->user(),
            (int) $data['dine_table_id'],
            $data['payment_option'],
            $request->integer('add_on_to_dine_order_id') ?: null,
        );

        return back();
    }
}
