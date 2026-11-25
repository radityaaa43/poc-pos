<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\ProductBatch;
use App\Models\ProductWarehouse;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DineOrderService
{
    public function __construct(
        private PricingService $pricingService,
        private StockMutationService $stockMutationService,
        private InventoryCostService $inventoryCostService,
        private CashierShiftService $cashierShiftService,
        private TransactionTenderService $tenderService,
    ) {}

    public function accept(DineOrder $order): void
    {
        $cashierId = $order->cashier_id ?? auth()->id();
        $shift = CashierShift::with('warehouse.outlet')
            ->where('user_id', $cashierId)
            ->open()
            ->first();

        // ponytail: without an open shift there is no warehouse context to take stock from — refuse instead of accepting an order whose stock is never decremented
        if (! $shift) {
            throw ValidationException::withMessages([
                'shift' => 'Tidak ada shift kasir aktif. Buka shift terlebih dahulu sebelum menerima pesanan.',
            ]);
        }

        $warehouseId = $shift->warehouse_id;

        DB::transaction(function () use ($order, $shift, $warehouseId, $cashierId) {
            // ponytail: lock the order row so accept cannot race with a second accept/reject
            $order = DineOrder::with(['items.product.components', 'table.area.outlet'])
                ->whereKey($order->id)
                ->lockForUpdate()
                ->firstOrFail();

            $tableOutletId = $order->table?->area?->outlet_id;
            $shiftOutletId = $shift->warehouse?->outlet_id;

            if ($tableOutletId && $shiftOutletId && $tableOutletId !== $shiftOutletId) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift kasir harus berada di outlet yang sama dengan meja.',
                ]);
            }

            $userId = $cashierId;

            // ponytail: accepting a dine order is a sale — record a Transaction so revenue, profit, stock ledger and stock mutations all stay in sync with the POS path
            $transaction = Transaction::create([
                'cashier_id' => $userId,
                'cashier_shift_id' => $shift->id,
                'warehouse_id' => $warehouseId,
                'customer_id' => $order->customer_id,
                'invoice' => 'TRX-'.strtoupper(Str::random(10)),
                'cash' => 0,
                'change' => 0,
                'discount' => 0,
                'grand_total' => (int) $order->subtotal,
                'payment_method' => Transaction::METHOD_OPEN_BILL, // chosen by the cashier when the bill is settled
                'payment_status' => 'unpaid',
                'order_type' => 'in_store',
                'note' => $order->notes,
            ]);

            foreach ($order->items as $item) {
                $product = $item->product;
                $lineTotal = (int) $item->price * (int) $item->qty;

                // Frozen HPP: read the warehouse average before the stock decrement below (REQ-002 §6.6).
                $costTotal = $this->inventoryCostService->lineCost($product, $warehouseId, (int) $item->qty, (float) ($item->conversion_factor ?? 1));

                $detail = $transaction->details()->create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $product->id,
                    'unit_id' => $item->unit_id,
                    'conversion_factor' => $item->conversion_factor ?? 1,
                    'qty' => (int) $item->qty,
                    'base_unit_price' => (int) $item->price,
                    'unit_price' => (int) $item->price,
                    'price' => $lineTotal,
                    'discount_total' => 0,
                    'cost_total' => $costTotal,
                ]);

                $transaction->profits()->create([
                    'transaction_id' => $transaction->id,
                    'total' => $lineTotal - $costTotal,
                ]);

                if ($product->is_composite) {
                    foreach ($product->components as $component) {
                        $componentQty = (int) round((float) $component->pivot->qty * (int) $item->qty);

                        $pw = ProductWarehouse::where('product_id', $component->id)
                            ->where('warehouse_id', $warehouseId)
                            ->lockForUpdate()
                            ->first();

                        $available = $pw ? (int) $pw->stock : (int) $component->stock;

                        if ($available < $componentQty) {
                            throw ValidationException::withMessages([
                                'stock' => "Stok komponen {$component->title} tidak mencukupi (tersedia: {$available}).",
                            ]);
                        }

                        $pw?->decrement('stock', $componentQty);
                        $component->decrement('stock', $componentQty);

                        $this->stockMutationService->recordMutation(
                            product: $component,
                            warehouseId: $warehouseId,
                            referenceType: 'dine_order',
                            referenceId: $order->id,
                            mutationType: 'out',
                            qty: $componentQty,
                            stockBefore: $available,
                            stockAfter: $available - $componentQty,
                            notes: "Pesanan dine-in #{$order->id} (komponen komposit {$product->title})",
                            userId: $userId,
                        );
                    }
                } else {
                    $baseQty = $item->baseQuantity();

                    $pw = ProductWarehouse::where('product_id', $product->id)
                        ->where('warehouse_id', $warehouseId)
                        ->lockForUpdate()
                        ->first();

                    $available = $pw ? (int) $pw->stock : (int) $product->stock;

                    if ($available < $baseQty) {
                        throw ValidationException::withMessages([
                            'stock' => "Stok {$product->title} tidak mencukupi (tersedia: {$available}).",
                        ]);
                    }

                    $pw?->decrement('stock', $baseQty);
                    $product->decrement('stock', $baseQty);

                    $this->stockMutationService->recordMutation(
                        product: $product,
                        warehouseId: $warehouseId,
                        referenceType: 'dine_order',
                        referenceId: $order->id,
                        mutationType: 'out',
                        qty: $baseQty,
                        stockBefore: $available,
                        stockAfter: $available - $baseQty,
                        notes: "Pesanan dine-in #{$order->id}",
                        userId: $userId,
                    );

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

            $order->update([
                'status' => DineOrder::STATUS_ACCEPTED,
                'transaction_id' => $transaction->id,
                'cashier_id' => $userId,
            ]);
        });
    }

    /**
     * Settle the open bill of an accepted order. Stock was taken at accept, so only the payment is
     * recorded: on the paying cashier's shift, which is the drawer the money goes into.
     *
     * @param  array<int, array<string, mixed>>  $tenderInput
     */
    public function pay(DineOrder $order, User $cashier, array $tenderInput): Transaction
    {
        return DB::transaction(function () use ($order, $cashier, $tenderInput) {
            $shift = $this->cashierShiftService->requireActiveShiftForUser($cashier->id, lockForUpdate: true);
            $shift->load('warehouse.outlet');

            $order = DineOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $transaction = $order->transaction_id
                ? Transaction::with('warehouse')->whereKey($order->transaction_id)->lockForUpdate()->first()
                : null;

            if ($order->status !== DineOrder::STATUS_ACCEPTED || ! $transaction || $transaction->payment_status !== 'unpaid') {
                throw ValidationException::withMessages([
                    'order' => 'Pesanan ini tidak memiliki tagihan yang belum lunas.',
                ]);
            }

            if ($transaction->warehouse?->outlet_id !== $shift->warehouse?->outlet_id) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift kasir harus berada di outlet yang sama dengan pesanan.',
                ]);
            }

            $tenders = $this->tenderService->normalize($tenderInput, (int) $transaction->grand_total, allowEmpty: false, outlet: $shift->warehouse?->outlet);

            // shortcut: gateway tenders need a charge and a webhook that completes the order, so the bill takes cash and bank transfer only; add gateways when a cafe asks
            if (collect($tenders)->pluck('method')->intersect(TransactionTenderService::GATEWAY_METHODS)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'tenders' => 'Tagihan dine-in hanya dapat dibayar tunai atau transfer bank.',
                ]);
            }

            $transaction->tenders()->createMany($tenders);
            $transaction->update([
                'cashier_id' => $cashier->id,
                'cashier_shift_id' => $shift->id,
                'cash' => (int) collect($tenders)->sum('cash_received'),
                'change' => (int) collect($tenders)->sum('change'),
                'payment_method' => count($tenders) > 1 ? 'split' : $tenders[0]['method'],
                'payment_status' => $this->tenderService->aggregateStatus($tenders),
                'bank_account_id' => collect($tenders)->firstWhere('bank_account_id', '!==', null)['bank_account_id'] ?? null,
            ]);

            return $transaction;
        });
    }

    /** Staff mark the order as served from the queue. Idempotent. */
    public function serve(DineOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = DineOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->status !== DineOrder::STATUS_ACCEPTED) {
                throw ValidationException::withMessages([
                    'order' => 'Hanya pesanan yang sudah diterima yang dapat ditandai disajikan.',
                ]);
            }

            if (! $order->served_at) {
                $order->update(['served_at' => now()]);
            }

            $order->completeIfSettled();
        });
    }

    public function reject(DineOrder $order, ?string $reason = null): void
    {
        $order->update([
            'status' => DineOrder::STATUS_REJECTED,
            'notes' => $order->notes
                ? "{$order->notes}\n[Penolakan: {$reason}]"
                : "[Penolakan: {$reason}]",
        ]);
    }
}
