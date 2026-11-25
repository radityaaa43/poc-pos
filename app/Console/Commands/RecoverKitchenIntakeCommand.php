<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\KitchenIntakeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecoverKitchenIntakeCommand extends Command
{
    protected $signature = 'kitchen:recover-intake';

    // Intake failures are transient, and a short window avoids phantom orders after products are re-flagged.
    private const WINDOW_MINUTES = 30;

    protected $description = 'Put paid sales of the last 30 minutes that missed the kitchen intake into the kitchen';

    public function handle(KitchenIntakeService $intake): int
    {
        $recovered = 0;
        $failed = 0;

        // Cancelled sales are not paid, so the status filter drops them. A pay-later sale reached the kitchen at checkout.
        // "Fully returned" = every line has its whole qty in completed returns (the rule the sales list uses for can_create_sales_return).
        Transaction::query()
            ->where('payment_status', 'paid')
            ->where('is_pay_later', false)
            ->whereNotNull('warehouse_id')
            ->where('created_at', '>=', now()->subHours(48)) // indexed prefilter; gateway sales can be paid long after they are created, so 48 h still covers them
            ->where('updated_at', '>=', now()->subMinutes(self::WINDOW_MINUTES))
            ->whereDoesntHave('kitchenItems')
            ->whereHas('details', fn ($detail) => $detail->goesToKitchen())
            ->whereHas('details', fn ($detail) => $detail->whereRaw(
                'transaction_details.qty > coalesce((select sum(sri.qty_return) from sales_return_items sri'
                .' join sales_returns sr on sr.id = sri.sales_return_id'
                .' where sri.transaction_detail_id = transaction_details.id and sr.status = ?), 0)',
                ['completed'],
            ))
            ->chunkById(100, function ($sales) use ($intake, &$recovered, &$failed) {
                foreach ($sales as $sale) {
                    // A failure is reported and the loop goes on; the next run tries again, so there is no retry in here.
                    $done = rescue(function () use ($intake, $sale) {
                        $intake->intake($sale);

                        return $sale->kitchenItems()->exists();
                    }, false);

                    $done ? $recovered++ : $failed++;
                }
            });

        Log::info('kitchen:recover-intake', ['recovered' => $recovered, 'failed' => $failed]);
        $this->info("Kitchen intake recovered for {$recovered} sale(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
