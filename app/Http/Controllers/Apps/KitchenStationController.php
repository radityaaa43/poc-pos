<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\KitchenStation;
use App\Services\KitchenStationService;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;

class KitchenStationController extends Controller
{
    public function __construct(
        protected readonly KitchenStationService $stations,
    ) {}

    public function index()
    {
        return Inertia::render('Dashboard/Settings/KitchenStations', [
            'stations' => KitchenStation::ordered()->get(['id', 'name', 'is_default']),
        ]);
    }

    public function store(Request $request)
    {
        $this->stations->create($this->validatedName($request));

        return back();
    }

    public function update(Request $request, KitchenStation $kitchenStation)
    {
        $this->stations->rename($kitchenStation, $this->validatedName($request, $kitchenStation));

        return back();
    }

    public function destroy(KitchenStation $kitchenStation)
    {
        $this->stations->delete($kitchenStation);

        return back();
    }

    private function validatedName(Request $request, ?KitchenStation $station = null): string
    {
        return $request->validate([
            'name' => ['bail', 'required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) use ($station) {
                if ($this->stations->nameTaken($value, $station?->id)) {
                    $fail(__('messages.kitchen_stations.name_taken'));
                }
            }],
        ])['name'];
    }
}
