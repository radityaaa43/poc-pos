<?php

namespace App\Http\Controllers;

use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Product;
use App\Models\Setting;
use App\Services\AddOnOrderService;
use App\Services\DineOrderOnlinePaymentService;
use App\Services\Modifiers\ModifierSelection;
use App\Services\OutletAccessService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DineOrderController extends Controller
{
    public function __construct(
        private PricingService $pricingService,
        private DineOrderOnlinePaymentService $onlinePayment,
        private OutletAccessService $outletAccessService,
        private AddOnOrderService $addOnOrder,
    ) {}

    public function store(Request $request, string $token)
    {
        $table = DiningTable::where('token', $token)->where('is_active', true)->firstOrFail();
        $outlet = $table->area?->outlet;

        // Same global setting the menu page reads; the page only hides the button.
        if (! Setting::getBool('dine_in_self_order_enabled', true)) {
            throw ValidationException::withMessages(['order' => 'Fitur pemesanan sementara nonaktif.']);
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.qty' => ['required', 'integer', 'min:1'],
            'items.*.unit_id' => ['nullable', 'exists:units,id'],
            'items.*.note' => ['nullable', 'string', 'max:255'],
            'items.*.modifier_option_ids' => ['nullable', 'array', 'max:50'],
            'items.*.modifier_option_ids.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:500'],
            // Payment is never taken at submit: the guest can pay online only after staff accept (POST dine-order.pay).
            // So the only options here are the counter and pay later (the outlet's choice, checked below).
            'payment_option' => ['required', 'in:'.DineOrder::PAY_AT_COUNTER.','.DineOrder::PAY_LATER],
            // The access token of the order whose status page the guest ordered from (#251), never an id.
            'add_on_to' => ['nullable', 'string', 'max:64'],
        ]);

        // The menu only hides the button; a menu left open while the owner switched pay later off must still be refused here.
        if ($validated['payment_option'] === DineOrder::PAY_LATER
            && ! ($outlet && Setting::getBoolForOutlet('pay_later_enabled', $outlet))) {
            throw ValidationException::withMessages(['order' => 'Bayar Belakangan tidak tersedia di outlet ini. Pilih Bayar di kasir.']);
        }

        $original = isset($validated['add_on_to']) ? $this->addOnOrder->originalForGuest($validated['add_on_to'], $table) : null;

        $items = collect($validated['items']);
        $productIds = $items->pluck('product_id')->toArray();

        $productModels = Product::with('components')
            ->whereIn('id', $productIds)
            ->where('is_raw_material', false)
            ->get()
            ->keyBy('id');

        $previews = $this->pricingService->previewProducts($productModels, null, null, $outlet);
        // Options are checked for the outlet's own warehouse at submit; accept re-resolves against the shift's warehouse.
        $warehouseId = $this->outletAccessService->outletWarehouseId($outlet);

        $subtotal = 0;
        $orderItems = [];

        foreach ($items as $item) {
            $product = $productModels->get($item['product_id']);
            if (! $product) {
                continue;
            }

            $unitId = $item['unit_id'] ?? null;
            $conversionFactor = $unitId
                ? ($product->units()->where('unit_id', $unitId)->first()?->pivot->conversion_factor ?? 1)
                : 1;
            $baseQty = (int) round($item['qty'] * (float) $conversionFactor);

            // Public orders are routed to the outlet's warehouse when accepted.
            $available = $product->stockAtOutlet($outlet);

            if ($available < $baseQty) {
                // A flash would read as success to Inertia (and the public page renders none); an error reaches onError.
                throw ValidationException::withMessages([
                    'order' => "Stok {$product->title} tidak mencukupi (tersedia: {$available}).",
                ]);
            }

            $preview = $previews->get($product->id);
            $selection = ModifierSelection::resolve($product, $item['modifier_option_ids'] ?? [], $outlet, $warehouseId);
            $optionIds = collect($selection['options'])->pluck('id')->sort()->values()->all();
            // The shown snapshot (name + price) is kept so accept cannot re-price an option the guest was offered (AC-15).
            $snapshot = $selection['has_groups']
                ? array_map(
                    fn (array $option) => ['id' => $option['id'], 'group_name' => $option['group_name'], 'name' => $option['name'], 'price_delta' => $option['price_delta']],
                    $selection['options'],
                )
                : null;
            $price = (int) ($preview['effective_unit_price'] ?? $product->sell_price) + $selection['unit_total'];
            $subtotal += $price * $item['qty'];
            $orderItems[] = [
                'product_id' => $item['product_id'],
                'unit_id' => $unitId,
                'conversion_factor' => $conversionFactor,
                'qty' => $item['qty'],
                'price' => $price,
                'note' => $item['note'] ?? null,
                'modifier_option_ids' => $selection['has_groups'] ? $optionIds : null,
                'modifier_snapshot' => $snapshot,
            ];
        }

        $order = DineOrder::create([
            'dine_table_id' => $table->id,
            'access_token' => (string) Str::uuid(),
            'status' => DineOrder::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'payment_option' => $validated['payment_option'],
            'notes' => $validated['notes'] ?? null,
            'subtotal' => $subtotal,
            'item_count' => $items->sum('qty'),
            'cashier_id' => null,
            'add_on_to_dine_order_id' => $original?->id,
        ]);

        foreach ($orderItems as $item) {
            $order->items()->create($item);
        }

        return redirect()
            ->route('dine-order.status', $order->access_token)
            ->with('success', 'Pesanan berhasil dikirim.');
    }

    public function status(string $accessToken)
    {
        $order = DineOrder::with(['table.area', 'items.product', 'transaction:id,payment_status'])
            ->where('access_token', $accessToken)
            ->firstOrFail();

        $table = DiningTable::where('token', $order->table->token)
            ->where('is_active', true)
            ->firstOrFail();

        $storeName = Setting::getForOutlet('store_name', $order->table?->area?->outlet, 'Restoran');
        $order->setAttribute('is_paid', $order->isPaid());
        $order->setAttribute('add_on_open', $this->addOnOrder->wouldLink($order));
        $order->makeHidden('transaction');

        return Inertia::render('Public/DineOrderStatus', [
            'order' => $order,
            'table' => [
                'id' => $table->id,
                'token' => $table->token,
                'name' => $table->name,
            ],
            'storeName' => $storeName,
            'payment' => $this->onlinePayment->state($order),
        ]);
    }

    public function statusCheck(string $accessToken)
    {
        $order = DineOrder::where('access_token', $accessToken)
            ->first(['id', 'transaction_id', 'status', 'payment_option', 'payment_status', 'subtotal', 'item_count', 'updated_at']);

        if (! $order) {
            return response()->json(['message' => 'Pesanan tidak ditemukan.'], 404);
        }

        // ponytail: the URL token is the only auth factor — poll access is bounded by a 24h window and a rate limit on the route
        if ($order->updated_at->lt(now()->subHours(24))) {
            return response()->json(['message' => 'Pesanan sudah kedaluwarsa.'], 410);
        }

        // the kitchen waits for payment, so the guest needs to know whether the bill is paid yet
        $order->setAttribute('is_paid', $order->isPaid());
        $order->setAttribute('add_on_open', $this->addOnOrder->wouldLink($order));

        // No QR here: the page already holds it, and the poll stays light.
        $payment = $this->onlinePayment->state($order, withQr: false);

        return response()->json(['order' => $order->makeHidden(['transaction_id', 'transaction']), 'payment' => $payment]);
    }
}
