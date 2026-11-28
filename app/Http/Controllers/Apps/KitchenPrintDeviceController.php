<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKitchenCancellationPrintsRequest;
use App\Http\Requests\StoreKitchenPrintDeviceRequest;
use App\Http\Requests\StoreKitchenTicketPrintsRequest;
use App\Models\KitchenPrintDevice;
use App\Services\KitchenTicketService;
use App\Services\OutletAccessService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class KitchenPrintDeviceController extends Controller
{
    public function __construct(
        protected readonly OutletAccessService $outletAccessService,
        protected readonly KitchenTicketService $ticketService,
    ) {}

    public function store(StoreKitchenPrintDeviceRequest $request): JsonResponse
    {
        $outlet = $this->outletAccessService->activeOutlet($request)
            ?? throw ValidationException::withMessages(['name' => 'Pilih outlet terlebih dahulu']);

        // Two devices naming a device at once both pass the validation; the unique index stops the second.
        try {
            $device = KitchenPrintDevice::create(['outlet_id' => $outlet->id, 'name' => $request->validated('name')]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => $request->messages()['name.unique']]);
        }

        return response()->json([...$device->only('id', 'name'), 'station_ids' => []], 201);
    }

    public function show(Request $request, KitchenPrintDevice $device): JsonResponse
    {
        $this->authorizeDevice($request, $device);

        return response()->json([
            'id' => $device->id,
            'name' => $device->name,
            'station_ids' => $this->ticketService->servedStationIds($device),
        ]);
    }

    public function tickets(Request $request, KitchenPrintDevice $device): JsonResponse
    {
        $this->authorizeDevice($request, $device);

        return response()->json([
            'tickets' => $this->ticketService->pending($device),
            'cancellations' => $this->ticketService->pendingCancellations($device),
        ]);
    }

    public function cancellationPrints(StoreKitchenCancellationPrintsRequest $request, KitchenPrintDevice $device): Response
    {
        $this->authorizeDevice($request, $device);

        $this->ticketService->markCancellationsPrinted($device, $request->validated('cancellation_ids'));

        return response()->noContent();
    }

    public function prints(StoreKitchenTicketPrintsRequest $request, KitchenPrintDevice $device): Response
    {
        $this->authorizeDevice($request, $device);

        $this->ticketService->markPrinted($device, $request->validated('item_ids'));

        return response()->noContent();
    }

    /** The stations a device serves (#345). An empty list means every station, so a shop without a split is unchanged. */
    public function stations(Request $request, KitchenPrintDevice $device): JsonResponse
    {
        $this->authorizeDevice($request, $device);
        $data = $request->validate([
            'station_ids' => ['present', 'array'],
            'station_ids.*' => ['integer', 'exists:kitchen_stations,id'],
        ]);
        $device->stations()->sync($data['station_ids']);

        return response()->json([
            'station_ids' => $device->stations()->pluck('kitchen_stations.id')->map(fn ($id) => (int) $id)->all(),
        ]);
    }

    /** A device belongs to one outlet: another outlet's staff get 404, as if it did not exist. */
    private function authorizeDevice(Request $request, KitchenPrintDevice $device): void
    {
        $outlet = $this->outletAccessService->activeOutlet($request);

        abort_unless($outlet && $outlet->id === (int) $device->outlet_id, 404);
    }
}
