<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Services\OutletAccessService;
use App\Services\PayableAgingService;
use App\Services\ReceivableService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AgingController extends Controller
{
    public function __construct(
        protected readonly PayableAgingService $payableAgingService,
        protected readonly ReceivableService $receivableService,
        protected readonly OutletAccessService $outletAccessService,
    ) {}

    public function index(Request $request)
    {
        $warehouseIds = $this->outletAccessService->warehousesFor($request->user())->pluck('id');

        return Inertia::render('Dashboard/Aging/Index', [
            'payableAgingSummary' => $this->payableAgingService->getAgingSummary($warehouseIds),
            'receivableAgingSummary' => $this->receivableService->getAgingSummary($warehouseIds),
        ]);
    }
}
