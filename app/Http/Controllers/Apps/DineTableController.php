<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\DineArea;
use App\Models\DiningTable;
use App\Services\OutletAccessService;
use App\Services\TableStatusService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class DineTableController extends Controller
{
    public function __construct(protected readonly OutletAccessService $outletAccess) {}

    public function index(Request $request, TableStatusService $tableStatus)
    {
        $outlet = $this->outletAccess->activeOutlet($request);
        $query = DiningTable::with('area')->forOutlet($outlet);

        if ($request->filled('area_id')) {
            $query->where('dine_area_id', $request->area_id);
        }

        if ($request->input('is_active') === 'active') {
            $query->where('is_active', true);
        } elseif ($request->input('is_active') === 'inactive') {
            $query->where('is_active', false);
        }

        $tables = $query->orderBy('dine_area_id')->orderBy('sort_order')->get();
        $states = $tableStatus->forTables($tables);
        $areas = DineArea::whereNull('outlet_id')
            ->when($outlet, fn ($query) => $query->orWhere('outlet_id', $outlet->id))
            ->orderBy('sort_order')->get();

        return Inertia::render('Dashboard/DineIn/Tables/Index', [
            'tables' => $tables->map(fn (DiningTable $table) => $table->toArray() + Arr::only($states[$table->id], ['status', 'open_bills'])),
            'areas' => $areas,
            'filters' => [
                'area_id' => $request->area_id,
                'is_active' => $request->input('is_active', 'all'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'dine_area_id' => ['nullable', 'exists:dine_areas,id'],
            'name' => ['required', 'string', 'max:100'],
            'capacity' => ['integer', 'min:0'],
            'pos_x' => ['integer', 'min:0', 'max:24'],
            'pos_y' => ['integer', 'min:0', 'max:14'],
            'shape' => [Rule::in(['circle', 'square'])],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);

        $validated['token'] = (string) Str::uuid();
        $this->validateAreaAccess($validated['dine_area_id'] ?? null, $request);

        DiningTable::create($validated);

        return back()->with('success', 'Meja berhasil ditambahkan.');
    }

    public function update(Request $request, DiningTable $dineTable, TableStatusService $tableStatus)
    {
        abort_unless($this->ownsTable($dineTable, $request), 404);
        $validated = $request->validate([
            'dine_area_id' => ['nullable', 'exists:dine_areas,id'],
            'name' => ['required', 'string', 'max:100'],
            'capacity' => ['integer', 'min:0'],
            'pos_x' => ['integer', 'min:0', 'max:24'],
            'pos_y' => ['integer', 'min:0', 'max:14'],
            'shape' => [Rule::in(['circle', 'square'])],
            'sort_order' => ['integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
        $this->validateAreaAccess($validated['dine_area_id'] ?? null, $request);

        // AC-23: only switching a table off is refused; a busy one can still be edited.
        $switchesOff = $dineTable->is_active && ! ($validated['is_active'] ?? true);
        $tableStatus->retire($dineTable, fn () => $dineTable->update($validated), when: $switchesOff);

        return back()->with('success', 'Meja berhasil diperbarui.');
    }

    public function destroy(DiningTable $dineTable, TableStatusService $tableStatus)
    {
        abort_unless($this->ownsTable($dineTable, request()), 404);
        $tableStatus->retire($dineTable, fn () => $dineTable->delete());

        return back()->with('success', 'Meja berhasil dihapus.');
    }

    public function qr(DiningTable $dineTable)
    {
        abort_unless($this->ownsTable($dineTable, request()), 404);
        $url = config('app.url').'/dine/'.$dineTable->token;

        // SVG like transactions.qr: the library's PNG back end needs ext-imagick, which the app image lacks.
        $svg = QrCode::format('svg')
            ->size(300)
            ->margin(2)
            ->generate($url);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml',
            'Content-Disposition' => 'inline; filename="qr-'.$dineTable->id.'.svg"',
        ]);
    }

    protected function ownsTable(DiningTable $table, Request $request): bool
    {
        $outlet = $this->outletAccess->activeOutlet($request);
        $area = $table->area;

        return $area?->outlet_id === null || ($outlet && (int) $area->outlet_id === (int) $outlet->id);
    }

    protected function validateAreaAccess(?int $areaId, Request $request): void
    {
        if (! $areaId) {
            return;
        }

        $area = DineArea::findOrFail($areaId);
        abort_unless($this->ownsArea($area, $request), 404);
    }

    protected function ownsArea(DineArea $area, Request $request): bool
    {
        $outlet = $this->outletAccess->activeOutlet($request);

        return $area->outlet_id === null || ($outlet && (int) $area->outlet_id === (int) $outlet->id);
    }
}
