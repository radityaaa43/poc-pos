<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\Setting;
use App\Services\DineOrderOnlinePaymentService;
use App\Services\Modifiers\ModifierSelection;
use App\Services\OutletAccessService;
use App\Services\PricingService;
use Inertia\Inertia;

class DineMenuController extends Controller
{
    public function __construct(
        private PricingService $pricingService,
        private DineOrderOnlinePaymentService $onlinePayment,
        private OutletAccessService $outletAccessService,
    ) {}

    public function show(string $token)
    {
        $table = DiningTable::where('token', $token)
            ->where('is_active', true)
            ->with('area')
            ->firstOrFail();
        $outlet = $table->area?->outlet;

        $selfOrderEnabled = Setting::getBool('dine_in_self_order_enabled', true);

        // categories/products have no is_active or sort_order columns
        $categories = Category::orderBy('name')->get();

        $products = Product::where('is_raw_material', false)
            ->where(function ($query) use ($outlet) {
                $query->where('stock', '>', 0)->orWhere('is_composite', true);
                if ($outlet) {
                    $query->orWhereHas('warehouses', fn ($warehouse) => $warehouse->where('outlet_id', $outlet->id)->where('stock', '>', 0));
                }
            })
            ->with('category')
            ->get();

        // ponytail: previewProducts() returns arrays keyed by product id (pricing fields only) — merge the effective price back onto model-shaped payloads the menu page expects (id/title/image/category_id/sell_price)
        $previews = $this->pricingService->previewProducts($products, null, null, $outlet);

        // Same catalog and availability the POS shows (BR-1): options switched off at the outlet are already gone,
        // an option short of its recipe carries available=false. One flat group list plus the ids each menu uses.
        $catalog = ModifierSelection::forPos($products->pluck('id'), $outlet, $this->outletAccessService->outletWarehouseId($outlet));
        $modifierGroups = $catalog->flatten(1)->unique('id')->keyBy('id');

        $products = $products->map(function (Product $product) use ($previews, $catalog) {
            $preview = $previews->get($product->id);

            return [
                'id' => $product->id,
                'title' => $product->title,
                'image' => $product->image,
                'category_id' => $product->category_id,
                'sell_price' => (int) ($preview['effective_unit_price'] ?? $product->sell_price),
                'modifier_group_ids' => collect($catalog->get($product->id, []))->pluck('id')->sort()->values()->all(),
            ];
        })->values();

        $storeName = Setting::getForOutlet('store_name', $outlet, 'Restoran');
        $storeLogo = Setting::getForOutlet('store_logo', $outlet);

        return Inertia::render('Public/DineMenu', [
            'table' => [
                'id' => $table->id,
                'token' => $table->token,
                'name' => $table->name,
                'capacity' => $table->capacity,
                'area_name' => $table->area?->name,
            ],
            'categories' => $categories,
            'products' => $products,
            'modifierGroups' => $modifierGroups,
            'selfOrderEnabled' => $selfOrderEnabled,
            'payLaterEnabled' => $outlet ? Setting::getBoolForOutlet('pay_later_enabled', $outlet) : false,
            'onlinePayment' => $this->onlinePayment->isReadyFor($outlet),
            'storeName' => $storeName,
            'storeLogo' => $storeLogo,
        ]);
    }
}
