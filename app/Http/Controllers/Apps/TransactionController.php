<?php

namespace App\Http\Controllers\Apps;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerVoucher;
use App\Models\DiscountApprovalLog;
use App\Models\Outlet;
use App\Models\PaymentSetting;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Models\Warehouse;
use App\Services\AddOnOrderService;
use App\Services\AuditLogService;
use App\Services\BatchService;
use App\Services\CashierShiftService;
use App\Services\CheckoutPreviewService;
use App\Services\CheckoutService;
use App\Services\LoyaltyService;
use App\Services\OpenBillListService;
use App\Services\OutletAccessService;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\PriceListService;
use App\Services\PricingService;
use App\Services\TransactionPaymentRetryService;
use App\Services\TransactionTenderService;
use App\Services\UnitConversionService;
use App\Support\Checkout\CheckoutContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class TransactionController extends Controller
{
    public function __construct(
        protected readonly CashierShiftService $cashierShiftService,
        protected readonly AuditLogService $auditLogService,
        protected readonly PricingService $pricingService,
        protected readonly LoyaltyService $loyaltyService,
        protected readonly PriceListService $priceListService,
        protected readonly BatchService $batchService,
        protected readonly TransactionTenderService $tenderService,
        protected readonly OutletAccessService $outletAccessService,
        protected readonly CheckoutService $checkoutService,
        protected readonly TransactionPaymentRetryService $paymentRetryService,
        protected readonly CheckoutPreviewService $checkoutPreviewService,
    ) {}

    public function index()
    {
        $userId = auth()->user()->id;
        $activeShift = $this->cashierShiftService->getActiveShiftForUser($userId);
        $activeShift?->load('warehouse.outlet');
        $warehouseId = $activeShift?->warehouse_id;
        $outlet = $activeShift?->warehouse?->outlet;

        // Get active cart items (not held)
        $carts = Cart::with('product')
            ->where('cashier_id', $userId)
            ->active()
            ->latest()
            ->get();

        // The POS opens on Di Tempat, so the first paint already carries the dine-in bill.
        $initialPricingPreview = $this->checkoutPreviewService->checkout(auth()->user(), null, ['order_type' => 'in_store'], $carts);

        // Get held carts grouped by hold_id
        $heldCarts = Cart::with('product:id,title,sell_price,image')
            ->where('cashier_id', $userId)
            ->held()
            ->get()
            ->groupBy('hold_id')
            ->map(function ($items, $holdId) {
                $first = $items->first();

                return [
                    'hold_id' => $holdId,
                    'label' => $first->hold_label,
                    'held_at' => $first->held_at?->toISOString(),
                    'items_count' => $items->sum('qty'),
                    'total' => $items->sum('price'),
                ];
            })
            ->values();

        // get all customers
        $customers = Customer::latest()->get();

        // sellable products with stock > 0 in active warehouse (composites: enough component stock)
        $inWarehouse = fn ($q) => $warehouseId ? $q->where('warehouses.id', $warehouseId) : $q;
        $products = Product::with([
            'category:id,name',
            'units',
            'warehouses' => $inWarehouse,
            'components.warehouses' => $inWarehouse,
        ])
            ->select('id', 'barcode', 'title', 'description', 'image', 'buy_price', 'sell_price', 'stock', 'category_id', 'is_composite')
            ->forPos($warehouseId)
            ->orderBy('title')
            ->get()
            ->filter(fn (Product $product) => $product->stockIn($warehouseId) > 0)
            ->values();
        $pricingBadges = $this->pricingService->previewProducts($products, null, null, $outlet);
        $products = $products->map(function (Product $product) use ($pricingBadges, $warehouseId) {
            $pricing = $pricingBadges->get($product->id);

            return [
                ...collect($product->toArray())->except(['warehouses', 'components'])->all(),
                'stock' => $product->stockIn($warehouseId),
                'sell_price' => $product->is_composite ? $product->compositeSellPrice() : (int) $product->sell_price,
                'units' => $product->units->map(fn ($u) => [
                    'unit_id' => $u->id,
                    'code' => $u->code,
                    'is_base' => (bool) $u->pivot->is_base,
                    'conversion_factor' => (float) $u->pivot->conversion_factor,
                    'sell_price' => (int) $u->pivot->sell_price,
                    'barcode' => $u->pivot->barcode,
                ]),
                'pricing_badge' => $pricing && ! empty($pricing['pricing_rule']) ? [
                    'label' => $pricing['pricing_rule']['label'],
                    'promo_price' => $pricing['pricing_rule']['price_context']
                        ? $pricing['effective_unit_price']
                        : null,
                    'base_price' => $pricing['base_unit_price'],
                    'kind' => $pricing['pricing_rule']['kind'],
                ] : null,
            ];
        });

        // get all categories
        $categories = Category::select('id', 'name', 'image')
            ->orderBy('name')
            ->get();

        $paymentSetting = PaymentSetting::forOutlet($outlet);

        $carts_total = 0;
        foreach ($carts as $cart) {
            $carts_total += $cart->price;
        }

        $defaultGateway = $paymentSetting?->default_gateway ?? 'cash';
        if (
            $defaultGateway !== 'cash'
            && (! $paymentSetting || ! $paymentSetting->isGatewayReady($defaultGateway))
        ) {
            $defaultGateway = 'cash';
        }

        // Get active bank accounts for bank transfer
        $bankAccounts = BankAccount::active()->forOutlet($outlet)->ordered()->get();

        return Inertia::render('Dashboard/Transactions/Index', [
            'carts' => $carts,
            'carts_total' => $carts_total,
            'heldCarts' => $heldCarts,
            'openBills' => app(OpenBillListService::class)->rowsFor(auth()->user()),
            'payLaterEnabled' => $outlet ? Setting::getBoolForOutlet('pay_later_enabled', $outlet) : false,
            'customers' => $customers,
            'products' => $products,
            'categories' => $categories,
            'initialPricingPreview' => $initialPricingPreview,
            'paymentGateways' => $paymentSetting?->enabledGateways($outlet) ?? [],
            'defaultPaymentGateway' => $defaultGateway,
            'bankAccounts' => $bankAccounts,
            'warehouses' => $this->outletAccessService->salesWarehousesFor(auth()->user())->values(),
            'shiftSummary' => $this->cashierShiftService->summarizeForDisplay($activeShift),
            'loyaltyTierOptions' => $this->loyaltyService->tierOptions(),
            'addOn' => $activeShift && is_string(request('add_on_to')) ? app(AddOnOrderService::class)->preview(request('add_on_to'), $activeShift) : null,
        ]);
    }

    /**
     * The POS "Belum Lunas" list as JSON, polled by the browser: only the open-bills query, not the whole POS page.
     */
    public function openBills(): JsonResponse
    {
        return response()->json(['openBills' => app(OpenBillListService::class)->rowsFor(auth()->user())]);
    }

    /**
     * searchProduct
     *
     * @param  mixed  $request
     * @return void
     */
    public function searchProduct(Request $request)
    {
        $activeShift = $this->cashierShiftService->getActiveShiftForUser(auth()->user()->id);
        $warehouseId = $activeShift?->warehouse_id;

        $product = Product::where('barcode', $request->barcode)
            ->where('is_raw_material', false)
            ->where(fn ($q) => $q->where('is_composite', true)
                ->orWhereHas('warehouses', fn ($w) => $w->where('product_warehouse.warehouse_id', $warehouseId)))
            ->first();

        if ($product) {
            return response()->json([
                'success' => true,
                'data' => [
                    ...$product->toArray(),
                    'stock' => $product->stockIn($warehouseId),
                    'sell_price' => $product->is_composite ? $product->compositeSellPrice() : (int) $product->sell_price,
                ],
            ]);
        }

        return response()->json([
            'success' => false,
            'data' => null,
        ]);
    }

    public function previewPricing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'discount' => ['nullable', 'integer', 'min:0'],
            'shipping_cost' => ['nullable', 'integer', 'min:0'],
            'redeem_points' => ['nullable', 'integer', 'min:0'],
            'customer_voucher_id' => ['nullable', 'integer', 'exists:customer_vouchers,id'],
            'order_type' => ['nullable', 'in:in_store,takeaway,delivery'],
        ]);

        $customer = isset($validated['customer_id'])
            ? Customer::find($validated['customer_id'])
            : null;
        $voucher = isset($validated['customer_voucher_id'])
            ? CustomerVoucher::find($validated['customer_voucher_id'])
            : null;

        $carts = Cart::with('product.category')
            ->where('cashier_id', $request->user()->id)
            ->active()
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => $this->checkoutPreviewService->checkout($request->user(), $customer, [
                'manual_discount' => (int) ($validated['discount'] ?? 0),
                'shipping_cost' => (int) ($validated['shipping_cost'] ?? 0),
                'redeem_points' => (int) ($validated['redeem_points'] ?? 0),
                'voucher' => $voucher,
                'order_type' => $validated['order_type'] ?? null,
            ], $carts),
        ]);
    }

    /**
     * addToCart
     *
     * @param  mixed  $request
     * @return void
     */
    public function addToCart(Request $request)
    {
        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'qty' => ['required', 'numeric', 'min:0.01'],
            'unit_id' => ['nullable', 'integer'],
        ]);

        $activeShift = $this->cashierShiftService->getActiveShiftForUser(auth()->user()->id);
        $warehouseId = $activeShift?->warehouse_id;

        $product = Product::whereId($validated['product_id'])->first();
        $inCart = Cart::baseQtyInCart(auth()->id(), (int) $validated['product_id']);

        if (! $product) {
            return back()->withErrors(['message' => 'Product not found.']);
        }

        if ($product->is_raw_material) {
            return back()->withErrors(['message' => 'Bahan baku tidak dapat dijual.']);
        }

        // ponytail: composite branch skips unit logic; null unit_id is fine for composite carts
        $unitId = null;

        // Composite: check component stock
        if ($product->is_composite) {
            $product->load('components');
            foreach ($product->components as $component) {
                $needed = (float) $component->pivot->qty * ($validated['qty'] + $inCart);
                $whProduct = $component->warehouses()->where('warehouse_id', $warehouseId)->first();
                $avail = $whProduct?->pivot->stock ?? 0;
                if ($avail < $needed) {
                    return back()->withErrors(['message' => "Stok {$component->title} tidak mencukupi."]);
                }
            }
            $sellPrice = $product->compositeSellPrice();
        } else {
            $unitId = (int) (($validated['unit_id'] ?? null) ?: $product->baseUnit()?->id ?: 1);

            // Reject an explicitly provided unit that does not belong to this product
            if (! empty($validated['unit_id'] ?? null) && ! $product->units()->whereKey($unitId)->exists()) {
                return back()->withErrors(['message' => 'Satuan tidak valid untuk produk ini.']);
            }

            $unitConversion = app(UnitConversionService::class);
            $baseQty = $unitConversion->toBaseUnit($product, $unitId, $validated['qty']);

            $availableStock = $warehouseId
                ? (int) ($product->warehouses()->where('warehouse_id', $warehouseId)->first()?->pivot->stock ?? 0)
                : (int) $product->stock;

            if ($availableStock < $baseQty + $inCart) {
                return back()->withErrors(['message' => 'Stok tidak mencukupi. Tersedia: '.$availableStock]);
            }

            $sellPrice = $unitConversion->getSellPrice($product, $unitId);
            $pu = $product->units()->where('unit_id', $unitId)->first();
            $conversionFactor = $pu?->pivot->conversion_factor ?? 1;
        }
        if (! isset($conversionFactor)) {
            $conversionFactor = 1;
        }

        $cart = Cart::with('product')
            ->where('product_id', $validated['product_id'])
            ->where('unit_id', $unitId)
            ->where('cashier_id', auth()->user()->id)
            ->active()
            ->first();

        if ($cart) {
            $cart->increment('qty', $validated['qty']);
            $cart->price = $sellPrice * $cart->qty;
            $cart->save();
        } else {
            Cart::create([
                'cashier_id' => auth()->user()->id,
                'warehouse_id' => $warehouseId,
                'product_id' => $validated['product_id'],
                'unit_id' => $unitId,
                'conversion_factor' => $conversionFactor,
                'qty' => $validated['qty'],
                'price' => $sellPrice * $validated['qty'],
            ]);
        }

        return redirect()->route('transactions.index', $request->only('add_on_to'))->with('success', 'Product Added Successfully!.');
    }

    /**
     * destroyCart
     *
     * @param  mixed  $request
     * @return void
     */
    public function destroyCart($cart_id)
    {
        $cart = Cart::with('product')
            ->whereId($cart_id)
            ->where('cashier_id', auth()->user()->id)
            ->active()
            ->first();

        if ($cart) {
            $cart->delete();

            return back();
        } else {
            // Handle case where no cart is found (e.g., redirect with error message)
            return back()->withErrors(['message' => 'Cart not found']);
        }

    }

    /**
     * updateCart - Update cart item quantity
     *
     * @param  mixed  $request
     * @param  int  $cart_id
     * @return void
     */
    public function updateCart(Request $request, $cart_id)
    {
        $request->validate([
            'qty' => 'required|integer|min:1',
        ]);

        $activeShift = $this->cashierShiftService->getActiveShiftForUser(auth()->user()->id);
        $warehouseId = $activeShift?->warehouse_id;

        $cart = Cart::with('product')->whereId($cart_id)
            ->where('cashier_id', auth()->user()->id)
            ->first();

        if (! $cart) {
            return back()->withErrors(['message' => 'Cart item not found']);
        }

        $product = $cart->product;

        if (! $product) {
            return back()->withErrors(['message' => 'Produk tidak ditemukan']);
        }

        if ($product->is_composite) {
            $product->load('components');
        }

        // Check stock availability (convert display qty to base qty for the cart's unit)
        $unitConversion = app(UnitConversionService::class);
        $baseQty = $product->is_composite
            ? (int) $request->qty
            : $unitConversion->toBaseUnit($product, (int) $cart->unit_id, $request->qty);

        $availableStock = $product->stockIn($warehouseId);

        $otherRows = Cart::baseQtyInCart(auth()->id(), $cart->product_id, $cart->id);

        if ($availableStock < $baseQty + $otherRows) {
            return back()->withErrors(['message' => 'Stok tidak mencukupi. Tersedia: '.$availableStock]);
        }

        // Update quantity and price (derive price from DB, not from request/global sell_price)
        $cart->qty = $request->qty;
        $cart->price = $product->is_composite
            ? $product->compositeSellPrice() * $request->qty
            : $unitConversion->getSellPrice($product, (int) $cart->unit_id) * $request->qty;
        $cart->save();

        return back()->with('success', 'Quantity updated successfully');
    }

    /**
     * holdCart - Hold current cart items for later
     *
     * @return JsonResponse
     */
    public function holdCart(Request $request)
    {
        $request->validate([
            'label' => 'nullable|string|max:50',
        ]);

        $userId = auth()->user()->id;

        // Get active cart items
        $activeCarts = Cart::where('cashier_id', $userId)
            ->active()
            ->get();

        if ($activeCarts->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Keranjang kosong, tidak ada yang bisa ditahan',
            ], 422);
        }

        // Generate unique hold ID
        $holdId = 'HOLD-'.strtoupper(uniqid());
        $label = $request->label ?: 'Transaksi '.now()->format('H:i');

        // Mark all active cart items as held
        Cart::where('cashier_id', $userId)
            ->active()
            ->update([
                'hold_id' => $holdId,
                'hold_label' => $label,
                'held_at' => now(),
            ]);

        return back()->with('success', 'Transaksi ditahan: '.$label);
    }

    /**
     * resumeCart - Resume a held cart
     *
     * @param  string  $holdId
     * @return JsonResponse
     */
    public function resumeCart($holdId)
    {
        $userId = auth()->user()->id;

        // Check if there are any active carts (not held)
        $activeCarts = Cart::where('cashier_id', $userId)
            ->active()
            ->count();

        if ($activeCarts > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Selesaikan atau tahan transaksi aktif terlebih dahulu',
            ], 422);
        }

        // Get held carts
        $heldCarts = Cart::where('cashier_id', $userId)
            ->forHold($holdId)
            ->get();

        if ($heldCarts->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Transaksi ditahan tidak ditemukan',
            ], 404);
        }

        // Resume by clearing hold info
        Cart::where('cashier_id', $userId)
            ->forHold($holdId)
            ->update([
                'hold_id' => null,
                'hold_label' => null,
                'held_at' => null,
            ]);

        return back()->with('success', 'Transaksi dilanjutkan');
    }

    /**
     * clearHold - Delete a held cart
     *
     * @param  string  $holdId
     * @return JsonResponse
     */
    public function clearHold($holdId)
    {
        $userId = auth()->user()->id;

        $deleted = Cart::where('cashier_id', $userId)
            ->forHold($holdId)
            ->delete();

        if ($deleted === 0) {
            return request()->wantsJson()
                ? response()->json([
                    'success' => false,
                    'message' => 'Transaksi ditahan tidak ditemukan',
                ], 404)
                : back()->with('error', 'Transaksi ditahan tidak ditemukan');
        }

        if (request()->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Transaksi ditahan berhasil dihapus',
            ]);
        }

        return back()->with('success', 'Transaksi ditahan berhasil dihapus');
    }

    /**
     * getHeldCarts - Get all held carts for current user
     *
     * @return JsonResponse
     */
    public function getHeldCarts()
    {
        $userId = auth()->user()->id;

        $heldCarts = Cart::with('product:id,title,sell_price,image')
            ->where('cashier_id', $userId)
            ->held()
            ->get()
            ->groupBy('hold_id')
            ->map(function ($items, $holdId) {
                $first = $items->first();

                return [
                    'hold_id' => $holdId,
                    'label' => $first->hold_label,
                    'held_at' => $first->held_at,
                    'items_count' => $items->sum('qty'),
                    'total' => $items->sum('price'),
                    'items' => $items->map(fn ($item) => [
                        'id' => $item->id,
                        'product' => $item->product,
                        'qty' => $item->qty,
                        'price' => $item->price,
                    ]),
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'held_carts' => $heldCarts,
        ]);
    }

    /**
     * store
     *
     * @param  mixed  $request
     * @return void
     */
    public function store(Request $request, PaymentGatewayManager $paymentGatewayManager)
    {
        $isPayLater = $request->boolean('pay_later');
        $paymentGateway = $isPayLater ? null : $request->input('payment_gateway');
        if ($paymentGateway) {
            $paymentGateway = strtolower($paymentGateway);
        }
        $activeShift = $this->cashierShiftService->getActiveShiftForUser($request->user()->id);
        $activeShift?->load('warehouse.outlet');
        $outlet = $activeShift?->warehouse?->outlet;
        $orderType = in_array($request->input('order_type'), ['in_store', 'takeaway', 'delivery'])
            ? $request->input('order_type')
            : null;
        $note = trim((string) $request->input('note', '')) ?: null;

        $customer = $request->filled('customer_id') ? Customer::find($request->integer('customer_id')) : null;

        if ($isPayLater) {
            if (! $outlet || ! Setting::getBoolForOutlet('pay_later_enabled', $outlet)) {
                throw ValidationException::withMessages(['pay_later' => 'Bayar belakangan tidak aktif di outlet ini']);
            }

            // A guest has no customer record to chase, so the note must say who owes.
            if (! $customer && mb_strlen((string) $note) < 2) {
                throw ValidationException::withMessages(['note' => 'Catatan minimal 2 karakter wajib diisi untuk bayar belakangan tanpa pelanggan.']);
            }

            if ($customer?->may_buy_on_credit && ! $request->filled('due_date')) {
                return redirect()
                    ->route('transactions.index', $request->only('add_on_to'))
                    ->with('error', 'Tanggal jatuh tempo wajib diisi untuk nota barang.');
            }
        }

        $paymentSetting = null;
        if ($paymentGateway) {
            $paymentSetting = PaymentSetting::forOutlet($outlet);

            $gatewayReady = $paymentSetting && ($paymentGateway === 'qris'
                ? $paymentSetting->isGatewayReady(PaymentSetting::GATEWAY_MIDTRANS)
                    || $paymentSetting->isGatewayReady(PaymentSetting::GATEWAY_XENDIT)
                : $paymentSetting->isGatewayReady($paymentGateway));

            if (! $gatewayReady) {
                return redirect()
                    ->route('transactions.index', $request->only('add_on_to'))
                    ->with('error', 'Gateway pembayaran belum dikonfigurasi.');
            }
        }

        $tenderInput = is_array($request->input('tenders')) ? $request->input('tenders') : [];
        $useTenders = ! $isPayLater && $tenderInput !== [];
        // ponytail: legacy single-method path kept — split tenders only activate when payload has tenders[]
        $isCashPayment = empty($paymentGateway) && ! $isPayLater && ! $useTenders;
        $cashAmount = $isCashPayment ? max(0, (int) $request->cash) : 0;

        $context = new CheckoutContext(
            userId: $request->user()->id,
            customer: $customer,
            voucher: $request->filled('customer_voucher_id') ? CustomerVoucher::find($request->integer('customer_voucher_id')) : null,
            manualDiscount: max(0, (int) $request->input('discount', 0)),
            shippingCost: max(0, (int) $request->input('shipping_cost', 0)),
            requestedRedeemPoints: max(0, (int) $request->input('redeem_points', 0)),
            isPayLater: $isPayLater,
            dueDate: $request->input('due_date'),
            orderType: $orderType,
            note: $note,
            customerNpwp: $request->input('customer_npwp'),
            isCashPayment: $isCashPayment,
            cashAmount: $cashAmount,
            paymentGateway: $paymentGateway,
            useTenders: $useTenders,
            tenderInput: $tenderInput,
            outlet: $outlet,
            bankAccountId: $request->input('bank_account_id'),
            addOnTo: $request->validate(['add_on_to' => ['nullable', 'string']])['add_on_to'] ?? null,
        );

        $result = $this->checkoutService->execute($context);
        $request->session()->flash('success', 'Transaksi berhasil!'); // saved: the POS toasts only this flash (#473)

        if ($result->needsDiscountApproval) {
            $result->transaction->update([
                'discount_approval_status' => 'pending',
                'payment_status' => 'pending_approval',
            ]);

            DiscountApprovalLog::create([
                'transaction_id' => $result->transaction->id,
                'cashier_id' => $request->user()->id,
                'requested_discount' => $result->appliedManualDiscount,
                'status' => 'pending',
            ]);

            return redirect()
                ->route('transactions.print', $result->transaction->invoice)
                ->with('info', 'Transaksi menunggu approval supervisor.');
        }

        if ($useTenders) {
            $gatewayTenders = $result->transaction->tenders()
                ->whereIn('method', [TransactionTender::METHOD_MIDTRANS, TransactionTender::METHOD_XENDIT, TransactionTender::METHOD_QRIS])
                ->where('payment_status', TransactionTender::STATUS_PENDING)
                ->get();

            try {
                foreach ($gatewayTenders as $tender) {
                    $response = $paymentGatewayManager->createTenderPayment(
                        $result->transaction,
                        $tender,
                        $paymentSetting ?? PaymentSetting::forOutlet($outlet),
                    );

                    $tender->update([
                        'payment_reference' => $response['reference'] ?? null,
                        'payment_url' => $response['payment_url'] ?? null,
                        'qr_string' => $response['qr_string'] ?? null,
                    ]);
                }

                if ($gatewayTenders->count() === 1) {
                    $result->transaction->update($gatewayTenders->first()->only(['payment_reference', 'payment_url', 'qr_string']));
                }
            } catch (PaymentGatewayException $exception) {
                return redirect()
                    ->route('transactions.print', $result->transaction->invoice)
                    ->with('error', $exception->getMessage());
            }
        } elseif ($paymentGateway) {
            try {
                $paymentResponse = $paymentGateway === 'qris'
                    ? $paymentGatewayManager->createQrisPayment($result->transaction, $paymentSetting)
                    : $paymentGatewayManager->createPayment($result->transaction, $paymentGateway, $paymentSetting);

                $result->transaction->update([
                    'payment_reference' => $paymentResponse['reference'] ?? null,
                    'payment_url' => $paymentResponse['payment_url'] ?? null,
                    'qr_string' => $paymentResponse['qr_string'] ?? null,
                ]);
            } catch (PaymentGatewayException $exception) {
                return redirect()
                    ->route('transactions.print', $result->transaction->invoice)
                    ->with('error', $exception->getMessage());
            }
        }

        return to_route('transactions.print', $result->transaction->invoice);
    }

    public function print(Request $request, $invoice)
    {
        // get transaction
        $transaction = Transaction::with('details.product', 'details.pricingRule', 'cashier', 'customer', 'receivable', 'bankAccount', 'tenders', 'parent:id,invoice')
            ->where('invoice', $invoice)
            ->firstOrFail();
        $this->ensureTransactionAccess($request, $transaction);

        // Pay-another-way choices are only needed while the sale can be retried.
        $retryable = $this->paymentRetryService->isRetryable($transaction);
        $transaction->forceFill(['retry_amount' => $retryable ? $transaction->amountDue() : null, 'amount_due' => $transaction->reducedAmountDue(), ...$transaction->cashRefundNotice()]);
        $outlet = $retryable ? $transaction->warehouse?->outlet : null;

        return Inertia::render('Dashboard/Transactions/Print', [
            'transaction' => $transaction,
            'retryable' => $retryable,
            'canAddOn' => app(AddOnOrderService::class)->offersAddOn($transaction),
            'bankAccounts' => $retryable ? BankAccount::active()->forOutlet($outlet)->ordered()->get(['id', 'bank_name', 'account_number', 'account_name']) : [],
            'paymentGateways' => $retryable ? PaymentSetting::forOutlet($outlet)?->enabledGateways($outlet) ?? [] : [],
        ]);
    }

    public function status(Request $request, $invoice)
    {
        $transaction = Transaction::where('invoice', $invoice)
            ->firstOrFail();
        $this->ensureTransactionAccess($request, $transaction);

        return response()->json([
            'payment_status' => $transaction->payment_status,
        ]);
    }

    public function qrisImage(Request $request, $invoice)
    {
        $transaction = Transaction::where('invoice', $invoice)
            ->firstOrFail();
        $this->ensureTransactionAccess($request, $transaction);

        abort_unless(filled($transaction->qr_string), 404);

        $svg = QrCode::format('svg')->size(300)->margin(2)->generate($transaction->qr_string);

        return response($svg, 200, ['Content-Type' => 'image/svg+xml']);
    }

    /**
     * Display transaction history.
     */
    public function history(Request $request)
    {
        $salesReturnTablesReady = Schema::hasTable('sales_returns') && Schema::hasTable('sales_return_items');

        $filters = [
            'invoice' => $request->input('invoice'),
            'start_date' => $request->input('start_date'),
            'end_date' => $request->input('end_date'),
            'warehouse_id' => $request->input('warehouse_id'),
            'late_payment' => $request->boolean('late_payment'),
            'paid_after_close' => $request->boolean('paid_after_close'),
        ];

        $query = Transaction::query()
            ->with([
                'cashier:id,name',
                'warehouse:id,code,name',
                'cashierShift:id,opened_at,status',
                'customer:id,name',
                'receivable',
                'dineOrder:id,transaction_id,dine_table_id,status',
                'dineOrder.table:id,name',
            ])
            ->withSum('details as total_items', 'qty')
            ->withSum('profits as total_profit', 'total')
            ->orderByDesc('created_at');

        $warehouseIds = $this->outletAccessService->warehousesFor($request->user())->pluck('id');
        $query->where(function (Builder $builder) use ($warehouseIds) {
            $builder->whereIn('warehouse_id', $warehouseIds);

            if (Outlet::active()->count() <= 1) {
                $builder->orWhereNull('warehouse_id');
            }
        });

        if ($salesReturnTablesReady) {
            $query->with('details.salesReturnItems.salesReturn:id,status')->withScreenAmounts();
        }

        if (! $request->user()->isSuperAdmin()) {
            $query->where('cashier_id', $request->user()->id);
        }

        $query
            ->when($filters['invoice'], function (Builder $builder, $invoice) {
                $builder->where('invoice', 'like', '%'.$invoice.'%');
            })
            ->when($filters['start_date'], function (Builder $builder, $date) {
                $builder->whereDate('created_at', '>=', $date);
            })
            ->when($filters['end_date'], function (Builder $builder, $date) {
                $builder->whereDate('created_at', '<=', $date);
            })
            ->when($filters['warehouse_id'], function (Builder $builder, $warehouseId) {
                $builder->where('warehouse_id', $warehouseId);
            })
            // sales whose superseded attempt was paid after a retry: money to refund by hand
            ->when($filters['late_payment'], fn (Builder $builder) => $builder->whereNotNull('late_superseded_payment_at'))
            // gateway sales paid after their shift closed: the closed recap was left as it was
            ->when($filters['paid_after_close'], fn (Builder $builder) => $builder->whereNotNull('paid_after_close_at'));

        $transactions = $query->paginate($this->perPage())->withQueryString();
        $warehouses = $this->outletAccessService->warehousesFor($request->user());
        $transactions->through(function (Transaction $transaction) use ($salesReturnTablesReady) {
            $canCreateSalesReturn = false;

            if ($salesReturnTablesReady) {
                $allReturned = true;

                foreach ($transaction->details as $detail) {
                    $returnedQty = (int) $detail->salesReturnItems
                        ->filter(fn ($item) => $item->salesReturn?->status === 'completed')
                        ->sum('qty_return');

                    if ($returnedQty < (int) $detail->qty) {
                        $allReturned = false;
                        break;
                    }
                }

                $canCreateSalesReturn = $transaction->details->isNotEmpty() && ! $allReturned;
            }

            return [
                ...$transaction->toArray(),
                'can_create_sales_return' => $canCreateSalesReturn,
                // what the dialogs ask: after returns, not the grand total
                ...($salesReturnTablesReady ? $transaction->screenAmounts() : ['owed_total' => (int) $transaction->grand_total]),
            ];
        });

        $billOutlet = $this->outletAccessService->activeOutlet($request);

        return Inertia::render('Dashboard/Transactions/History', [
            'transactions' => $transactions,
            'filters' => $filters,
            'salesReturnFeatureReady' => $salesReturnTablesReady,
            'warehouses' => $warehouses,
            // for the "Bayar" modal on an unpaid dine-in bill
            'bankAccounts' => BankAccount::active()->forOutlet($billOutlet)->ordered()->get(['id', 'bank_name', 'account_number', 'account_name']),
            'paymentGateways' => PaymentSetting::forOutlet($billOutlet)?->enabledGateways($billOutlet) ?? [],
        ]);
    }

    /**
     * Confirm payment for bank transfer transactions
     */
    public function confirmPayment(Request $request, Transaction $transaction)
    {
        $this->ensureTransactionAccess($request, $transaction);
        if ($transaction->payment_status === 'paid') {
            return redirect()
                ->back()
                ->with('error', 'Transaksi sudah dibayar.');
        }

        $tenderService = app(TransactionTenderService::class);

        if (! $tenderService->hasTransfer($transaction)) {
            return redirect()
                ->back()
                ->with('error', 'Hanya transaksi transfer bank yang dapat dikonfirmasi pembayarannya.');
        }

        if (! $transaction->bank_account_id) {
            return redirect()
                ->back()
                ->with('error', 'Transaksi transfer bank belum memiliki rekening tujuan.');
        }

        if (! in_array($transaction->payment_status, ['pending', 'pending_approval'], true)) {
            return redirect()
                ->back()
                ->with('error', 'Status transaksi tidak dapat dikonfirmasi saat ini.');
        }

        $beforeStatus = $transaction->payment_status;
        $newStatus = $tenderService->confirmTransfer($transaction);

        $this->auditLogService->log(
            event: 'transaction.payment_confirmed',
            module: 'transactions',
            auditable: $transaction,
            description: "Pembayaran untuk invoice {$transaction->invoice} dikonfirmasi.",
            before: [
                'invoice' => $transaction->invoice,
                'payment_method' => $transaction->payment_method,
                'payment_status' => $beforeStatus,
                'bank_account_id' => $transaction->bank_account_id,
            ],
            after: [
                'invoice' => $transaction->invoice,
                'payment_method' => $transaction->payment_method,
                'payment_status' => $newStatus,
                'bank_account_id' => $transaction->bank_account_id,
            ],
            meta: [
                'invoice' => $transaction->invoice,
                'bank_account_id' => $transaction->bank_account_id,
            ],
        );

        return redirect()
            ->back()
            ->with('success', "Pembayaran untuk invoice {$transaction->invoice} berhasil dikonfirmasi.");
    }

    protected function ensureTransactionAccess(Request $request, Transaction $transaction): void
    {
        $warehouse = $transaction->warehouse_id ? $transaction->warehouse : null;
        abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 404);
    }
}
