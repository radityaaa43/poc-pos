<?php

namespace App\Http\Controllers\Apps;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Warehouse;
use App\Services\OutletAccessService;
use App\Services\TransactionSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settles a guest or non-credit pay-later bill at the counter (REQ-003 #278). Kept out of TransactionController,
 * which is at the 1000-line file limit.
 */
class TransactionSettleController extends Controller
{
    public function __construct(
        private readonly TransactionSettlementService $settlement,
        private readonly OutletAccessService $outletAccessService,
    ) {}

    public function __invoke(Request $request, Transaction $transaction): RedirectResponse
    {
        $warehouse = $transaction->warehouse_id ? Warehouse::find($transaction->warehouse_id) : null;
        abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 404);

        $validated = $request->validate([
            'tenders' => ['required', 'array'],
            'tenders.*' => ['array'],
        ]);

        $this->settlement->settle($transaction, $request->user(), $validated['tenders']);

        return to_route('transactions.print', $transaction->invoice)
            ->with('success', 'Tagihan berhasil dibayar.');
    }
}
