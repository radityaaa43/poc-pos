<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKitchenPrintStationRequest;
use App\Http\Requests\StoreKitchenTicketPrintsRequest;
use App\Models\KitchenPrintStation;
use App\Services\KitchenTicketService;
use App\Services\OutletAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class KitchenPrintStationController extends Controller
{
    public function __construct(
        protected readonly OutletAccessService $outletAccessService,
        protected readonly KitchenTicketService $ticketService,
    ) {}

    public function store(StoreKitchenPrintStationRequest $request): JsonResponse
    {
        $outlet = $this->outletAccessService->activeOutlet($request)
            ?? throw ValidationException::withMessages(['name' => 'Pilih outlet terlebih dahulu']);

        $station = KitchenPrintStation::create(['outlet_id' => $outlet->id, 'name' => $request->validated('name')]);

        return response()->json($station->only('id', 'name'), 201);
    }

    public function tickets(Request $request, KitchenPrintStation $station): JsonResponse
    {
        $this->authorizeStation($request, $station);

        return response()->json(['tickets' => $this->ticketService->pending($station)]);
    }

    public function prints(StoreKitchenTicketPrintsRequest $request, KitchenPrintStation $station): Response
    {
        $this->authorizeStation($request, $station);

        $this->ticketService->markPrinted($station, $request->validated('item_ids'));

        return response()->noContent();
    }

    /** A station belongs to one outlet: another outlet's staff get 404, as if it did not exist. */
    private function authorizeStation(Request $request, KitchenPrintStation $station): void
    {
        $outlet = $this->outletAccessService->activeOutlet($request);

        abort_unless($outlet && $outlet->id === (int) $station->outlet_id, 404);
    }
}
