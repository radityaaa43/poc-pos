<?php

namespace App\Http\Controllers\Apps\Transactions;

use App\Http\Controllers\Controller;
use App\Http\Requests\DiscardOfflineSaleRequest;
use App\Models\Transaction;
use App\Services\AuditLogService;
use App\Services\CashierShiftService;
use Illuminate\Http\JsonResponse;

/**
 * Records a queued offline sale the POS is about to remove from the device (#400). The audit entry is the only
 * trace left of it, so the device deletes the row only after this answers 201. Nothing else changes here; a
 * repeated call writes a second entry.
 */
class OfflineSaleDiscardController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly CashierShiftService $cashierShiftService,
    ) {}

    public function __invoke(DiscardOfflineSaleRequest $request): JsonResponse
    {
        $row = $request->validated();
        // The same lookup as the sync: for a conflict the server already holds this sale.
        $sale = Transaction::where('client_uuid', $row['client_uuid'])->first();

        $audit = $this->auditLogService->log(
            event: 'offline_sale.discarded',
            module: 'transactions',
            auditable: $sale,
            description: 'Transaksi offline dihapus dari antrean perangkat.',
            meta: [
                ...$row,
                'server_invoice' => $sale?->invoice,
                'cashier_shift_id' => $this->cashierShiftService->getActiveShiftForUser($request->user()->id)?->id,
            ],
        );

        return response()->json(['data' => ['audit_id' => $audit->id]], 201);
    }
}
