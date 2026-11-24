<?php

namespace App\Services;

use App\Models\GoodsReceiving;
use App\Models\GoodsReceivingItem;
use App\Models\Payable;
use App\Models\ProductBatch;
use App\Models\ProductWarehouse;
use App\Models\PurchaseOrder;
use App\Models\SupplierReturnItem;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoodsReceivingService
{
    public function __construct(
        private readonly StockMutationService $stockMutationService,
        private readonly AuditLogService $auditLogService,
        private readonly OutletAccessService $outletAccessService
    ) {}

    public function generateDocumentNumber(): string
    {
        $prefix = 'GR-'.now()->format('Ymd').'-';
        $last = GoodsReceiving::where('document_number', 'like', $prefix.'%')
            ->orderByDesc('document_number')
            ->value('document_number');

        $next = $last ? (int) Str::afterLast($last, '-') + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function receive(PurchaseOrder $order, array $items, ?string $notes, int $userId): GoodsReceiving
    {
        // ponytail: retry only on document_number unique collisions (concurrent receipts pick the same next number)
        return retry(3, function () use ($order, $items, $notes, $userId) {
            return DB::transaction(function () use ($order, $items, $notes, $userId) {
                // ponytail: lock the order + its items so concurrent receipts cannot double-consume the same PO item
                $order = PurchaseOrder::with('items.unit')->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $user = User::findOrFail($userId);
                $warehouse = $order->warehouse_id ? Warehouse::findOrFail($order->warehouse_id) : null;
                abort_unless($this->outletAccessService->canUseWarehouse($user, $warehouse), 403);

                if (! in_array($order->status, ['ordered', 'partial_received'])) {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => 'Hanya PO berstatus ordered/partial yang dapat diterima.',
                    ]);
                }

                $receiving = GoodsReceiving::create([
                    'purchase_order_id' => $order->id,
                    'supplier_id' => $order->supplier_id,
                    'warehouse_id' => $order->warehouse_id,
                    'document_number' => $this->generateDocumentNumber(),
                    'notes' => $notes,
                    'received_by' => $userId,
                    'received_at' => now(),
                ]);

                foreach ($items as $item) {
                    $poItem = $order->items->firstWhere('id', $item['purchase_order_item_id']);
                    if (! $poItem) {
                        throw ValidationException::withMessages([
                            'items' => 'Item tidak ditemukan di PO.',
                        ]);
                    }
                    $qtyReceived = (int) $item['qty_received'];

                    $outstanding = $poItem->qty_ordered - $poItem->qty_received;
                    if ($qtyReceived > $outstanding) {
                        throw ValidationException::withMessages([
                            'items' => "Qty diterima melebihi sisa item {$poItem->product_id}.",
                        ]);
                    }

                    // Stock moves in stock units: qty in the PO item unit x its factor.
                    // The 4-decimal round absorbs float noise from the decimal(15,4) factor.
                    $stockQty = round($qtyReceived * $poItem->conversion_factor, 4);
                    if ($stockQty != floor($stockQty)) {
                        throw ValidationException::withMessages([
                            'items' => "Qty diterima {$qtyReceived} {$poItem->unit?->symbol} tidak menghasilkan stok bulat (item {$poItem->product_id}).",
                        ]);
                    }
                    $stockQty = (int) $stockQty;

                    GoodsReceivingItem::create([
                        'goods_receiving_id' => $receiving->id,
                        'purchase_order_item_id' => $poItem->id,
                        'product_id' => $poItem->product_id,
                        'unit_id' => $poItem->unit_id,
                        'conversion_factor' => $poItem->conversion_factor,
                        'qty_received' => $qtyReceived,
                        'notes' => $item['notes'] ?? null,
                    ]);

                    $poItem->increment('qty_received', $qtyReceived);

                    $product = $poItem->product;
                    $stockBefore = (int) $product->stock;
                    // Increment legacy stock
                    $product->increment('stock', $stockQty);
                    // Increment warehouse pivot stock
                    if ($order->warehouse_id) {
                        ProductWarehouse::firstOrCreate(
                            ['product_id' => $product->id, 'warehouse_id' => $order->warehouse_id],
                            ['stock' => 0]
                        )->increment('stock', $stockQty);
                    }

                    // Create batch record
                    if (! empty($item['batch_number']) && $order->warehouse_id) {
                        ProductBatch::create([
                            'product_id' => $product->id,
                            'warehouse_id' => $order->warehouse_id,
                            'batch_number' => $item['batch_number'],
                            'expired_at' => $item['expired_at'] ?? null,
                            'received_at' => now(),
                            'stock' => $stockQty,
                        ]);
                    }

                    $this->stockMutationService->recordPurchaseInbound(
                        product: $product,
                        goodsReceiving: $receiving,
                        qty: $stockQty,
                        stockBefore: $stockBefore,
                        stockAfter: (int) $product->stock,
                        notes: $poItem->unit
                            ? "Penerimaan {$qtyReceived} {$poItem->unit->symbol} dari PO ".$order->document_number
                            : 'Penerimaan dari PO '.$order->document_number,
                        userId: $userId,
                    );
                }

                $this->updateOrderStatus($order);

                if ($receiving->supplier_id) {
                    $this->createOrUpdatePayable($order, $receiving, $userId);
                }

                $this->auditLogService->log(
                    event: 'goods_receiving.created',
                    module: 'purchase',
                    auditable: $receiving,
                    description: 'Barang diterima dari PO '.$order->document_number,
                    after: [
                        'document_number' => $receiving->document_number,
                        'purchase_order_id' => $order->id,
                        'total_items' => count($items),
                    ],
                    meta: ['goods_receiving_id' => $receiving->id],
                );

                return $receiving;
            });
        }, 0, function ($e) {
            return $e instanceof QueryException && str_contains($e->getMessage(), 'document_number');
        });
    }

    private function updateOrderStatus(PurchaseOrder $order): void
    {
        $allFullyReceived = $order->items()->whereColumn('qty_received', '<', 'qty_ordered')->doesntExist();

        $status = $allFullyReceived ? 'completed' : 'partial_received';
        $updates = ['status' => $status];

        if ($status === 'completed') {
            $updates['completed_at'] = now();
        }

        $order->update($updates);
    }

    private function createOrUpdatePayable(PurchaseOrder $order, GoodsReceiving $receiving, int $userId): void
    {
        // Locked so a concurrent payment cannot be lost under the recomputed row.
        $payable = Payable::where('purchase_order_id', $order->id)->lockForUpdate()->first() ?? new Payable([
            'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'document_number' => $receiving->document_number,
            'due_date' => now()->addDays(30),
            'note' => 'Otomatis dari penerimaan PO '.$order->document_number,
        ]);

        // Everything received on the PO so far (qty and price are both in the PO line unit), minus
        // completed supplier returns on this payable. Mirrors SupplierReturnService::complete().
        $received = GoodsReceivingItem::whereHas('goodsReceiving', fn ($q) => $q->where('purchase_order_id', $order->id))
            ->sum(DB::raw('qty_received * (SELECT unit_price FROM purchase_order_items WHERE id = goods_receiving_items.purchase_order_item_id)'));
        $returned = $payable->exists
            ? SupplierReturnItem::whereHas('supplierReturn', fn ($q) => $q->where('payable_id', $payable->id)->where('status', 'completed'))
                ->sum(DB::raw('qty_returned * unit_price'))
            : 0;

        $payable->total = max(0, $received - $returned);
        $payable->paid ??= 0;
        $payable->status = $payable->paid >= $payable->total ? 'paid' : ($payable->paid > 0 ? 'partial' : 'unpaid');
        $payable->save();

        if ($payable->wasRecentlyCreated) {
            $this->auditLogService->log(
                event: 'payable.created_from_receiving',
                module: 'payable',
                auditable: $payable,
                description: 'Hutang otomatis dari penerimaan PO '.$order->document_number,
                after: [
                    'payable_id' => $payable->id,
                    'supplier_id' => $payable->supplier_id,
                    'total' => $payable->total,
                    'document_number' => $payable->document_number,
                    'purchase_order_id' => $order->id,
                ],
                meta: ['goods_receiving_id' => $receiving->id],
            );
        }
    }
}
