<?php

namespace App\Http\Controllers;

use App\Http\Requests\StartDineOrderPaymentRequest;
use App\Models\DineOrder;
use App\Services\DineOrderOnlinePaymentService;

class DineOrderPaymentController extends Controller
{
    /** A guest starts (or restarts) the online payment of an accepted order's open bill. */
    public function __invoke(StartDineOrderPaymentRequest $request, DineOrderOnlinePaymentService $service, string $accessToken)
    {
        $order = DineOrder::where('access_token', $accessToken)->firstOrFail();

        return response()->json([
            'payment' => $service->start($order, $request->validated('method') ?? DineOrderOnlinePaymentService::METHOD_QRIS),
        ]);
    }
}
