<?php

namespace App\Http\Controllers\Apps;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\OutletAccessService;
use App\Services\TransactionPaymentRetryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Pay a failed or expired POS sale again: the same method (Coba lagi) or another one.
 * Its own controller because TransactionController is at its file length limit.
 */
class TransactionRetryController extends Controller
{
    public function __construct(
        protected readonly TransactionPaymentRetryService $paymentRetryService,
        protected readonly OutletAccessService $outletAccessService,
    ) {}

    public function __invoke(Request $request, Transaction $transaction)
    {
        $warehouse = $transaction->warehouse_id ? $transaction->warehouse : null;
        abort_unless($this->outletAccessService->canUseWarehouse($request->user(), $warehouse), 404);

        $data = $request->validate([
            'payment_method' => ['required', Rule::in(['cash', 'qris', 'midtrans', 'xendit', 'bank_transfer'])],
            'cash_amount' => ['exclude_unless:payment_method,cash', 'required', 'integer', 'min:'.$this->paymentRetryService->amountDue($transaction)],
            'bank_account_id' => ['exclude_unless:payment_method,bank_transfer', 'required', 'integer', 'exists:bank_accounts,id'],
        ]);

        $print = redirect()->route('transactions.print', $transaction->invoice);

        try {
            $sale = $this->paymentRetryService->retry($transaction, $request->user(), $data);
        } catch (PaymentGatewayException $exception) {
            return $print->with('error', $exception->getMessage());
        }

        return $sale->payment_status === 'paid'
            ? $print->with('success', "Pembayaran untuk invoice {$sale->invoice} berhasil dicatat.")
            : $print->with('success', "Menunggu pembayaran untuk invoice {$sale->invoice}.");
    }
}
