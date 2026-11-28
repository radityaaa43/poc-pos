<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductWarehouse;
use App\Models\Receivable;
use App\Models\Transaction;
use App\Models\TransactionTender;
use App\Services\Modifiers\ModifierSelection;
use App\Support\Checkout\CheckoutContext;
use App\Support\Checkout\CheckoutResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private readonly CashierShiftService $cashierShiftService,
        private readonly PricingService $pricingService,
        private readonly LoyaltyService $loyaltyService,
        private readonly PriceListService $priceListService,
        private readonly BatchService $batchService,
        private readonly TransactionTenderService $tenderService,
        private readonly StockMutationService $stockMutationService,
        private readonly InventoryCostService $inventoryCostService,
        private readonly KitchenIntakeService $kitchenIntake,
        private readonly ComponentStockService $componentStock,
        private readonly AddOnOrderService $addOnOrderService,
    ) {}

    public function execute(CheckoutContext $ctx): CheckoutResult
    {
        // Looked up outside the transaction: its first plain read fixes MySQL's snapshot, and that must follow the locks.
        $original = $ctx->addOnTo ? Transaction::where('invoice', $ctx->addOnTo)->first() : null;

        return DB::transaction(function () use ($ctx, $original) {
            $activeShift = $this->cashierShiftService->requireActiveShiftForUser(
                $ctx->userId,
                lockForUpdate: true,
            );

            // Under a lock on the original, which is only read (BR-5), so a cancellation that committed meanwhile is seen.
            $parentId = $ctx->addOnTo ? $this->addOnOrderService->resolveForPos($original, $activeShift) : null;

            $carts = Cart::with('product')
                ->where('cashier_id', $ctx->userId)
                ->active()
                ->when($ctx->onlyCartIds, fn ($q, $ids) => $q->whereIn('id', $ids))
                ->get();

            if ($carts->isEmpty()) {
                abort(422, 'Keranjang kosong.');
            }

            $selections = ModifierSelection::forCheckout($carts, $ctx->outlet, $activeShift->warehouse_id);

            $pricingPreview = $this->pricingService->previewCart($carts, $ctx->customer, null, $ctx->outlet);
            $checkoutPreview = $this->loyaltyService->previewCheckout(
                $pricingPreview,
                $ctx->customer,
                [
                    'manual_discount' => $ctx->manualDiscount,
                    'shipping_cost' => $ctx->shippingCost,
                    'redeem_points' => $ctx->requestedRedeemPoints,
                    'voucher' => $ctx->voucher,
                    'order_type' => $ctx->orderType,
                ],
                null,
                $ctx->outlet,
            );

            $grandTotal = (int) data_get($checkoutPreview, 'summary.grand_total', 0);
            $appliedManualDiscount = (int) data_get($checkoutPreview, 'summary.manual_discount_total', 0);
            $loyaltyDiscount = (int) data_get($checkoutPreview, 'summary.loyalty_discount_total', 0);
            $voucherDiscount = (int) data_get($checkoutPreview, 'summary.voucher_discount_total', 0);
            $subtotalAfterPromo = (int) data_get($pricingPreview, 'summary.subtotal_after_promo', 0);

            if ($ctx->isCashPayment && $ctx->cashAmount < $grandTotal) {
                throw ValidationException::withMessages([
                    'cash' => 'Uang tunai kurang dari total belanja.',
                ]);
            }

            $tenders = $ctx->useTenders
                ? $this->tenderService->normalize($ctx->tenderInput, $grandTotal, allowEmpty: false, outlet: $ctx->outlet)
                : [];

            $tenderCash = (int) collect($tenders)->where('method', TransactionTender::METHOD_CASH)->sum('cash_received');
            $tenderChange = (int) collect($tenders)->sum('change');
            $paymentMethod = $ctx->useTenders
                ? (count($tenders) > 1 ? 'split' : $tenders[0]['method'])
                : ($ctx->isPayLater ? 'pay_later' : ($ctx->paymentGateway ?: 'cash'));
            $paymentStatus = $ctx->useTenders
                ? $this->tenderService->aggregateStatus($tenders)
                : ($ctx->isCashPayment ? 'paid' : ($ctx->isPayLater ? 'unpaid' : 'pending'));
            $tenderBankAccountId = $ctx->useTenders
                ? (collect($tenders)->firstWhere('bank_account_id', '!==', null)['bank_account_id'] ?? null)
                : ($ctx->paymentGateway === 'bank_transfer' ? $ctx->bankAccountId : null);

            $transaction = Transaction::create([
                'cashier_id' => $ctx->userId,
                'cashier_shift_id' => $activeShift->id,
                'parent_transaction_id' => $parentId,
                'warehouse_id' => $activeShift->warehouse_id,
                'customer_id' => $ctx->customer?->id,
                'invoice' => 'TRX-'.strtoupper(Str::random(10)),
                'cash' => $ctx->useTenders ? $tenderCash : $ctx->cashAmount,
                'change' => $ctx->useTenders ? $tenderChange : max(0, $ctx->cashAmount - $grandTotal),
                'discount' => $appliedManualDiscount,
                'loyalty_points_redeemed' => (int) data_get($checkoutPreview, 'summary.applied_redeem_points', 0),
                'loyalty_discount_total' => $loyaltyDiscount,
                'customer_voucher_discount' => $voucherDiscount,
                'customer_voucher_code' => data_get($checkoutPreview, 'voucher.code'),
                'customer_voucher_name' => data_get($checkoutPreview, 'voucher.name'),
                'shipping_cost' => $ctx->shippingCost,
                'grand_total' => $grandTotal,
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'is_pay_later' => $ctx->isPayLater,
                'bank_account_id' => $tenderBankAccountId,
                'order_type' => $ctx->orderType,
                'note' => $ctx->note,
                'tax_rate' => data_get($checkoutPreview, 'summary.tax_rate'),
                'tax_total' => data_get($checkoutPreview, 'summary.tax_total', 0),
                'service_charge' => data_get($checkoutPreview, 'summary.service_charge'),
                'service_charge_rate' => data_get($checkoutPreview, 'summary.service_charge_rate'),
                'tax_kind' => data_get($checkoutPreview, 'summary.tax_kind'),
                'tax_base' => data_get($checkoutPreview, 'summary.tax_base'),
                'customer_npwp' => $ctx->customerNpwp,
                'price_list_id' => $this->priceListService->getApplicablePriceList($ctx->customer, $ctx->outlet)?->id,
                'client_uuid' => $ctx->clientUuid,
                'sync_fingerprint' => $ctx->syncFingerprint,
            ]);

            if ($ctx->useTenders) {
                $transaction->tenders()->createMany($tenders);
            }

            if ($parentId) {
                $this->addOnOrderService->record($transaction);
            }

            $this->processCartItems($transaction, $carts, $selections, $pricingPreview, $ctx->outlet, $subtotalAfterPromo, $appliedManualDiscount, $activeShift->warehouse_id, $ctx->userId);

            // The sale is created already paid, before its lines exist, so the Transaction `updated` hook never sees it.
            // Money first: a kitchen-intake failure is reported, never allowed to undo a payment.
            // A pay-later sale is unpaid but goes to the kitchen at once (its settle later never intakes: see Transaction's hook).
            DB::afterCommit(fn () => rescue(fn () => $ctx->isPayLater
                ? $this->kitchenIntake->intakeUnpaid($transaction)
                : $this->kitchenIntake->intake($transaction)));

            Cart::where('cashier_id', $ctx->userId)
                ->active()
                ->when($ctx->onlyCartIds, fn ($q, $ids) => $q->whereIn('id', $ids))
                ->delete();

            $this->loyaltyService->finalizeTransaction($transaction, $ctx->customer, $checkoutPreview);

            // Only a customer with "Boleh piutang" owes a receivable; a guest or other customer leaves an unpaid sale without one.
            if ($ctx->isPayLater && $ctx->customer?->may_buy_on_credit) {
                Receivable::create([
                    'customer_id' => $ctx->customer->id,
                    'transaction_id' => $transaction->id,
                    'invoice' => $transaction->invoice,
                    'total' => $grandTotal,
                    'paid' => 0,
                    'due_date' => $ctx->dueDate,
                    'status' => 'unpaid',
                ]);
            }

            $needsDiscountApproval = $appliedManualDiscount > 0 && $transaction->needsDiscountApproval();

            return new CheckoutResult(
                transaction: $transaction->fresh(['customer']),
                pricingPreview: $pricingPreview,
                checkoutPreview: $checkoutPreview,
                needsDiscountApproval: $needsDiscountApproval,
                appliedManualDiscount: $appliedManualDiscount,
                tenders: $tenders,
                paymentMethod: $paymentMethod,
                paymentStatus: $paymentStatus,
                tenderBankAccountId: $tenderBankAccountId,
            );
        }, 3); // retried on a deadlock: an add-on holds sales of a shift that another cashier may be closing
    }

    private function processCartItems(
        Transaction $transaction,
        $carts,
        array $selections,
        array $pricingPreview,
        $outlet,
        int $subtotalAfterPromo,
        int $appliedManualDiscount,
        ?int $warehouseId,
        int $userId,
    ): void {
        $pricingItems = collect($pricingPreview['items']);
        // withTrashed: an option deleted since the check above still has the recipe this sale was priced with.
        $recipes = ModifierOption::withTrashed()->with('components')
            ->findMany(collect($selections)->pluck('options.*.id')->flatten()->all())->keyBy('id');
        $this->componentStock->lock($carts->pluck('product')
            ->flatMap(fn (Product $product) => $product->is_composite ? $product->components->modelKeys() : [$product->id])
            ->merge($recipes->flatMap->components->pluck('id')), $warehouseId);

        foreach ($carts as $cart) {
            $pricingItem = $pricingItems->firstWhere('cart_id', $cart->id);
            $lineTotal = (int) data_get($pricingItem, 'line_total', $cart->price);
            $linePromoDiscount = (int) data_get($pricingItem, 'line_discount_total', 0);
            $baseUnitPrice = (int) data_get($pricingItem, 'base_unit_price', $cart->product->sell_price);
            $unitPrice = (int) data_get($pricingItem, 'effective_unit_price', $cart->product->sell_price);

            $product = Product::find($cart->product_id);

            if ($product->is_raw_material) {
                throw ValidationException::withMessages([
                    'stock' => "{$product->title} adalah bahan baku dan tidak dapat dijual.",
                ]);
            }

            $selection = $selections[$cart->id] ?? null;
            $options = $selection['options'] ?? [];

            // Frozen HPP: read the warehouse average before the stock decrement below (REQ-002 §6.6).
            $costTotal = $this->inventoryCostService->lineCost($product, $warehouseId, $cart->qty, (float) ($cart->conversion_factor ?? 1));
            $costTotal += $this->inventoryCostService->recipeCost(
                collect($options)->flatMap(fn (array $option) => $recipes[$option['id']]->components), $warehouseId, $cart->qty
            );

            $detail = $transaction->details()->create([
                'transaction_id' => $transaction->id,
                'product_id' => $cart->product_id,
                'unit_id' => $cart->unit_id,
                'conversion_factor' => $cart->conversion_factor,
                'qty' => $cart->qty,
                'base_unit_price' => $baseUnitPrice,
                'unit_price' => $unitPrice,
                'price' => $lineTotal,
                'discount_total' => $linePromoDiscount,
                'cost_total' => $costTotal,
                'needs_preparation' => $product->needs_preparation, // frozen: intake and self-heal read the sale-time flag
                'pricing_rule_id' => data_get($pricingItem, 'pricing_rule.id'),
                'pricing_rule_name' => data_get($pricingItem, 'pricing_rule.name'),
                'pricing_rule_kind' => data_get($pricingItem, 'pricing_rule.kind'),
                'pricing_group_key' => data_get($pricingItem, 'pricing_group_key'),
                'pricing_group_label' => data_get($pricingItem, 'pricing_group_label'),
                'note' => $cart->note,
                'modifier_total' => $selection['unit_total'] ?? 0,
            ]);
            $detail->modifiers()->createMany(ModifierSelection::snapshot($options, $recipes));

            foreach ($options as $option) {
                $refusal = __('messages.modifiers.option_sold_out', ['menu' => $product->title, 'option' => $option['name']]);
                $this->componentStock->deduct($transaction, $recipes[$option['id']]->components, 1, (int) $cart->qty, $warehouseId, "opsi {$option['name']} {$product->title}", $userId, $refusal);
            }

            $lineShare = $subtotalAfterPromo > 0 ? $lineTotal / $subtotalAfterPromo : 0;
            $allocatedManualDiscount = (int) round($appliedManualDiscount * $lineShare);
            $netSellPrice = max(0, $lineTotal - $allocatedManualDiscount);
            $profits = $netSellPrice - $costTotal;

            $transaction->profits()->create([
                'transaction_id' => $transaction->id,
                'total' => $profits,
            ]);

            if ($product->is_composite) {
                $product->load('components');
                $this->componentStock->deduct($transaction, $product->components->whereInstanceOf(Product::class), 1, (int) $cart->qty, $warehouseId, "komponen komposit {$product->title}", $userId);
            } else {
                $baseQty = (int) round($cart->qty * (float) ($cart->conversion_factor ?? 1));

                $pw = $warehouseId
                    ? ProductWarehouse::where([
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouseId,
                    ])->lockForUpdate()->first()
                    : null;
                // Read fresh: this line's options may have taken from the menu since $product was loaded.
                $available = (int) ($pw ? $pw->stock : Product::lockForUpdate()->findOrFail($product->id)->stock);
                if ($available < $baseQty) {
                    throw ValidationException::withMessages([
                        'stock' => "Stok {$product->title} tidak mencukupi. Tersedia: {$available}.",
                    ]);
                }
                $stockBefore = $available;
                if ($pw) {
                    $pw->decrement('stock', $baseQty);
                }
                $product->decrement('stock', $baseQty);

                $this->stockMutationService->recordMutation(
                    product: $product,
                    warehouseId: $warehouseId,
                    referenceType: 'transaction',
                    referenceId: $transaction->id,
                    mutationType: 'out',
                    qty: $baseQty,
                    stockBefore: $stockBefore,
                    stockAfter: $stockBefore - $baseQty,
                    notes: "Penjualan {$transaction->invoice}",
                    userId: $userId,
                );

                if ($warehouseId) {
                    $batches = ProductBatch::where('product_id', $product->id)
                        ->where('warehouse_id', $warehouseId)
                        ->where('stock', '>', 0)
                        ->where(function (Builder $q) {
                            $q->whereNull('expired_at')->orWhere('expired_at', '>', now());
                        })
                        ->orderBy('expired_at')
                        ->orderBy('received_at')
                        ->lockForUpdate()
                        ->get();

                    if ($batches->isNotEmpty()) {
                        $covered = (int) $batches->sum('stock');
                        if ($covered < $baseQty) {
                            throw ValidationException::withMessages([
                                'stock' => "Stok batch {$product->title} tidak mencukupi. Tersedia: {$covered}.",
                            ]);
                        }
                        $remaining = $baseQty;
                        $firstBatchId = null;
                        foreach ($batches as $batch) {
                            if ($remaining <= 0) {
                                break;
                            }
                            $take = min((int) $batch->stock, $remaining);
                            $batch->decrement('stock', $take);
                            $remaining -= $take;
                            $firstBatchId ??= $batch->id;
                            $detail->batchAllocations()->create([
                                'product_batch_id' => $batch->id,
                                'qty' => $take,
                            ]);
                        }
                        $detail->update(['product_batch_id' => $firstBatchId]);
                    }
                }
            }
        }
    }
}
