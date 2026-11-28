<?php

namespace App\Services;

use App\Jobs\ExpireSupersededGatewayCharge;
use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\KitchenItem;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductWarehouse;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Modifiers\ModifierSelection;
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
        private TransactionSettlementService $settlementService,
        private KitchenItemService $kitchenItemService,
        private ComponentStockService $componentStock,
        private AddOnOrderService $addOnOrderService,
    ) {}

    public function accept(DineOrder $order): void
    {
        $cashierId = $order->cashier_id ?? auth()->id();
        DB::transaction(function () use ($order, $cashierId) {
            // The shift lock comes first, as in a close: a bill never lands on a shift that is closing (#242).
            $shift = CashierShift::where('user_id', $cashierId)->open()->lockForUpdate()->first();

            // ponytail: without an open shift there is no warehouse context to take stock from — refuse instead of accepting an order whose stock is never decremented
            if (! $shift) {
                throw ValidationException::withMessages([
                    'shift' => 'Tidak ada shift kasir aktif. Buka shift terlebih dahulu sebelum menerima pesanan.',
                ]);
            }

            $warehouseId = $shift->warehouse_id;

            // ponytail: lock the order row so accept cannot race with a second accept/reject
            $order = DineOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            $this->refuseIfProcessed($order);
            // #251: locked before the first plain read fixes MySQL's snapshot. A refusal rolls the whole accept back.
            $parentId = $this->addOnOrderService->resolveForDine($order->addOnTo()->lockForUpdate()->first());

            $order->load(['items.product.components', 'table.area.outlet']);

            $tableOutletId = $order->table?->area?->outlet_id;
            $shiftOutletId = $shift->warehouse?->outlet_id;

            if ($tableOutletId && $shiftOutletId && $tableOutletId !== $shiftOutletId) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift kasir harus berada di outlet yang sama dengan meja.',
                ]);
            }

            $userId = $cashierId;
            $outlet = $order->table?->area?->outlet;

            // withTrashed: an option deleted since submit still gives the recipe its snapshot row was priced with.
            $recipes = ModifierOption::withTrashed()->with('components')
                ->findMany($order->items->flatMap(fn ($item) => (array) ($item->getAttribute('modifier_option_ids') ?? []))->all())
                ->keyBy('id');

            // Take every stock row this accept touches in product_id order first, so it never deadlocks with a sale sharing an ingredient.
            $lockIds = collect();
            foreach ($order->items as $item) {
                $lockedProduct = $item->getRelationValue('product');
                if ($lockedProduct instanceof Product) {
                    $lockIds = $lockIds->merge($lockedProduct->is_composite ? $lockedProduct->components->modelKeys() : [$lockedProduct->id]);
                }
            }
            $this->componentStock->lock($lockIds->merge($recipes->flatMap->components->pluck('id')), $warehouseId);

            // ponytail: accepting a dine order is a sale — record a Transaction so revenue, profit, stock ledger and stock mutations all stay in sync with the POS path
            $transaction = Transaction::create([
                'cashier_id' => $userId,
                'cashier_shift_id' => $shift->id,
                'parent_transaction_id' => $parentId,
                'warehouse_id' => $warehouseId,
                'customer_id' => $order->customer_id,
                'invoice' => 'TRX-'.strtoupper(Str::random(10)),
                'cash' => 0,
                'change' => 0,
                'discount' => 0,
                'grand_total' => (int) $order->subtotal,
                'payment_method' => Transaction::METHOD_OPEN_BILL, // chosen by the cashier when the bill is settled
                'payment_status' => 'unpaid',
                'is_pay_later' => $order->payment_option === DineOrder::PAY_LATER, // no receivable: the guest settles at the counter
                'order_type' => 'in_store',
                'note' => $order->notes,
            ]);

            if ($parentId) {
                $this->addOnOrderService->record($transaction);
            }

            foreach ($order->items as $item) {
                $product = $item->product;
                $lineTotal = (int) $item->price * (int) $item->qty;
                // The sale column is 100 wide: an order pending from before the submit cap could still carry more.
                $note = $item->getAttribute('note');

                // A line written before this ticket (or before its menu had groups) carries no options: accept writes none.
                $itemOptionIds = $item->getAttribute('modifier_option_ids');
                if ($itemOptionIds === null) {
                    $options = [];
                } else {
                    // Re-check availability at the latest data (BR-3): an option gone or switched off refuses the accept.
                    // Prices stay as shown to the guest: the stored snapshot, never a fresh catalog price.
                    $resolved = ModifierSelection::resolve($product, (array) $itemOptionIds, $outlet, $warehouseId);
                    $options = (array) ($item->getAttribute('modifier_snapshot') ?? $resolved['options']);
                }
                $modifierTotal = (int) array_sum(array_column($options, 'price_delta'));

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
                    'needs_preparation' => $product->needs_preparation, // the bill is paid later: freeze the flag at accept
                    'note' => $note === null ? null : mb_substr($note, 0, 100),
                    'modifier_total' => $modifierTotal,
                ]);

                if ($options !== []) {
                    $detail->modifiers()->createMany(ModifierSelection::snapshot($options, $recipes));

                    // Frozen HPP includes the option recipes exactly as the POS checkout snapshots them (REQ-008 decision 3).
                    // Read from $detail->qty so the accept's $item->qty accesses (and the Larastan baseline) stay unchanged.
                    $costTotal += $this->inventoryCostService->recipeCost(
                        collect($options)->flatMap(fn (array $option) => $recipes[$option['id']]->components),
                        $warehouseId,
                        (int) $detail->qty,
                    );
                    $detail->update(['cost_total' => $costTotal]);
                }

                $transaction->profits()->create([
                    'transaction_id' => $transaction->id,
                    'total' => $lineTotal - $costTotal,
                ]);

                // Option recipes come out of the same warehouse and transaction as the menu (BR-2); a missing ingredient refuses the accept.
                foreach ($options as $option) {
                    $refusal = __('messages.modifiers.option_sold_out', ['menu' => $product->title, 'option' => $option['name']]);
                    $this->componentStock->deduct($transaction, $recipes[$option['id']]->components, 1, (int) $detail->qty, $warehouseId, "opsi {$option['name']} {$product->title}", $userId, $refusal);
                }

                if ($product->is_composite) {
                    $this->componentStock->deduct($transaction, $product->components, 1, (int) $item->qty, $warehouseId, "komponen komposit {$product->title}", $userId);
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
                'accepted_at' => now(),
                'accepted_by' => $userId,
                'transaction_id' => $transaction->id,
                'cashier_id' => $userId,
            ]);

            // Money first: a kitchen failure is reported, never allowed to roll back the accept. Both steps share one rescue,
            // so a failed intake never leaves "no kitchen items" to be read as "nothing to make".
            if ($transaction->is_pay_later) {
                DB::afterCommit(fn () => rescue(function () use ($transaction) {
                    app(KitchenIntakeService::class)->intakeUnpaid($transaction);
                    $this->kitchenItemService->serveIfNoKitchenItems($transaction); // BR-10: nothing to make is served now, completed once paid
                }));
            }
        }, 3); // retried on a deadlock with a close of another shift; kitchen intake runs only after the commit
    }

    /**
     * Settle the open bill of an accepted order. Stock was taken at accept, so only the payment is
     * recorded: on the paying cashier's shift, which is the drawer the money goes into.
     * A gateway tender (QRIS, Midtrans, Xendit) charges against the same transaction and leaves the
     * bill pending until the webhook marks it paid. The charge is created after the payment commits.
     *
     * @param  array<int, array<string, mixed>>  $tenderInput
     */
    public function pay(DineOrder $order, User $cashier, array $tenderInput): Transaction
    {
        $claim = DB::transaction(function () use ($order, $cashier, $tenderInput) {
            $shift = $this->cashierShiftService->requireActiveShiftForUser($cashier->id, lockForUpdate: true);

            $order = DineOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $transaction = $order->transaction_id
                ? Transaction::with('warehouse')->whereKey($order->transaction_id)->lockForUpdate()->first()
                : null;

            // No plain read before the bill lock: on MySQL it would fix the snapshot, and the tender checks below would
            // miss a claim committed while this request waited (a second charge, mistaken for a takeover).
            $shift->load('warehouse.outlet');
            $outlet = $shift->warehouse?->outlet;

            if (! in_array($order->status, DineOrder::IN_PROGRESS, true) || ! $transaction
                || ! in_array($transaction->payment_status, [...Transaction::OPEN_BILL_PAYABLE, 'pending'], true)) {
                throw ValidationException::withMessages([
                    'order' => 'Pesanan ini tidak memiliki tagihan yang belum lunas.',
                ]);
            }

            // The guest's online charge does not hold the bill: the cashier can always take the money (takeover below).
            $takeover = $transaction->isAwaitingGuestPayment();

            // A pending gateway payment or transfer of the cashier already holds the bill: no second charge or tender, finish that one.
            // An abandoned claim does not: it is replaced.
            if ($transaction->payment_status === 'pending' && ! $takeover && ! $transaction->tenders()->abandonedClaim()->exists()) {
                throw ValidationException::withMessages([
                    'order' => 'Tagihan ini sedang menunggu pembayaran (gateway atau konfirmasi transfer). Selesaikan pembayaran itu dulu, lihat QR di struk.',
                ]);
            }

            if ($transaction->warehouse?->outlet_id !== $outlet?->id) {
                throw ValidationException::withMessages([
                    'shift' => 'Shift kasir harus berada di outlet yang sama dengan pesanan.',
                ]);
            }

            if (! Transaction::whereKey($transaction->id)->notFullyReturned()->exists() || $transaction->remainingTotal() < 1) {
                throw ValidationException::withMessages(['order' => 'Penjualan ini sudah diretur penuh, tidak ada yang perlu dibayar.']);
            }

            $guestAttempt = (int) $transaction->payment_attempt;
            $guestReference = $transaction->payment_reference;

            // claim() moves the attempt counter, so the guest's charge is superseded in the same transaction as the payment.
            // Invalid input rolls everything back. The cashier's gateway call comes after the commit: if it fails, the
            // guest's charge stays superseded and the bill is failed and payable.
            $claim = $this->settlementService->claim($transaction, $cashier, $shift, $outlet, $tenderInput, $transaction->remainingTotal());

            if ($takeover) {
                // After this commit, and whatever becomes of the cashier's own charge.
                ExpireSupersededGatewayCharge::dispatchFor($transaction, $claim['before']);

                app(AuditLogService::class)->log(
                    event: 'dine_order.online_payment_taken_over',
                    module: 'transactions',
                    auditable: $transaction,
                    description: "Kasir mengambil alih pembayaran online tamu invoice {$transaction->invoice}; pembayaran tamu tidak berlaku, refund manual bila sudah terbayar.",
                    meta: ['guest_attempt' => $guestAttempt, 'attempt' => $transaction->payment_attempt, 'guest_reference' => $guestReference, 'cashier_id' => $cashier->id],
                    actor: $cashier,
                );
            }

            return $claim;
        });

        return $this->settlementService->charge($claim);
    }

    /** Kitchen starts making an accepted order, but only once its bill is paid. */
    public function startPreparing(DineOrder $order): void
    {
        // Paid orders with kitchen items (#236): the items carry the trail, the order status follows them (#237).
        if ($this->hasKitchenItems($order)) {
            // One transaction, items locked and re-read as accepted: a double tap or a cook who took one item first
            // leaves the rest to start, not a half-started order plus an error.
            DB::transaction(function () use ($order) {
                $this->kitchenItemService->lockKitchenRows([$order->transaction_id]);
                $items = KitchenItem::where('transaction_id', $order->transaction_id)
                    ->where('status', KitchenItem::STATUS_ACCEPTED)
                    ->lockForUpdate()
                    ->get();

                foreach ($items as $item) {
                    $this->kitchenItemService->start($item, auth()->user());
                }
            });

            return;
        }

        $this->advance($order, DineOrder::STATUS_ACCEPTED, DineOrder::STATUS_PREPARING, 'preparing');
    }

    /** Kitchen marks a preparing order ready. */
    public function markReady(DineOrder $order): void
    {
        if ($this->hasKitchenItems($order)) {
            $this->kitchenItemService->allReady(Transaction::findOrFail($order->transaction_id), auth()->user());

            return;
        }

        $this->advance($order, DineOrder::STATUS_PREPARING, DineOrder::STATUS_READY, 'ready');
    }

    /** Someone verifies a ready order was handed to the customer. The bill is already paid by then, so this completes the order. */
    public function serve(DineOrder $order): void
    {
        // Not ready yet (or already served): advance() keeps its error / no-op, so only a ready order is handed over item by item.
        if ($order->status === DineOrder::STATUS_READY && $this->hasKitchenItems($order)) {
            $this->kitchenItemService->handOverAll(Transaction::findOrFail($order->transaction_id), auth()->user());

            return;
        }

        $this->advance($order, DineOrder::STATUS_READY, DineOrder::STATUS_SERVED, 'served');
    }

    private function hasKitchenItems(DineOrder $order): bool
    {
        return $order->transaction_id && KitchenItem::where('transaction_id', $order->transaction_id)->exists();
    }

    /** One fulfillment step: valid only from the previous status; repeating a step already taken is a no-op. */
    private function advance(DineOrder $order, string $from, string $to, string $step): void
    {
        DB::transaction(function () use ($order, $from, $to, $step) {
            $order = DineOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($order->{"{$step}_at"}) {
                return;
            }

            if ($order->status !== $from) {
                throw ValidationException::withMessages([
                    'order' => "Pesanan harus berstatus {$from} untuk langkah ini (sekarang: {$order->status}).",
                ]);
            }

            // Payment first: the kitchen starts only on a paid bill. A transfer or gateway payment still pending is not paid.
            // The bill row is locked here, in the same transaction, so the check cannot race a webhook or a refund.
            if ($to === DineOrder::STATUS_PREPARING) {
                $status = Transaction::whereKey($order->transaction_id)->lockForUpdate()->value('payment_status');

                if ($status !== 'paid') {
                    throw ValidationException::withMessages([
                        'order' => $status === 'pending'
                            ? 'Pembayaran pesanan ini masih menunggu (konfirmasi transfer atau gateway). Pesanan baru bisa dibuat setelah lunas.'
                            : 'Pesanan ini belum dibayar. Selesaikan pembayaran sebelum mulai dibuat.',
                    ]);
                }
            }

            // when and who, in the same transaction as the status change, so kitchen speed can be analysed later
            $order->update(['status' => $to, "{$step}_at" => now(), "{$step}_by" => auth()->id()]);
            $order->completeIfSettled();
        });
    }

    public function reject(DineOrder $order, ?string $reason = null): void
    {
        DB::transaction(function () use ($order, $reason) {
            $order = DineOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->refuseIfProcessed($order);

            $order->update([
                'status' => DineOrder::STATUS_REJECTED,
                'notes' => $order->notes
                    ? "{$order->notes}\n[Penolakan: {$reason}]"
                    : "[Penolakan: {$reason}]",
            ]);
        });
    }

    /** #438: an order is accepted or rejected once. Pass the locked row, so a racing second action waits and is refused. */
    private function refuseIfProcessed(DineOrder $order): void
    {
        if (! $order->isPending() || $order->transaction_id) {
            throw ValidationException::withMessages(['order' => 'Pesanan ini sudah diproses.']);
        }
    }
}
