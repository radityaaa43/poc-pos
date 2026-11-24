<?php

namespace App\Services;

use App\Models\GoodsReceiving;
use App\Models\GoodsReceivingItem;
use App\Models\Payable;
use App\Models\ProductWarehouse;
use App\Models\SupplierReturn;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SupplierReturnService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly StockMutationService $stockMutationService,
        private readonly OutletAccessService $outletAccessService,
        private readonly PurchaseOrderService $purchaseOrderService
    ) {}

    public function generateDocumentNumber(): string
    {
        $prefix = 'SR-'.now()->format('Ymd').'-';
        $last = SupplierReturn::where('document_number', 'like', $prefix.'%')
            ->orderByDesc('document_number')
            ->value('document_number');

        $next = $last ? (int) Str::afterLast($last, '-') + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function createReturn(array $data, array $items, int $userId): SupplierReturn
    {
        return DB::transaction(function () use ($data, $items, $userId) {
            // Lock the receiving lines before reading what is already returned, so two concurrent
            // returns cannot both pass the over-return check.
            $lines = GoodsReceivingItem::whereIn('id', array_filter(array_column($items, 'goods_receiving_item_id')))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines->load('purchaseOrderItem')->loadSum('activeReturnItems as returned_qty', 'qty_returned');

            // One return belongs to one receiving: the chosen one, else the one its lines come from.
            $receivingId = $data['goods_receiving_id'] ?? $lines->first()?->goods_receiving_id;
            $receiving = $receivingId ? GoodsReceiving::findOrFail($receivingId) : null;
            foreach ($items as $i => $item) {
                if ($receiving && isset($item['goods_receiving_item_id']) && (int) $lines[$item['goods_receiving_item_id']]->goods_receiving_id !== $receiving->id) {
                    throw ValidationException::withMessages(["items.{$i}.goods_receiving_item_id" => 'Item bukan bagian dari penerimaan barang retur ini.']);
                }
            }

            $data['warehouse_id'] ??= $receiving?->warehouse_id;
            if ($receiving?->warehouse_id && (int) $receiving->warehouse_id !== (int) $data['warehouse_id']) {
                throw ValidationException::withMessages(['warehouse_id' => 'Gudang retur harus sama dengan gudang penerimaan barang.']);
            }
            if ($data['warehouse_id']) {
                $this->ensureWarehouseAccess($userId, $data['warehouse_id']);
            }
            // The receiving's payable wins over a client-sent one: the return must reduce what that PO owes.
            $data['payable_id'] = $receiving
                ? Payable::where('purchase_order_id', $receiving->purchase_order_id)->value('id')
                : ($data['payable_id'] ?? null);

            $rows = [];
            $requested = [];
            foreach ($items as $i => $item) {
                $line = $lines[$item['goods_receiving_item_id'] ?? null] ?? null;
                if ($line && (int) $line->product_id !== (int) $item['product_id']) {
                    throw ValidationException::withMessages(["items.{$i}.product_id" => 'Produk tidak sesuai dengan item penerimaan barang.']);
                }

                // A receiving line keeps its own unit and price; a manual line picks a unit like a PO line.
                $unit = $line
                    ? ['unit_id' => $line->unit_id, 'conversion_factor' => $line->conversion_factor]
                    : $this->purchaseOrderService->purchaseUnit($item['product_id'], $item['unit_id'] ?? null, $i);
                $stockQty = round($item['qty_returned'] * $unit['conversion_factor'], 4);
                if ($stockQty != floor($stockQty)) {
                    throw ValidationException::withMessages(["items.{$i}.qty_returned" => 'Qty retur tidak menghasilkan stok bulat.']);
                }

                if ($line) {
                    $requested[$line->id] = ($requested[$line->id] ?? 0) + $item['qty_returned'];
                    $left = $line->qty_received - (int) $line->returned_qty;
                    if ($requested[$line->id] > $left) {
                        throw ValidationException::withMessages(["items.{$i}.qty_returned" => 'Qty retur melebihi sisa yang bisa diretur ('.max(0, $left).').']);
                    }
                }

                $rows[] = $unit + [
                    'goods_receiving_item_id' => $line?->id,
                    'product_id' => $item['product_id'],
                    'qty_returned' => $item['qty_returned'],
                    'unit_price' => $line ? $line->purchaseOrderItem->unit_price : ($item['unit_price'] ?? 0),
                    'reason' => $item['reason'] ?? null,
                    'notes' => $item['notes'] ?? null,
                ];
            }

            $return = SupplierReturn::create([
                'supplier_id' => $data['supplier_id'] ?? null,
                'warehouse_id' => $data['warehouse_id'],
                'goods_receiving_id' => $receiving?->id,
                'payable_id' => $data['payable_id'],
                'document_number' => $this->generateDocumentNumber(),
                'status' => 'draft',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
            $return->items()->createMany($rows);

            $this->auditLogService->log(
                event: 'supplier_return.created',
                module: 'purchase',
                auditable: $return,
                description: 'Supplier return '.$return->document_number.' dibuat.',
                after: [
                    'document_number' => $return->document_number,
                    'supplier_id' => $return->supplier_id,
                    'status' => 'draft',
                    'total_items' => count($items),
                ],
                meta: ['supplier_return_id' => $return->id],
            );

            return $return;
        });
    }

    public function complete(SupplierReturn $return, int $userId): void
    {
        DB::transaction(function () use ($return, $userId) {
            if ($return->warehouse_id) {
                $this->ensureWarehouseAccess($userId, $return->warehouse_id);
            }

            $return->load('items');

            foreach ($return->items as $item) {
                $product = $item->product()->lockForUpdate()->first();

                if (! $product) {
                    continue;
                }

                // Items are in the purchase unit; stock moves in stock units. Average cost is left alone on a return.
                $qtyReturned = (int) round($item->qty_returned * $item->conversion_factor, 4);

                if ((int) $product->stock < $qtyReturned) {
                    throw ValidationException::withMessages([
                        'items' => "Stok {$product->title} tidak mencukupi untuk diretur ke supplier (tersedia: {$product->stock}).",
                    ]);
                }

                $stockBefore = (int) $product->stock;
                $product->decrement('stock', $qtyReturned);

                // Decrement pivot warehouse stock
                if ($return->warehouse_id) {
                    $pivot = ProductWarehouse::where([
                        'product_id' => $product->id,
                        'warehouse_id' => $return->warehouse_id,
                    ])->lockForUpdate()->first();

                    if ($pivot && (int) $pivot->stock < $qtyReturned) {
                        throw ValidationException::withMessages([
                            'items' => "Stok gudang {$product->title} tidak mencukupi untuk diretur ke supplier (tersedia: {$pivot->stock}).",
                        ]);
                    }

                    $pivot?->decrement('stock', $qtyReturned);
                }

                $this->stockMutationService->recordSupplierReturnOut(
                    product: $product,
                    supplierReturn: $return,
                    qty: $qtyReturned,
                    stockBefore: $stockBefore,
                    stockAfter: (int) $product->stock,
                    notes: $item->reason ?? 'Retur barang ke supplier',
                    userId: $return->created_by,
                );
            }

            if ($return->payable_id && $return->payable) {
                $returnAmount = $return->items->sum(fn ($i) => $i->qty_returned * $i->unit_price);
                $payable = $return->payable;
                $payable->total = max(0, $payable->total - $returnAmount);
                if ($payable->total <= 0) {
                    $payable->total = 0;
                    $payable->status = 'paid';
                } elseif ($payable->paid > 0) {
                    $payable->status = $payable->paid >= $payable->total ? 'paid' : 'partial';
                }
                $payable->save();
            }

            $return->update([
                'status' => 'completed',
                'returned_at' => now(),
            ]);

            $this->auditLogService->log(
                event: 'supplier_return.completed',
                module: 'purchase',
                auditable: $return,
                description: 'Supplier return '.$return->document_number.' diselesaikan. Stok dikurangi dan hutang dikoreksi.',
                after: ['status' => 'completed'],
                meta: ['supplier_return_id' => $return->id],
            );
        });
    }

    private function ensureWarehouseAccess(int $userId, int $warehouseId): void
    {
        $user = User::findOrFail($userId);
        $warehouse = Warehouse::findOrFail($warehouseId);

        abort_unless($this->outletAccessService->canUseWarehouse($user, $warehouse), 403);
    }

    public function cancel(SupplierReturn $return): void
    {
        DB::transaction(function () use ($return) {
            $return->update(['status' => 'cancelled']);

            $this->auditLogService->log(
                event: 'supplier_return.cancelled',
                module: 'purchase',
                auditable: $return,
                description: 'Supplier return '.$return->document_number.' dibatalkan.',
                after: ['status' => 'cancelled'],
                meta: ['supplier_return_id' => $return->id],
            );
        });
    }
}
