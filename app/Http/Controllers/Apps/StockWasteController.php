<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Http\Requests\StockWasteReasonRequest;
use App\Http\Requests\StoreStockWasteRequest;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\StockWaste;
use App\Models\Warehouse;
use App\Services\InventoryCostService;
use App\Services\OutletAccessService;
use App\Services\StockWasteService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StockWasteController extends Controller
{
    public function __construct(
        private readonly StockWasteService $stockWasteService,
        private readonly OutletAccessService $outletAccessService,
        private readonly InventoryCostService $inventoryCostService,
    ) {}

    public function index(Request $request): Response
    {
        // The management list: records of a deactivated warehouse stay visible.
        $warehouses = $this->outletAccessService->warehousesFor($request->user(), true);
        $filters = ['status' => $request->input('status'), 'warehouse_id' => $request->input('warehouse_id')];
        $product = fn ($query) => $query->select('products.id', 'products.title')->withUnitSymbol();

        $wastes = StockWaste::query()
            ->whereIn('warehouse_id', $warehouses->modelKeys())
            ->when($filters['status'], fn ($query, $status) => $query->where('status', $status))
            ->when($filters['warehouse_id'], fn ($query, $id) => $query->where('warehouse_id', $id))
            ->with(['warehouse:id,code,name', 'creator:id,name', 'lines.product' => $product, 'moves.product' => $product])
            ->latest('id')
            ->paginate($this->perPage(15))
            ->withQueryString();

        return Inertia::render('Dashboard/StockWaste/Index', [
            'wastes' => $wastes,
            'filters' => $filters,
            'warehouses' => $warehouses->map->only(['id', 'code', 'name'])->values(),
        ]);
    }

    public function create(Request $request): Response
    {
        $warehouses = $this->outletAccessService->warehousesFor($request->user());
        $ids = $warehouses->modelKeys();
        $warehouseId = in_array($request->integer('warehouse_id'), $ids, true) ? $request->integer('warehouse_id') : ($ids[0] ?? null);

        return Inertia::render('Dashboard/StockWaste/Create', [
            'warehouses' => $warehouses->map->only(['id', 'code', 'name'])->values(),
            'warehouseId' => $warehouseId,
            'reasons' => StockWaste::REASONS,
            'items' => fn () => $warehouseId ? $this->items($warehouseId) : [],
        ]);
    }

    public function store(StoreStockWasteRequest $request): RedirectResponse
    {
        $warehouse = Warehouse::findOrFail($request->validated('warehouse_id'));
        abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 403);

        $waste = $this->stockWasteService->record($request->validated(), $request->user());

        return to_route('stock-waste.index')->with('success', __('messages.waste.'.$waste->status));
    }

    /** Another holder of stock-waste-approve approves a pending record; the stock is taken then (AC-06). */
    public function approve(Request $request, StockWaste $stockWaste): RedirectResponse
    {
        abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $stockWaste->warehouse), 403);

        $this->stockWasteService->approve($stockWaste, $request->user());

        return back()->with('success', __('messages.waste.recorded'));
    }

    public function reject(StockWasteReasonRequest $request, StockWaste $stockWaste): RedirectResponse
    {
        abort_unless($this->outletAccessService->canManageWarehouse($request->user(), $stockWaste->warehouse), 403);

        $this->stockWasteService->reject($stockWaste, $request->user(), $request->validated('reason'));

        return back()->with('success', __('messages.waste.rejected'));
    }

    /** Cancel a recorded record with a reason; the stock comes back (AC-09). */
    public function cancel(StockWasteReasonRequest $request, StockWaste $stockWaste): RedirectResponse
    {
        abort_unless($this->outletAccessService->canManageWarehouse($request->user(), $stockWaste->warehouse), 403);

        $this->stockWasteService->cancel($stockWaste, $request->user(), $request->validated('reason'));

        return back()->with('success', __('messages.waste.cancelled'));
    }

    /**
     * Raw materials and recipe menus only. `parts` is [unit cost, qty per item] of each stock product an
     * item takes, so the page prices it the way StockWasteService does.
     */
    private function items(int $warehouseId): array
    {
        $products = Product::query()
            ->where(fn ($query) => $query
                ->where(fn ($menu) => $menu->where('is_composite', true)->whereHas('components'))
                ->orWhere(fn ($raw) => $raw->where('is_composite', false)->where('is_raw_material', true)))
            ->select('products.id', 'products.title', 'products.buy_price', 'products.is_composite')
            ->withUnitSymbol()
            ->with('components')
            ->orderBy('title')
            ->get();
        $stock = ProductWarehouse::where('warehouse_id', $warehouseId)->whereIn('product_id', $products->modelKeys())->pluck('stock', 'product_id');
        $taken = $products->flatMap(fn (Product $product) => $product->is_composite ? $product->components : [$product]);
        $costs = $this->inventoryCostService->unitCosts(new Collection($taken->all()), $warehouseId);

        return $products->map(fn (Product $product) => [
            'id' => $product->id,
            'title' => $product->title,
            'unit_symbol' => $product->getAttribute('unit_symbol'),
            // A menu has no stock of its own; the server checks its ingredients.
            'stock' => $product->is_composite ? null : (int) ($stock[$product->id] ?? 0),
            'parts' => $product->is_composite
                ? $product->components->map(fn ($part) => [$costs[$part->getKey()], (float) data_get($part, 'pivot.qty')])->all()
                : [[$costs[$product->id], 1]],
        ])->all();
    }
}
