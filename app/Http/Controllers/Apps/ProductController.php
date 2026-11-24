<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\AuditLogService;
use App\Services\OutletAccessService;
use App\Services\StockMutationService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class ProductController extends Controller
{
    public function __construct(
        private readonly StockMutationService $stockMutationService,
        private readonly AuditLogService $auditLogService,
        private readonly OutletAccessService $outletAccessService
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
        })->with('category')->latest()->paginate($this->perPage())->withQueryString();

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
    public function create()
    {
        // get categories
        $categories = Category::all();

        // return inertia
        return Inertia::render('Dashboard/Products/Create', [
            'categories' => $categories,
            'products' => $this->componentCandidates(),
            'units' => Unit::orderBy('code')->get(['id', 'code', 'symbol']),
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
            'buy_price' => 'required',
            'sell_price' => 'required',
            'stock' => 'required|integer|min:0',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'min_stock' => 'nullable|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'tax_type' => 'nullable|in:exclusive,inclusive',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            ...$this->compositeRules(),
            ...$this->unitRules(),
        ]);
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
            'buy_price' => $request->buy_price,
            'sell_price' => $request->sell_price,
            'stock' => $request->stock,
            'min_stock' => $request->min_stock ?? 0,
            'max_stock' => $request->max_stock ?? 0,
            'tax_type' => $request->input('tax_type', 'exclusive'),
            'tax_rate' => $request->input('tax_rate', 11.00),
            'is_composite' => $request->boolean('is_composite'),
            'is_raw_material' => $request->boolean('is_raw_material'),
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
    public function edit(Product $product)
    {
        // get categories
        $categories = Category::all();

        return Inertia::render('Dashboard/Products/Edit', [
            'product' => $product->load('components', 'units'),
            'categories' => $categories,
            'products' => $this->componentCandidates($product),
            'units' => Unit::orderBy('code')->get(['id', 'code', 'symbol']),
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
            'buy_price' => 'required',
            'sell_price' => 'required',
            'min_stock' => 'nullable|integer|min:0',
            'max_stock' => 'nullable|integer|min:0',
            'tax_type' => 'nullable|in:exclusive,inclusive',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            ...$this->compositeRules(),
            ...$this->unitRules(),
        ]);

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
                'buy_price' => $request->buy_price,
                'sell_price' => $request->sell_price,
                'min_stock' => $request->min_stock ?? 0,
                'max_stock' => $request->max_stock ?? 0,
                'tax_type' => $request->input('tax_type', 'exclusive'),
                'tax_rate' => $request->input('tax_rate', 11.00),
                'is_composite' => $request->boolean('is_composite'),
                'is_raw_material' => $request->boolean('is_raw_material'),
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
            'buy_price' => $request->buy_price,
            'sell_price' => $request->sell_price,
            'min_stock' => $request->min_stock ?? 0,
            'max_stock' => $request->max_stock ?? 0,
            'tax_type' => $request->input('tax_type', 'exclusive'),
            'tax_rate' => $request->input('tax_rate', 11.00),
            'is_composite' => $request->boolean('is_composite'),
            'is_raw_material' => $request->boolean('is_raw_material'),
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

    private function compositeRules(): array
    {
        return [
            'is_composite' => 'nullable|boolean',
            'is_raw_material' => 'nullable|boolean',
            // The form always sends `components: []`; only a composite needs at least one.
            'components' => 'exclude_unless:is_composite,1,true|required|array|min:1',
            'components.*.component_product_id' => 'required|integer|distinct|exists:products,id',
            'components.*.qty' => 'required|integer|min:1',
        ];
    }

    /**
     * Non-composite products for the component picker: raw materials first, each with its
     * base-unit symbol (one subquery, no N+1; null when the product has no units).
     */
    private function componentCandidates(?Product $except = null)
    {
        return Product::where('is_composite', false)
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

        if (
            (int) $before['buy_price'] !== (int) $after['buy_price']
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
