<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\KitchenIntakeService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class RecoverKitchenIntakeCommand extends Command
{
    protected $signature = 'kitchen:recover-intake';

    // Intake failures are transient, and a short window avoids phantom orders after products are re-flagged.
    private const WINDOW_MINUTES = 30;

    protected $description = 'Put sales of the last 30 minutes that missed the kitchen intake into the kitchen';

    public function handle(KitchenIntakeService $intake): int
    {
        $recovered = 0;
        $failed = 0;

        $recover = function ($sales) use ($intake, &$recovered, &$failed) {
            foreach ($sales as $sale) {
                // A failure is reported and the loop goes on; the next run tries again, so there is no retry in here.
                $done = rescue(function () use ($intake, $sale) {
                    // Both are once-only (the unique transaction_detail_id key) and each acts on its own payment state.
                    $sale->payment_status === 'paid' ? $intake->intake($sale) : $intake->intakeUnpaid($sale);

                    return $sale->kitchenItems()->exists();
                }, false);

                $done ? $recovered++ : $failed++;
            }
        };

        // Paid now: cancelled sales are not paid, so the status filter drops them. A pay-later sale is handled below, because
        // it reached (or should have reached) the kitchen at checkout and the paid hook skips it.
        $this->missed()
            ->where('payment_status', 'paid')
            ->where('is_pay_later', false)
            ->where('created_at', '>=', now()->subHours(48)) // indexed prefilter; gateway sales can be paid long after they are created, so 48 h still covers them
            ->where('updated_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->chunkById(100, $recover);

        // Pay later (a POS credit sale, or a QR bill accepted as pay later): its intake belongs to its creation, not to its payment,
        // so the window runs on created_at. Unpaid, or paid within the window but never taken in: the food is still owed.
        // The other states (a payment attempt in flight, failed, expired) are picked up when the bill is unpaid or paid again.
        $this->missed()
            ->where('is_pay_later', true)
            ->whereIn('payment_status', ['unpaid', 'paid'])
            ->where('created_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->chunkById(100, $recover);

        Log::info('kitchen:recover-intake', ['recovered' => $recovered, 'failed' => $failed]);
        $this->info("Kitchen intake recovered for {$recovered} sale(s), {$failed} failed.");

        return self::SUCCESS;
    }

    /**
     * Sales with something to make that have no kitchen item yet.
     * "Fully returned" = every line has its whole qty in completed returns (the rule the sales list uses for can_create_sales_return).
     *
     * @return Builder<Transaction>
     */
    private function missed(): Builder
    {
        return Transaction::query()
            ->whereNotNull('warehouse_id')
            ->whereDoesntHave('kitchenItems')
            ->whereHas('details', fn ($detail) => $detail->goesToKitchen())
            ->whereHas('details', fn ($detail) => $detail->whereRaw(
                'transaction_details.qty > coalesce((select sum(sri.qty_return) from sales_return_items sri'
                .' join sales_returns sr on sr.id = sri.sales_return_id'
                .' where sri.transaction_detail_id = transaction_details.id and sr.status = ?), 0)',
                ['completed'],
            ));
    }
}
