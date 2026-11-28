<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Http\Requests\DineReservationRequest;
use App\Models\DineReservation;
use App\Models\DiningTable;
use App\Models\Outlet;
use App\Services\DineReservationService;
use App\Services\OutletAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DineReservationController extends Controller
{
    public function __construct(
        protected readonly OutletAccessService $outletAccess,
        protected readonly DineReservationService $reservations,
    ) {}

    public function index(Request $request)
    {
        $outlet = $this->activeOutlet($request);
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? now()->toDateString();
        $day = Carbon::parse($date)->startOfDay();

        // Today also lists the unmarked bookings of earlier days: their tables stay reserved until someone marks them.
        $rows = DineReservation::with('diningTable.area')
            ->where('outlet_id', $outlet->id)
            ->where('reserved_at', '<', $day->copy()->addDay())
            ->where(fn ($query) => $query->where('reserved_at', '>=', $day)
                ->when($day->isToday(), fn ($query) => $query->orWhere('status', 'booked')))
            ->orderBy('reserved_at')
            ->get();

        return Inertia::render('Dashboard/DineIn/Reservations/Index', [
            'date' => $date,
            // The only payload that carries the phone number: this page is behind dine-reservations-manage.
            'reservations' => $rows->map(fn (DineReservation $reservation) => [
                ...$reservation->only(['id', 'guest_name', 'phone', 'party_size', 'dine_table_id', 'note', 'status']),
                // Strings in the app timezone: an ISO date would be shifted by the browser.
                'date' => $reservation->reserved_at->format('Y-m-d'),
                'time' => $reservation->reserved_at->format('H:i'),
                // Both null when the table has been deleted since.
                'table_name' => $reservation->diningTable?->name,
                'area_name' => $reservation->diningTable?->area?->name,
            ]),
            'tables' => DiningTable::active()->forOutlet($outlet)->with('area')
                ->orderBy('dine_area_id')->orderBy('sort_order')->get()
                ->map(fn (DiningTable $table) => [
                    'id' => $table->id, 'name' => $table->name, 'area_name' => $table->area?->name,
                ]),
        ]);
    }

    public function store(DineReservationRequest $request)
    {
        $this->reservations->create($this->activeOutlet($request), $request->validated(), $request->user());

        return back();
    }

    public function update(DineReservationRequest $request, DineReservation $dineReservation)
    {
        $outlet = $this->owningOutlet($request, $dineReservation);
        $this->reservations->update($dineReservation, $outlet, $request->validated(), $request->user());

        return back();
    }

    public function mark(Request $request, DineReservation $dineReservation)
    {
        $this->owningOutlet($request, $dineReservation);
        $validated = $request->validate(['status' => ['required', Rule::in(DineReservation::MARKS)]]);
        $this->reservations->mark($dineReservation, $validated['status'], $request->user());

        return back();
    }

    private function activeOutlet(Request $request): Outlet
    {
        return $this->outletAccess->activeOutlet($request) ?? abort(403);
    }

    /** A reservation of another outlet does not exist for this user. */
    private function owningOutlet(Request $request, DineReservation $reservation): Outlet
    {
        $outlet = $this->activeOutlet($request);
        abort_unless((int) $reservation->outlet_id === (int) $outlet->id, 404);

        return $outlet;
    }
}
