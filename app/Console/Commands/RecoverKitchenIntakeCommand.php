<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\KitchenIntakeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecoverKitchenIntakeCommand extends Command
{
    protected $signature = 'kitchen:recover-intake';

    protected $description = 'Put paid sales of the last 24 hours that missed the kitchen intake into the kitchen';

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
            ->where('created_at', '>=', now()->subHours(48)) // cheap prefilter: skips old rows before the updated_at window
            ->where('updated_at', '>=', now()->subDay())
            ->whereDoesntHave('kitchenItems')
            ->whereHas('details.product', fn ($product) => $product->where('needs_preparation', true))
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
