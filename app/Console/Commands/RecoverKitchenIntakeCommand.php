<?php

namespace App\Console\Commands;

use App\Services\KitchenIntakeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RecoverKitchenIntakeCommand extends Command
{
    protected $signature = 'kitchen:recover-intake';

    protected $description = 'Put sales of the last 30 minutes that missed the kitchen intake into the kitchen';

    public function handle(KitchenIntakeService $intake): int
    {
        $recovered = 0;
        $failed = 0;

        $recover = function ($sales) use ($intake, &$recovered, &$failed) {
            foreach ($sales as $sale) {
                // A failure is reported and the loop goes on; the next run tries again, so there is no retry in here.
                $done = rescue(function () use ($intake, $sale) {
                    // Once-only (the unique transaction_detail_id key).
                    $intake->recover($sale);

                    return $sale->kitchenItems()->exists();
                }, false);

                $done ? $recovered++ : $failed++;
            }
        };

        foreach ($intake->missedSales() as $sales) {
            $sales->chunkById(100, $recover);
        }

        Log::info('kitchen:recover-intake', ['recovered' => $recovered, 'failed' => $failed]);
        $this->info("Kitchen intake recovered for {$recovered} sale(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
