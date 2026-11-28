<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductWarehouse;
use App\Models\Setting;
use App\Models\StockWaste;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Waste of raw materials and recipe menus (REQ-011). A line is what the user typed; a move is what
 * leaves the shelf: ['line' => line index, 'product' => stock product, 'qty' => stock units].
 */
class StockWasteService
{
    /** Rupiah per record, per outlet; above it the stock waits for an approval. */
    public const LIMIT_KEY = 'waste_approval_limit';

    public const DEFAULT_LIMIT = 100000;

    public function __construct(
        private readonly InventoryCostService $inventoryCostService,
        private readonly StockMutationService $stockMutationService,
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * Records a validated waste form. The same client_key returns the first record and writes nothing.
     *
     * @param  array{warehouse_id: int|string, reason: string, note?: ?string, client_key: string, lines: list<array{product_id: int|string, qty: int|string}>}  $data
     */
    public function record(array $data, User $user): StockWaste
    {
        $first = fn (): ?StockWaste => StockWaste::where('client_key', $data['client_key'])->first();

        if ($existing = $first()) {
            return $existing;
        }

        $warehouse = Warehouse::with('outlet')->findOrFail($data['warehouse_id']);
        // Read before the transaction: on MySQL a plain read inside it would fix the snapshot, and the
        // cost read under the locks would be stale.
        $moves = $this->explode($data['lines']);
        $estimate = array_sum(array_column($this->priced($moves, $warehouse->id), 'value'));
        $pending = $estimate > $this->approvalLimit($warehouse->outlet);

        try {
            return DB::transaction(function () use ($data, $user, $warehouse, $moves, $estimate, $pending) {
                $waste = StockWaste::create([
                    'warehouse_id' => $warehouse->id,
                    'status' => StockWaste::STATUS_PENDING,
                    'reason' => $data['reason'],
                    'note' => $data['note'] ?? null,
                    'total_value' => $estimate,
                    'client_key' => $data['client_key'],
                    'created_by' => $user->id,
                ]);
                // Locked before the lines exist: their foreign key check share-locks the products, and two
                // records upgrading that share lock would deadlock.
                $locked = $pending ? null : $this->lockStock($waste, $moves);
                $lineIds = array_map(fn (array $line) => $waste->lines()->create([
                    'product_id' => $line['product_id'],
                    'qty' => $line['qty'],
                ])->getKey(), $data['lines']);

                if ($locked) {
                    $this->takeStock($waste, $moves, $lineIds, $locked, $user);
                } else {
                    $this->assertAvailable($moves, ProductWarehouse::where('warehouse_id', $warehouse->id)
                        ->whereIn('product_id', $this->productIds($moves))->pluck('stock', 'product_id'));
                }

                $this->auditLogService->log(
                    event: 'stock_waste.recorded',
                    module: 'stock',
                    auditable: $waste,
                    description: __('messages.waste.audit_'.$waste->status, ['id' => $waste->id]),
                    meta: [
                        'warehouse_id' => $warehouse->id,
                        'reason' => $waste->reason,
                        'lines' => count($lineIds),
                        'total_value' => $waste->total_value,
                        'outcome' => $waste->status,
                    ],
                    actor: $user,
                );

                return $waste;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // Two posts of one form at the same moment: the unique client_key picks the winner.
            return $first() ?? throw $e;
        }
    }

    public function approvalLimit(?Outlet $outlet): int
    {
        return (int) Setting::getForOutlet(self::LIMIT_KEY, $outlet, self::DEFAULT_LIMIT);
    }

    /** A menu becomes its ingredients, rounded to whole stock units exactly as a sale deducts them. */
    private function explode(array $lines): array
    {
        $products = Product::with('components')->findMany(array_column($lines, 'product_id'))->keyBy('id');
        $moves = [];

        foreach ($lines as $index => $line) {
            $product = $products[$line['product_id']];

            if (! $product->is_raw_material && ! $product->is_composite) {
                $this->refuse("lines.$index.product_id", 'item_not_allowed', ['item' => $product->title]);
            }
            if ($product->is_composite && $product->components->isEmpty()) {
                $this->refuse("lines.$index.product_id", 'no_recipe', ['item' => $product->title]);
            }

            $parts = $product->is_composite
                ? $product->components->map(fn ($part) => [$part, (int) round((float) data_get($part, 'pivot.qty') * (int) $line['qty'])])->all()
                : [[$product, (int) $line['qty']]];
            // An ingredient that rounds to 0 takes nothing: no row, no stock check, no value.
            $parts = array_filter($parts, fn (array $part) => $part[1] > 0);

            if (! $parts) {
                $this->refuse("lines.$index.qty", 'nothing_to_take', ['item' => $product->title]);
            }

            foreach ($parts as [$part, $qty]) {
                $moves[] = ['line' => $index, 'product' => $part, 'qty' => $qty];
            }
        }

        return $moves;
    }

    /** The moves with the average cost of now: value = unit cost x qty, whole rupiah. */
    private function priced(array $moves, int $warehouseId): array
    {
        $costs = $this->inventoryCostService->unitCosts(new Collection(array_column($moves, 'product')), $warehouseId);

        return array_map(fn (array $move) => $move + [
            'unit_cost' => $costs[$move['product']->id],
            'value' => (int) round($costs[$move['product']->id] * $move['qty']),
        ], $moves);
    }

    private function productIds(array $moves): array
    {
        $ids = array_unique(array_map(fn (array $move) => $move['product']->id, $moves));
        sort($ids);

        return $ids;
    }

    /** Refuses when the demand, summed per product over all lines, is above the warehouse's stock. */
    private function assertAvailable(array $moves, BaseCollection $stock): void
    {
        $demand = [];

        foreach ($moves as ['product' => $product, 'qty' => $qty]) {
            $demand[$product->id] = ($demand[$product->id] ?? 0) + $qty;
            $left = (int) ($stock[$product->id] ?? 0);

            if ($demand[$product->id] > $left) {
                $this->refuse('stock', 'insufficient', ['item' => $product->title, 'left' => $left]);
            }
        }
    }

    /**
     * Call inside a transaction. Fixed lock order: the waste row, the warehouse rows by product id, then
     * the products by id.
     *
     * @return array{0: BaseCollection<int, ?ProductWarehouse>, 1: Collection<int, Product>}
     */
    private function lockStock(StockWaste $waste, array $moves): array
    {
        StockWaste::whereKey($waste->id)->lockForUpdate()->firstOrFail();
        $ids = $this->productIds($moves);
        $rows = collect($ids)->mapWithKeys(fn (int $id) => [$id => ProductWarehouse::where([
            'product_id' => $id,
            'warehouse_id' => $waste->warehouse_id,
        ])->lockForUpdate()->first()]);
        $products = Product::whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        return [$rows, $products];
    }

    /** Takes the locked stock, prices it at this moment's average and marks the waste recorded. */
    private function takeStock(StockWaste $waste, array $moves, array $lineIds, array $locked, User $user): void
    {
        [$rows, $products] = $locked;
        $this->assertAvailable($moves, $rows->map(fn (?ProductWarehouse $row) => $row?->stock));
        // Receiving into this warehouse waits for the row locks, so this cost holds until commit.
        $priced = $this->priced($moves, $waste->warehouse_id);

        foreach ($priced as $move) {
            $product = $products[$move['product']->id];
            $row = $rows[$product->id];
            $before = (int) $row->stock;

            $row->decrement('stock', $move['qty']);
            $product->decrement('stock', $move['qty']);
            $waste->moves()->create([
                'line_id' => $lineIds[$move['line']],
                'product_id' => $product->id,
                'qty' => $move['qty'],
                'unit_cost' => $move['unit_cost'],
                'value' => $move['value'],
            ]);
            $this->stockMutationService->recordWasteMovement($product, $waste, 'out', $move['qty'], $before, $before - $move['qty'], userId: $user->id);
        }

        $waste->update([
            'status' => StockWaste::STATUS_RECORDED,
            'recorded_at' => now(),
            'total_value' => array_sum(array_column($priced, 'value')),
        ]);
    }

    private function refuse(string $field, string $message, array $replace): never
    {
        throw ValidationException::withMessages([$field => __("messages.waste.$message", $replace)]);
    }
}
