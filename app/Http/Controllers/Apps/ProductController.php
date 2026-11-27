<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\KitchenStation;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\InventoryCostService;
use App\Services\OutletAccessService;
use App\Services\StockMutationService;
use App\Services\TaxService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function __construct(
        private readonly StockMutationService $stockMutationService,
        private readonly AuditLogService $auditLogService,
        private readonly OutletAccessService $outletAccessService,
        private readonly InventoryCostService $inventoryCostService
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $products = Product::when($request->search, function ($products, $search) {
            $products = $products->where('title', 'like', '%'.$search.'%');
        })->with('category', 'components')->latest()->paginate($this->perPage())->withQueryString();

        // A recipe's cost is its components' cost in the active outlet; a plain product shows its typed buy price.
        $warehouseId = $this->outletAccessService->costWarehouseId($request);
        $products->through(fn (Product $product) => $product->setAttribute(
            'cost',
            $product->is_composite ? $this->inventoryCostService->lineCost($product, $warehouseId, 1) : (int) $product->buy_price
        ));

        $warehouses = $this->outletAccessService->warehousesFor($request->user());

        return Inertia::render('Dashboard/Products/Index', [
            'products' => $products,
            'warehouses' => $warehouses,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create(Request $request)
    {
        // get categories
        $categories = Category::all();

        // return inertia
        return Inertia::render('Dashboard/Products/Create', [
            'categories' => $categories,
            'products' => $this->componentCandidates($request),
            'cost_outlet' => $this->outletAccessService->activeOutlet($request)?->code,
            'default_tax_rate' => app(TaxService::class)->getDefaultRate($this->outletAccessService->activeOutlet($request)),
            'units' => Unit::orderBy('code')->get(['id', 'code', 'symbol']),
            'kitchen_stations' => KitchenStation::ordered()->get(['id', 'name', 'is_default']),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(Request $request)
    {
        /**
         * validate
         */
        $request->validate([
            'image' => 'nullable|image|mimes:jpeg,jpg,png,webp|max:2048',
            'barcode' => 'required|unique:products,barcode',
            'sku' => 'required|unique:products,sku',
            'title' => 'required',
            'description' => 'nullable|string',
            'category_id' => 'required',
            'buy_price' => 'exclude_if:is_composite,1,true|required',
            'sell_price' => 'required',
            'stock' => 'required|integer|min:0',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'min_stock' => 'nullable|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'tax_type' => 'nullable|in:exclusive,inclusive',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            ...$this->compositeRules(),
            ...$this->unitRules(),
        ], $this->purchasedByMessages());
        // upload image
        $image = $request->file('image');
        $image?->storeAs('public/products', $image->hashName());

        // create product
        $product = Product::create([
            'image' => $image?->hashName(),
            'barcode' => $request->barcode,
            'sku' => $request->sku,
            'title' => $request->title,
            'description' => $request->description,
            'category_id' => $request->category_id,
            'buy_price' => $request->boolean('is_composite') ? 0 : $request->buy_price,
            'sell_price' => $request->sell_price,
            'stock' => $request->stock,
            'min_stock' => $request->min_stock ?? 0,
            'max_stock' => $request->max_stock ?? 0,
            'tax_type' => $request->input('tax_type', 'exclusive'),
            'tax_rate' => $request->input('tax_rate', 11.00),
            'is_composite' => $request->boolean('is_composite'),
            'is_raw_material' => $request->boolean('is_raw_material'),
            'needs_preparation' => $request->boolean('needs_preparation'),
            'prep_target_minutes' => $this->prepTargetMinutes($request),
            'kitchen_station_id' => $this->kitchenStationId($request),
            'purchased_by' => $this->purchasedBy($request),
        ]);

        if ($request->boolean('is_composite')) {
            $this->validateComponentProducts($request);
            $this->syncComponents($product, $request->input('components'));
        } else {
            $this->syncUnits($product, $request->input('units'));

            // ponytail: keep global products.stock and per-warehouse pivot in sync; PUSAT (first active main) is the default warehouse
            $warehouse = Warehouse::find($request->warehouse_id)
                ?? Warehouse::active()->where('type', 'main')->orderBy('sort_order')->orderBy('code')->first()
                ?? Warehouse::active()->orderBy('sort_order')->orderBy('code')->first();

            abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 403);

            if ($warehouse) {
                ProductWarehouse::updateOrCreate(
                    ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
                    ['stock' => (int) $request->stock]
                );
            }

            $this->stockMutationService->recordInitialStock($product, $request->user()?->id, $warehouse?->id);
        }
        $this->auditLogService->log(
            event: 'product.created',
            module: 'products',
            auditable: $product,
            description: 'Produk baru dibuat.',
            after: $this->productAuditPayload($product->fresh())
        );

        // redirect
        return to_route('products.index');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit(Request $request, Product $product)
    {
        // get categories
        $categories = Category::all();

        return Inertia::render('Dashboard/Products/Edit', [
            'product' => $product->load('components', 'units'),
            'categories' => $categories,
            'products' => $this->componentCandidates($request, $product),
            'cost_outlet' => $this->outletAccessService->activeOutlet($request)?->code,
            'units' => Unit::orderBy('code')->get(['id', 'code', 'symbol']),
            'kitchen_stations' => KitchenStation::ordered()->get(['id', 'name', 'is_default']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, Product $product)
    {
        $before = $this->productAuditPayload($product);

        /**
         * validate
         */
        $request->validate([
            'barcode' => 'required|unique:products,barcode,'.$product->id,
            'sku' => 'required|unique:products,sku,'.$product->id,
            'title' => 'required',
            'description' => 'nullable|string',
            'category_id' => 'required',
            'buy_price' => 'exclude_if:is_composite,1,true|required',
            'sell_price' => 'required',
            'min_stock' => 'nullable|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'tax_type' => 'nullable|in:exclusive,inclusive',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            ...$this->compositeRules(),
            ...$this->unitRules(),
        ], $this->purchasedByMessages());

        if ($request->boolean('is_composite')) {
            $this->validateComponentProducts($request, $product);
        } elseif ($product->is_composite && $product->components()->exists()) {
            return back()->withErrors([
                'components' => 'Produk komposit harus memiliki minimal satu komponen.',
            ]);
        }

        // check image update
        if ($request->file('image')) {

            // remove old image
            Storage::disk('local')->delete('public/products/'.basename($product->image));

            // upload new image
            $image = $request->file('image');
            $image->storeAs('public/products', $image->hashName());

            // update product with new image
            $product->update([
                'image' => $image->hashName(),
                'barcode' => $request->barcode,
                'sku' => $request->sku,
                'title' => $request->title,
                'description' => $request->description,
                'category_id' => $request->category_id,
                'buy_price' => $request->boolean('is_composite') ? 0 : $request->buy_price,
                'sell_price' => $request->sell_price,
                'min_stock' => $request->min_stock ?? 0,
                'max_stock' => $request->max_stock ?? 0,
                'tax_type' => $request->input('tax_type', 'exclusive'),
                'tax_rate' => $request->input('tax_rate', 11.00),
                'is_composite' => $request->boolean('is_composite'),
                'is_raw_material' => $request->boolean('is_raw_material'),
                'needs_preparation' => $request->boolean('needs_preparation'),
                'prep_target_minutes' => $this->prepTargetMinutes($request),
                'kitchen_station_id' => $this->kitchenStationId($request),
                'purchased_by' => $this->purchasedBy($request),
            ]);

            if ($request->boolean('is_composite')) {
                $this->syncComponents($product, $request->input('components'));
            } else {
                $this->syncUnits($product, $request->input('units'));
            }

            $this->logProductUpdate($product, $before);

            return to_route('products.index');
        }

        // update product without image
        $product->update([
            'barcode' => $request->barcode,
            'sku' => $request->sku,
            'title' => $request->title,
            'description' => $request->description,
            'category_id' => $request->category_id,
            'buy_price' => $request->boolean('is_composite') ? 0 : $request->buy_price,
            'sell_price' => $request->sell_price,
            'min_stock' => $request->min_stock ?? 0,
            'max_stock' => $request->max_stock ?? 0,
            'tax_type' => $request->input('tax_type', 'exclusive'),
            'tax_rate' => $request->input('tax_rate', 11.00),
            'is_composite' => $request->boolean('is_composite'),
            'is_raw_material' => $request->boolean('is_raw_material'),
            'needs_preparation' => $request->boolean('needs_preparation'),
            'prep_target_minutes' => $this->prepTargetMinutes($request),
            'kitchen_station_id' => $this->kitchenStationId($request),
            'purchased_by' => $this->purchasedBy($request),
        ]);

        if ($request->boolean('is_composite')) {
            $this->syncComponents($product, $request->input('components'));
        } else {
            $this->syncUnits($product, $request->input('units'));
        }

        $this->logProductUpdate($product, $before);

        // redirect
        return to_route('products.index');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        // find by ID
        $product = Product::findOrFail($id);
        $before = $this->productAuditPayload($product);

        // remove image
        Storage::disk('local')->delete('public/products/'.basename($product->image));

        // delete
        $product->delete();

        $this->auditLogService->log(
            event: 'product.deleted',
            module: 'products',
            auditable: $product,
            description: 'Produk dihapus.',
            before: $before
        );

        // redirect
        return back();
    }

    private function purchasedByMessages(): array
    {
        return ['purchased_by.required' => 'Pilih siapa yang membeli bahan ini.'];
    }

    /** Only a raw material has a buyer; anything else stores null even if the field was sent. */
    private function purchasedBy(Request $request): ?string
    {
        return $request->boolean('is_raw_material') ? $request->input('purchased_by') : null;
    }

    /** The prep target only means something on a product that needs preparation; otherwise null. */
    private function prepTargetMinutes(Request $request): ?int
    {
        return $request->boolean('needs_preparation') ? $request->integer('prep_target_minutes') ?: null : null;
    }

    /** The station only means something on a product that needs preparation; otherwise null (= Dapur). */
    private function kitchenStationId(Request $request): ?int
    {
        return $request->boolean('needs_preparation') ? $request->integer('kitchen_station_id') ?: null : null;
    }

    private function compositeRules(): array
    {
        return [
            'is_composite' => 'nullable|boolean',
            'is_raw_material' => 'nullable|boolean',
            'needs_preparation' => 'nullable|boolean',
            'prep_target_minutes' => 'exclude_unless:needs_preparation,1,true|nullable|integer|min:1|max:65535',
            'kitchen_station_id' => ['exclude_unless:needs_preparation,1,true', 'nullable', 'integer', Rule::exists('kitchen_stations', 'id')->whereNull('deleted_at')],
            'purchased_by' => 'exclude_unless:is_raw_material,1,true|required|in:central,outlet',
            // The form always sends `components: []`; only a composite needs at least one.
            'components' => 'exclude_unless:is_composite,1,true|required|array|min:1',
            'components.*.component_product_id' => 'required|integer|distinct|exists:products,id',
            'components.*.qty' => 'required|integer|min:1',
        ];
    }

    /**
     * Non-composite products for the component picker: raw materials first, each with its
     * base-unit symbol (one subquery, no N+1; null when the product has no units) and unit_cost.
     */
    private function componentCandidates(Request $request, ?Product $except = null)
    {
        $candidates = Product::where('is_composite', false)
            ->when($except, fn ($query) => $query->where('id', '!=', $except->id))
            ->addSelect(['id', 'title', 'buy_price', 'sell_price', 'is_composite', 'is_raw_material'])
            ->addSelect(['unit_symbol' => DB::table('product_units')
                ->join('units', 'units.id', '=', 'product_units.unit_id')
                ->select('units.symbol')
                ->whereColumn('product_units.product_id', 'products.id')
                ->where('product_units.is_base', true)
                ->limit(1)])
            ->orderByDesc('is_raw_material')
            ->orderBy('title')
            ->get();

        // Each component's cost per stock unit in the active outlet, for the recipe's live HPP.
        $costs = $this->inventoryCostService->unitCosts($candidates, $this->outletAccessService->costWarehouseId($request));

        return $candidates->each(fn (Product $p) => $p->setAttribute('unit_cost', $costs[$p->id]));
    }

    private function validateComponentProducts(Request $request, ?Product $product = null): void
    {
        $componentIds = collect($request->input('components'))
            ->pluck('component_product_id')
            ->unique();

        if ($product && $componentIds->contains($product->id)) {
            abort(422, 'Produk tidak bisa menjadi komponen dirinya sendiri.');
        }

        $compositeCount = Product::whereIn('id', $componentIds)->where('is_composite', true)->count();

        if ($compositeCount > 0) {
            abort(422, 'Komponen tidak boleh produk komposit lain.');
        }
    }

    private function syncComponents(Product $product, ?array $components): void
    {
        $product->components()->sync(
            collect($components)->mapWithKeys(fn (array $c) => [
                $c['component_product_id'] => ['qty' => (int) $c['qty']],
            ])
        );
    }

    private function unitRules(): array
    {
        return [
            'units' => ['nullable', 'array', function (string $attribute, mixed $value, Closure $fail) {
                $defaults = collect($value)->filter(
                    fn ($u) => filter_var($u['is_purchase_default'] ?? false, FILTER_VALIDATE_BOOLEAN)
                );

                if ($defaults->count() > 1) {
                    $fail('Hanya satu satuan beli default yang diperbolehkan.');
                }

                // No base row is fine: syncUnits() makes the first row the base.
                $bases = collect($value)->filter(
                    fn ($u) => filter_var($u['is_base'] ?? false, FILTER_VALIDATE_BOOLEAN)
                );

                if ($bases->count() > 1) {
                    $fail('Hanya satu satuan dasar yang diperbolehkan.');
                } elseif ($bases->isNotEmpty() && (float) ($bases->first()['conversion_factor'] ?? 1) !== 1.0) {
                    $fail('Satuan dasar harus memiliki isi 1.');
                }
            }],
            'units.*.unit_id' => 'required|integer|distinct|exists:units,id',
            'units.*.is_base' => 'required|boolean',
            'units.*.conversion_factor' => 'required|numeric|min:0.0001',
            'units.*.is_purchase_default' => 'nullable|boolean',
            'units.*.buy_price' => 'nullable|integer|min:0',
            'units.*.sell_price' => 'nullable|integer|min:0',
            'units.*.barcode' => 'nullable|string|max:255',
        ];
    }

    private function syncUnits(Product $product, ?array $units): void
    {
        $units = collect($units ?? [])->keyBy('unit_id');

        if ($units->isNotEmpty() && ! $units->contains(fn ($u) => $u['is_base'])) {
            // ponytail: no explicit base unit sent — treat first row as base with factor 1
            $first = $units->keys()->first();
            $units[$first] = ['is_base' => true, 'conversion_factor' => 1] + $units[$first];
        }

        $rows = $units->map(fn (array $u) => [
            'is_base' => $u['is_base'],
            'is_purchase_default' => $u['is_purchase_default'] ?? false,
            'conversion_factor' => $u['conversion_factor'],
            // Prices are per that unit, so a blank pack price is the base price × its content.
            'buy_price' => $u['buy_price'] ?? (int) round($product->buy_price * (float) $u['conversion_factor']),
            'sell_price' => $u['sell_price'] ?? (int) round($product->sell_price * (float) $u['conversion_factor']),
            'barcode' => $u['barcode'] ?? null,
        ]);

        if ($rows->isEmpty()) {
            // ponytail: no units sent — only seed default PCS base when product has none (matches docs/features/unit-conversion.md)
            if ($product->units()->exists()) {
                return;
            }

            $pcs = Unit::where('code', 'PCS')->first();
            if ($pcs) {
                $product->units()->attach($pcs->id, [
                    'is_base' => true,
                    'conversion_factor' => 1,
                    'buy_price' => $product->buy_price,
                    'sell_price' => $product->sell_price,
                ]);
            }

            return;
        }

        $product->units()->sync($rows->all());
    }

    private function logProductUpdate(Product $product, array $before): void
    {
        $after = $this->productAuditPayload($product->fresh());

        $this->auditLogService->log(
            event: 'product.updated',
            module: 'products',
            auditable: $product,
            description: 'Data produk diperbarui.',
            before: $before,
            after: $after
        );

        // A recipe has no buy price of its own, so zeroing a stale one is not a price change.
        if (
            (! $product->is_composite && (int) $before['buy_price'] !== (int) $after['buy_price'])
            || (int) $before['sell_price'] !== (int) $after['sell_price']
        ) {
            $this->auditLogService->log(
                event: 'product.price_updated',
                module: 'products',
                auditable: $product,
                description: 'Harga produk diperbarui.',
                before: [
                    'buy_price' => $before['buy_price'],
                    'sell_price' => $before['sell_price'],
                ],
                after: [
                    'buy_price' => $after['buy_price'],
                    'sell_price' => $after['sell_price'],
                ]
            );
        }
    }

    private function productAuditPayload(Product $product): array
    {
        return $this->auditLogService->only($product->toArray(), [
            'title',
            'barcode',
            'sku',
            'buy_price',
            'sell_price',
            'stock',
            'category_id',
        ]);
    }
}
