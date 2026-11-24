<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\StockMutation;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\OutletAccessService;
use App\Services\StockMutationService;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Lean screen over `products` where is_raw_material = true. Products stay the source of truth; this
 * only hides what a raw material does not need (barcode, SKU, sell price) and adds purchased-by.
 */
class RawMaterialController extends Controller
{
    private const CATEGORY = 'Bahan Baku';

    public function __construct(
        private readonly StockMutationService $stockMutationService,
        private readonly AuditLogService $auditLogService,
        private readonly OutletAccessService $outletAccessService
    ) {}

    public function index(Request $request)
    {
        $warehouses = $this->outletAccessService->warehousesFor($request->user());

        $materials = Product::where('is_raw_material', true)
            ->when($request->search, fn ($query, $search) => $query->where(
                fn ($query) => $query->where('title', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%')
            ))
            ->with([
                'category:id,name',
                'units',
                'warehouses' => fn ($query) => $query->whereIn('warehouses.id', $warehouses->pluck('id')),
            ])
            ->orderBy('title')
            ->paginate($this->perPage())
            ->withQueryString()
            ->through(function (Product $product) use ($warehouses) {
                $default = $product->units->first(fn ($unit) => $unit->pivot->is_purchase_default);

                return [
                    'id' => $product->id,
                    'title' => $product->title,
                    'sku' => $product->sku,
                    'category' => $product->category?->name,
                    'unit' => $product->units->first(fn ($unit) => $unit->pivot->is_base)?->code,
                    'purchased_by' => $product->purchased_by,
                    'min_stock' => $product->min_stock,
                    'default_purchase_unit' => $default ? ['code' => $default->code, 'conversion_factor' => (float) $default->pivot->conversion_factor] : null,
                    // Cost is the warehouse's average per stock unit; the reference price until it has one.
                    'stocks' => $warehouses->map(fn (Warehouse $warehouse) => [
                        'warehouse_id' => $warehouse->id,
                        'stock' => (int) ($product->warehouses->firstWhere('id', $warehouse->id)?->pivot->stock ?? 0),
                        'cost' => $product->warehouses->firstWhere('id', $warehouse->id)?->pivot->avg_cost ?? $product->buy_price,
                    ])->values(),
                ];
            });

        return Inertia::render('Dashboard/RawMaterials/Index', [
            'materials' => $materials,
            'warehouses' => $warehouses->map->only(['id', 'code', 'name'])->values(),
        ]);
    }

    public function create(Request $request)
    {
        return Inertia::render('Dashboard/RawMaterials/Create', [
            ...$this->formOptions(),
            'warehouses' => $this->outletAccessService->warehousesFor($request->user())->map->only(['id', 'code', 'name'])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            ...$this->rules(),
            'opening_warehouse_id' => 'nullable|required_with:opening_qty|exists:warehouses,id',
            'opening_qty' => 'nullable|required_with:opening_warehouse_id|numeric|gt:0|max:1000000000',
            'opening_unit_id' => ['nullable', 'required_with:opening_qty', Rule::in([$request->input('stock_unit_id'), ...collect($request->input('units'))->pluck('unit_id')])],
        ]);

        $warehouse = isset($data['opening_warehouse_id']) ? Warehouse::findOrFail($data['opening_warehouse_id']) : null;
        abort_if($warehouse && ! $this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 403);

        $rows = $this->unitRows($data);
        $openingStock = $warehouse ? (int) round($data['opening_qty'] * $rows[$data['opening_unit_id']]['conversion_factor']) : 0;
        if ($warehouse && $openingStock < 1) {
            throw ValidationException::withMessages(['opening_qty' => 'Jumlah stok awal terlalu kecil: hasilnya kurang dari 1 satuan stok.']);
        }

        $product = DB::transaction(function () use ($request, $data, $warehouse, $rows, $openingStock) {
            $code = $this->newCode();

            $product = Product::create([
                'barcode' => $code,
                'sku' => $code,
                'title' => $data['title'],
                'category_id' => $data['category_id'] ?? $this->defaultCategory()->id,
                'buy_price' => $this->referencePrice($data) ?? 0,
                'sell_price' => 0,
                'stock' => 0,
                'min_stock' => $data['min_stock'] ?? 0,
                'tax_type' => 'exclusive',
                'tax_rate' => 0,
                'is_raw_material' => true,
                'purchased_by' => $data['purchased_by'],
            ]);
            $product->units()->sync($rows);

            if ($warehouse) {
                $product->stock = $openingStock;
                $product->save();
                ProductWarehouse::updateOrCreate(
                    ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
                    ['stock' => $product->stock]
                );
                $this->stockMutationService->recordInitialStock($product, $request->user()?->id, $warehouse->id);
            }

            return $product;
        });

        $this->auditLogService->log(
            event: 'product.created',
            module: 'products',
            auditable: $product,
            description: 'Bahan baku baru dibuat.',
            after: $this->auditLogService->only($product->fresh()->toArray(), ['title', 'sku', 'buy_price', 'stock', 'purchased_by'])
        );

        return to_route('raw-materials.index');
    }

    public function edit(Product $product)
    {
        abort_unless($product->is_raw_material, 404);

        $product->load('units');

        return Inertia::render('Dashboard/RawMaterials/Edit', [
            ...$this->formOptions(),
            'material' => [
                'id' => $product->id,
                'title' => $product->title,
                'category_id' => $product->category_id,
                'purchased_by' => $product->purchased_by,
                'min_stock' => $product->min_stock,
                'stock_unit_id' => $product->units->first(fn ($unit) => $unit->pivot->is_base)?->id,
                'units' => $product->units->reject(fn ($unit) => $unit->pivot->is_base)->map(fn ($unit) => [
                    'unit_id' => $unit->id,
                    'conversion_factor' => (float) $unit->pivot->conversion_factor,
                    'buy_price' => $unit->pivot->buy_price,
                    'is_purchase_default' => $unit->pivot->is_purchase_default,
                ])->values(),
            ],
        ]);
    }

    public function update(Request $request, Product $product)
    {
        abort_unless($product->is_raw_material, 404);

        $data = $request->validate($this->rules());

        // Stock is counted in this unit, so it cannot change once the material has one.
        $currentBase = $product->baseUnit();
        if ($currentBase && $currentBase->id !== (int) $data['stock_unit_id']) {
            throw ValidationException::withMessages(['stock_unit_id' => 'Satuan stok tidak bisa diubah setelah bahan baku dibuat.']);
        }

        $before = $this->auditLogService->only($product->toArray(), ['title', 'buy_price', 'purchased_by', 'min_stock']);

        DB::transaction(function () use ($product, $data) {
            $product->update([
                'title' => $data['title'],
                'category_id' => $data['category_id'] ?? $this->defaultCategory()->id,
                'buy_price' => $this->referencePrice($data) ?? $product->buy_price,
                'min_stock' => $data['min_stock'] ?? 0,
                'purchased_by' => $data['purchased_by'],
            ]);
            $product->units()->sync($this->unitRows($data, $product->buy_price));
        });

        $this->auditLogService->log(
            event: 'product.updated',
            module: 'products',
            auditable: $product,
            description: 'Bahan baku diperbarui.',
            before: $before,
            after: $this->auditLogService->only($product->fresh()->toArray(), ['title', 'buy_price', 'purchased_by', 'min_stock'])
        );

        return to_route('raw-materials.index');
    }

    public function destroy(Product $product)
    {
        abort_unless($product->is_raw_material, 404);

        try {
            $blocked = DB::transaction(function () use ($product) {
                if ($product->compositeOf()->exists()) {
                    return 'Bahan baku tidak bisa dihapus karena dipakai sebagai komponen resep.';
                }

                // The opening stock is the only history a material can lose; its mutation goes with the product (cascade).
                if (StockMutation::where('product_id', $product->id)->where('reference_type', '!=', 'product_create')->exists()) {
                    return 'Bahan baku tidak bisa dihapus karena sudah punya riwayat stok (pembelian, penjualan, transfer, atau opname).';
                }

                $product->delete();
            });
        } catch (QueryException) {
            // A draft PO, transfer or return line, or a batch, has no stock mutation but still restricts the delete.
            $blocked = 'Bahan baku tidak bisa dihapus karena masih dipakai di dokumen lain (PO, transfer, retur, atau batch).';
        }

        if ($blocked) {
            return back()->withErrors(['delete' => $blocked]);
        }

        $this->auditLogService->log(
            event: 'product.deleted',
            module: 'products',
            auditable: $product,
            description: 'Bahan baku dihapus.',
            before: $this->auditLogService->only($product->toArray(), ['title', 'sku', 'buy_price', 'stock'])
        );

        return back();
    }

    private function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'stock_unit_id' => 'required|integer|exists:units,id',
            'purchased_by' => 'required|in:central,outlet',
            'min_stock' => 'nullable|integer|min:0',
            'units' => ['nullable', 'array', function (string $attribute, mixed $value, Closure $fail) {
                if (collect($value)->filter(fn ($u) => filter_var($u['is_purchase_default'] ?? false, FILTER_VALIDATE_BOOLEAN))->count() > 1) {
                    $fail('Hanya satu satuan beli default yang diperbolehkan.');
                }
            }],
            'units.*.unit_id' => 'required|integer|distinct|exists:units,id|different:stock_unit_id',
            'units.*.conversion_factor' => 'required|numeric|min:0.0001',
            'units.*.buy_price' => 'required|integer|min:0',
            'units.*.is_purchase_default' => 'nullable|boolean',
        ];
    }

    /** Stock unit as the base row, then each purchase unit; keyed by unit id for `sync`. */
    private function unitRows(array $data, int $referencePrice = 0): array
    {
        $rows = [(int) $data['stock_unit_id'] => [
            'is_base' => true,
            'is_purchase_default' => false,
            'conversion_factor' => 1,
            'buy_price' => $this->referencePrice($data) ?? $referencePrice,
            'sell_price' => 0,
        ]];

        foreach ($data['units'] ?? [] as $unit) {
            $rows[(int) $unit['unit_id']] = [
                'is_base' => false,
                'is_purchase_default' => (bool) ($unit['is_purchase_default'] ?? false),
                'conversion_factor' => $unit['conversion_factor'],
                'buy_price' => $unit['buy_price'],
                'sell_price' => 0,
            ];
        }

        return $rows;
    }

    /** Default purchase unit price ÷ its content, in whole rupiah per stock unit; null when no default is set. */
    private function referencePrice(array $data): ?int
    {
        $default = collect($data['units'] ?? [])->first(fn ($u) => filter_var($u['is_purchase_default'] ?? false, FILTER_VALIDATE_BOOLEAN));

        return $default ? (int) round($default['buy_price'] / $default['conversion_factor']) : null;
    }

    private function defaultCategory(): Category
    {
        return Category::firstOrCreate(['name' => self::CATEGORY], ['description' => 'Bahan baku untuk resep dan produksi.']);
    }

    private function newCode(): string
    {
        do {
            $code = 'RM-'.Str::upper(Str::random(5));
        } while (Product::where('barcode', $code)->orWhere('sku', $code)->exists());

        return $code;
    }

    private function formOptions(): array
    {
        return [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'defaultCategoryId' => Category::where('name', self::CATEGORY)->value('id'),
            'units' => Unit::orderBy('code')->get(['id', 'code', 'symbol']),
        ];
    }
}
