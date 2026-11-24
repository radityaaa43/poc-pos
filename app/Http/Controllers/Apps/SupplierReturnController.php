<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceiving;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierReturn;
use App\Services\OutletAccessService;
use App\Services\SupplierReturnService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplierReturnController extends Controller
{
    public function __construct(
        protected readonly SupplierReturnService $supplierReturnService,
        protected readonly OutletAccessService $outletAccessService,
    ) {}

    public function index(Request $request)
    {
        $filters = [
            'status' => $request->input('status'),
            'supplier' => $request->input('supplier'),
            'search' => $request->input('search'),
        ];

        $warehouseIds = $this->outletAccessService->warehousesFor($request->user())->pluck('id');
        $query = SupplierReturn::with([
            'supplier:id,name',
            'creator:id,name',
        ])->withCount('items as items_count')
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderByDesc('created_at');

        $query->when($filters['status'], fn ($q, $s) => $q->where('status', $s))
            ->when($filters['supplier'], fn ($q, $s) => $q->where('supplier_id', $s))
            ->when($filters['search'], fn ($q, $s) => $q->where('document_number', 'like', "%{$s}%"));

        $returns = $query->paginate($this->perPage())->withQueryString();
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);

        return Inertia::render('Dashboard/SupplierReturns/Index', [
            'returns' => $returns,
            'filters' => $filters,
            'suppliers' => $suppliers,
        ]);
    }

    public function create(Request $request)
    {
        $suppliers = Supplier::orderBy('name')->get(['id', 'name']);

        $products = Product::with('units:units.id,units.name,units.symbol')->orderBy('title')->get(['id', 'title', 'sku', 'buy_price', 'stock']);

        return Inertia::render('Dashboard/SupplierReturns/Create', [
            'suppliers' => $suppliers,
            // Closure: only evaluated when the page asks for it (partial reload on supplier change).
            'goodsReceivings' => fn () => $this->goodsReceivingsFor($request),
            'products' => $products,
            'warehouses' => $this->outletAccessService->warehousesFor($request->user()),
        ]);
    }

    /** The chosen supplier's receivings, each line with its unit and what can still be returned. */
    protected function goodsReceivingsFor(Request $request)
    {
        if (! $request->input('supplier_id')) {
            return collect();
        }

        return GoodsReceiving::with([
            'supplier:id,name',
            'items' => fn ($q) => $q->withSum('activeReturnItems as returned_qty', 'qty_returned'),
            'items.product:id,title,sku',
            'items.unit:id,symbol',
            'items.purchaseOrderItem:id,unit_price',
        ])->where('supplier_id', $request->input('supplier_id'))
            ->whereIn('warehouse_id', $this->outletAccessService->warehousesFor($request->user())->pluck('id'))
            ->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', ['ordered', 'partial_received', 'completed']))
            ->orderByDesc('received_at')
            ->get()
            ->each(fn ($gr) => $gr->items->each(fn ($item) => $item->qty_returnable = $item->qty_received - (int) $item->returned_qty));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'goods_receiving_id' => ['nullable', 'exists:goods_receivings,id'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'payable_id' => ['nullable', 'exists:payables,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.goods_receiving_item_id' => ['nullable', 'integer', 'exists:goods_receiving_items,id'],
            'items.*.unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'items.*.qty_returned' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.reason' => ['nullable', 'string', 'max:100'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $return = $this->supplierReturnService->createReturn(
            $data,
            $data['items'],
            $request->user()->id,
        );

        return redirect()
            ->route('supplier-returns.show', $return)
            ->with('success', 'Retur supplier berhasil dibuat.');
    }

    public function show(SupplierReturn $supplierReturn)
    {
        $this->ensureAccess(request(), $supplierReturn);

        $supplierReturn->load([
            'supplier:id,name,phone,email,address',
            'goodsReceiving:id,document_number',
            'payable:id,total,paid,status,document_number',
            'items.product:id,title,sku',
            'items.unit:id,symbol',
            'items.goodsReceivingItem',
            'creator:id,name',
        ]);

        return Inertia::render('Dashboard/SupplierReturns/Show', [
            'return' => $supplierReturn,
        ]);
    }

    public function complete(Request $request, SupplierReturn $supplierReturn)
    {
        $this->ensureAccess($request, $supplierReturn);

        if ($supplierReturn->status !== 'draft') {
            return back()->with('error', 'Hanya retur dengan status draft yang bisa diselesaikan.');
        }

        $this->supplierReturnService->complete($supplierReturn, $request->user()->id);

        return redirect()
            ->route('supplier-returns.show', $supplierReturn)
            ->with('success', 'Retur supplier berhasil diselesaikan.');
    }

    public function cancel(Request $request, SupplierReturn $supplierReturn)
    {
        $this->ensureAccess($request, $supplierReturn);

        if (! in_array($supplierReturn->status, ['draft'])) {
            return back()->with('error', 'Retur tidak dapat dibatalkan.');
        }

        $this->supplierReturnService->cancel($supplierReturn);

        return redirect()
            ->route('supplier-returns.index')
            ->with('success', 'Retur supplier dibatalkan.');
    }

    protected function ensureAccess(Request $request, SupplierReturn $supplierReturn): void
    {
        $warehouse = $supplierReturn->warehouse_id
            ? $supplierReturn->warehouse()->first()
            : null;

        abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 404);
    }
}
