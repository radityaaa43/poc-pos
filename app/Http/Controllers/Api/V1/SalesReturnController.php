<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Apps\SalesReturnController as AppsSalesReturnController;
use App\Http\Requests\StoreSalesReturnRequest;
use App\Http\Requests\UpdateSalesReturnRequest;
use App\Http\Traits\ApiResponder;
use App\Models\Customer;
use App\Models\CustomerCredit;
use App\Models\Profit;
use App\Models\SalesReturn;
use App\Models\Transaction;
use App\Services\KitchenItemService;
use App\Services\LoyaltyService;
use App\Services\SalesReturnRestockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnController extends AppsSalesReturnController
{
    use ApiResponder;

    /**
     * GET /api/v1/sales-returns
     * Paginated sales return list with filters.
     */
    public function listReturns(Request $request): JsonResponse
    {
        $this->ensureSalesReturnTablesExist();

        $filters = [
            'code' => $request->input('code'),
            'invoice' => $request->input('invoice'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
            'return_type' => $request->input('return_type'),
        ];

        $salesReturns = SalesReturn::query()
            ->with(['transaction:id,invoice,payment_method,payment_status', 'customer:id,name', 'cashier:id,name'])
            ->when(! $request->user()->isSuperAdmin(), function ($query) use ($request) {
                $query->whereHas('transaction', function ($builder) use ($request) {
                    $builder->where('cashier_id', $request->user()->id);
                });
            })
            ->when($filters['code'], fn ($query, $code) => $query->where('code', 'like', '%'.$code.'%'))
            ->when($filters['invoice'], function ($query, $invoice) {
                $query->whereHas('transaction', fn ($builder) => $builder->where('invoice', 'like', '%'.$invoice.'%'));
            })
            ->when($filters['date_from'], fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'], fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($filters['return_type'], fn ($query, $returnType) => $query->where('return_type', $returnType))
            ->withCount('items')
            ->latest()
            ->paginate($this->perPage())->withQueryString();

        return $this->paginated($salesReturns);
    }

    /**
     * GET /api/v1/sales-returns/{salesReturn}
     * Return detail with transaction and items.
     */
    public function showReturn(Request $request, SalesReturn $salesReturn): JsonResponse
    {
        $this->ensureSalesReturnTablesExist();

        $salesReturn = $this->resolveAccessibleSalesReturn($request, $salesReturn->id);

        return $this->ok($this->transformSalesReturn($salesReturn));
    }

    /**
     * GET /api/v1/sales-returns/create/{transaction}
     * Return-able transaction data for the create form.
     */
    public function createFor(Request $request, Transaction $transaction): JsonResponse
    {
        $this->ensureSalesReturnTablesExist();

        $transaction = $this->resolveAccessibleTransaction($request, $transaction->id);

        if (! $this->transactionHasReturnableItems($transaction)) {
            return $this->error('Seluruh item transaksi ini sudah habis diretur.', 422);
        }

        return $this->ok($this->transformTransactionForEditor($transaction));
    }

    /**
     * POST /api/v1/sales-returns
     * Create a draft sales return for the sale in `transaction_id`.
     */
    public function createReturn(StoreSalesReturnRequest $request): JsonResponse
    {
        $this->ensureSalesReturnTablesExist();

        $transaction = $this->resolveAccessibleTransaction($request, (int) $request->validated('transaction_id'));
        $payload = $this->prepareDraftPayload($transaction, $request->validated());

        $salesReturn = DB::transaction(function () use ($request, $transaction, $payload) {
            $salesReturn = SalesReturn::create([
                'code' => $this->generateCode(),
                'transaction_id' => $transaction->id,
                'customer_id' => $transaction->customer_id,
                'cashier_id' => $request->user()?->id,
                'status' => 'draft',
                'return_type' => $payload['return_type'],
                'refund_amount' => $payload['refund_amount'],
                'credited_amount' => $payload['credited_amount'],
                'service_refunded' => $payload['service_refunded'],
                'tax_refunded' => $payload['tax_refunded'],
                'discount_deducted' => $payload['discount_deducted'],
                'points_restored' => $payload['points_restored'],
                'points_reversed' => $payload['points_reversed'],
                'total_return_amount' => $payload['total_return_amount'],
                'notes' => $payload['notes'],
            ]);

            $salesReturn->items()->createMany($payload['items']);

            return $salesReturn;
        });

        $salesReturn->load('items.product');
        $this->auditLogService->log(
            event: 'sales_return.created',
            module: 'sales_returns',
            auditable: $salesReturn,
            description: 'Draft retur penjualan dibuat.',
            after: $this->salesReturnAuditPayload($salesReturn),
        );

        return $this->created($this->transformSalesReturn($salesReturn), 'Draft retur penjualan berhasil dibuat.');
    }

    /**
     * PUT /api/v1/sales-returns/{salesReturn}
     * Update a draft sales return.
     */
    public function updateReturn(UpdateSalesReturnRequest $request, SalesReturn $salesReturn): JsonResponse
    {
        $this->ensureSalesReturnTablesExist();

        $salesReturn = $this->resolveAccessibleSalesReturn($request, $salesReturn->id);
        $this->ensureDraft($salesReturn);
        $before = $this->salesReturnAuditPayload($salesReturn);

        $payload = $this->prepareDraftPayload($salesReturn->transaction, $request->validated(), $salesReturn->id);

        DB::transaction(function () use ($salesReturn, $payload) {
            // A completion may have committed since the draft was read: lock it and check again before rewriting it.
            $this->ensureDraft(SalesReturn::whereKey($salesReturn->id)->lockForUpdate()->firstOrFail());

            $salesReturn->update([
                'return_type' => $payload['return_type'],
                'refund_amount' => $payload['refund_amount'],
                'credited_amount' => $payload['credited_amount'],
                'service_refunded' => $payload['service_refunded'],
                'tax_refunded' => $payload['tax_refunded'],
                'discount_deducted' => $payload['discount_deducted'],
                'points_restored' => $payload['points_restored'],
                'points_reversed' => $payload['points_reversed'],
                'total_return_amount' => $payload['total_return_amount'],
                'notes' => $payload['notes'],
            ]);

            $salesReturn->items()->delete();
            $salesReturn->items()->createMany($payload['items']);
        });

        $salesReturn->refresh();
        $salesReturn->load('items.product');
        $this->auditLogService->log(
            event: 'sales_return.updated',
            module: 'sales_returns',
            auditable: $salesReturn,
            description: 'Draft retur penjualan diperbarui.',
            before: $before,
            after: $this->salesReturnAuditPayload($salesReturn),
        );

        return $this->ok($this->transformSalesReturn($salesReturn), 'Draft retur penjualan berhasil diperbarui.');
    }

    /**
     * POST /api/v1/sales-returns/{salesReturn}/complete
     * Complete a draft sales return, applying stock/finance effects.
     */
    public function completeReturn(Request $request, SalesReturn $salesReturn): JsonResponse
    {
        $this->ensureSalesReturnTablesExist();

        $salesReturn = $this->resolveAccessibleSalesReturn($request, $salesReturn->id);
        $before = [];
        $pointsShortfall = 0;

        DB::transaction(function () use ($request, $salesReturn, &$before, &$pointsShortfall) {
            $activeShift = $this->cashierShiftService->requireActiveShiftForUser(
                $request->user()->id,
                lockForUpdate: true
            );

            // Re-read under a row lock, and work on that row from here on: two completions of one draft could both
            // pass an unlocked check and restock, refund and cancel kitchen items twice (#355), and the copy loaded
            // before the lock may hold lines or totals an edit has since changed. The second one is refused here.
            $salesReturn = SalesReturn::whereKey($salesReturn->id)->lockForUpdate()->firstOrFail();
            $this->ensureDraft($salesReturn);

            $salesReturn->load([
                'transaction.receivable',
                'items.product',
                'items.transactionDetail',
            ]);

            // The state that is actually completed, for the audit: the locked row, before anything changes.
            $before = $this->salesReturnAuditPayload($salesReturn);

            // Lock the customer before any kitchen, sale or stock row (team order: shift → return → customer → ...).
            // The id comes from the locked return row, never from the request; a walk-in has no customer to lock.
            $customer = $salesReturn->customer_id
                ? Customer::whereKey($salesReturn->customer_id)->lockForUpdate()->first()
                : null;

            // Lock order, as in a shift close and a webhook: kitchen items, the dine order, then the sale; all before any
            // stock row (#241, #242). A refusal below rolls the cancellation back.
            app(KitchenItemService::class)->cancelForReturn($salesReturn);
            $sale = $this->lockSaleForReturn($salesReturn);

            // A cancelled sale already returned its stock (expiry or shift close): completing would return it twice. The
            // sale lock orders this against a close that is cancelling the sale right now.
            if ($sale->wasRestocked(locking: true)) {
                throw ValidationException::withMessages([
                    'sales_return' => 'Penjualan ini sudah dibatalkan dan stoknya sudah dikembalikan.',
                ]);
            }

            if ($salesReturn->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'sales_return' => 'Draft retur belum memiliki item.',
                ]);
            }

            $returnedQtyMap = $this->getCompletedReturnedQtyMap(
                $salesReturn->transaction_id,
                excludeSalesReturnId: $salesReturn->id,
                locking: true,
            );

            // Summed per sale line: a draft saved with a line twice may not return more than was sold.
            $requested = collect($salesReturn->getRelationValue('items'))->groupBy('transaction_detail_id')->map->sum('qty_return');

            foreach ($salesReturn->items as $item) {
                $detail = $item->transactionDetail;

                if (! $detail || $item->qty_return < 1) {
                    throw ValidationException::withMessages([
                        'sales_return' => 'Seluruh item retur harus memiliki kuantitas minimal 1.',
                    ]);
                }

                $returnedBefore = (int) ($returnedQtyMap[$detail->id] ?? 0);
                $remainingQty = (int) $detail->qty - $returnedBefore;

                if ($requested[$detail->id] > $remainingQty) {
                    throw ValidationException::withMessages([
                        'sales_return' => 'Ada item retur yang melebihi sisa qty yang bisa diretur.',
                    ]);
                }
            }

            // The draft's stored figures are display only: recompute what this return gives back from the locked sale
            // and the returns already completed, before any stock row (TC-5).
            $itemValue = (int) $salesReturn->items->sum('subtotal');
            $share = $this->returnBillShareService->forReturn($sale, $itemValue, $salesReturn->id, lock: true);
            // The items refund net of their discount share; service and tax follow. A full return equals what was paid.
            $totalReturnAmount = $itemValue - $share['discount_deducted'] + $share['service_refunded'] + $share['tax_refunded'];

            // Move the points the sale moved: redeemed back, earned taken back (clamped at 0). The shortfall a
            // customer no longer holds goes into the audit entry below (BR-7).
            $loyalty = $customer
                ? app(LoyaltyService::class)->applyReturn(
                    $customer,
                    $salesReturn,
                    $sale,
                    $share['points_restored'],
                    $share['points_reversed'],
                    $totalReturnAmount
                )
                : ['restored' => 0, 'reversed' => 0];
            $pointsShortfall = max(0, (int) $share['points_reversed'] - $loyalty['reversed']);

            foreach ($salesReturn->items as $item) {
                $detail = $item->transactionDetail;

                $margin = $detail->returnMargin((int) $item->qty_return);

                Profit::create([
                    'transaction_id' => $salesReturn->transaction_id,
                    'total' => -$margin,
                ]);
            }

            app(SalesReturnRestockService::class)->restock($salesReturn, $salesReturn->transaction->warehouse_id, $request->user()?->id);

            $salesReturn->loadMissing('transaction.receivable');
            $settlement = $this->calculateSettlement(
                $salesReturn->transaction,
                $totalReturnAmount,
                $salesReturn->return_type
            );

            $salesReturn->update([
                'cashier_shift_id' => $activeShift->id,
                'warehouse_id' => $salesReturn->transaction->warehouse_id,
                'return_type' => $settlement['return_type'],
                'refund_amount' => $settlement['refund_amount'],
                'credited_amount' => $settlement['credited_amount'],
                'service_refunded' => $share['service_refunded'],
                'tax_refunded' => $share['tax_refunded'],
                'discount_deducted' => $share['discount_deducted'],
                'points_restored' => $loyalty['restored'],
                'points_reversed' => $loyalty['reversed'],
                'total_return_amount' => $totalReturnAmount,
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            if ($salesReturn->transaction->payment_method === 'pay_later' && $salesReturn->transaction->receivable) {
                $receivable = $salesReturn->transaction->receivable()->lockForUpdate()->first();

                if ($receivable) {
                    $receivable->update([
                        'total' => $settlement['receivable_total_after'],
                        'status' => $this->determineReceivableStatus(
                            total: $settlement['receivable_total_after'],
                            paid: (int) $receivable->paid,
                            dueDate: $receivable->due_date,
                        ),
                    ]);
                }
            }

            if (
                $salesReturn->return_type === 'store_credit'
                && $salesReturn->customer_id
                && $salesReturn->credited_amount > 0
            ) {
                CustomerCredit::create([
                    'customer_id' => $salesReturn->customer_id,
                    'sales_return_id' => $salesReturn->id,
                    'amount' => $salesReturn->credited_amount,
                    'balance' => $salesReturn->credited_amount,
                    'notes' => 'Saldo toko dari retur penjualan '.$salesReturn->code,
                ]);
            }
        }, 3); // retried on a deadlock: the restock locks stock by product id, a checkout in cart order

        $salesReturn->refresh();
        $salesReturn->load('items.product');
        $this->auditLogService->log(
            event: 'sales_return.completed',
            module: 'sales_returns',
            auditable: $salesReturn,
            description: 'Retur penjualan diselesaikan.',
            before: $before,
            after: $this->salesReturnAuditPayload($salesReturn, $pointsShortfall),
        );

        return $this->ok($this->transformSalesReturn($salesReturn), 'Retur penjualan berhasil diselesaikan.');
    }
}
