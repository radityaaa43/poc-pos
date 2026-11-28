<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\UnpaidSaleCancellationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpirePendingTransactionsCommand extends Command
{
    protected $signature = 'transactions:expire {--hours=24} {--dry-run}';

    protected $description = 'Expire gateway transactions stuck in pending payment and restock their items';

    public function handle(UnpaidSaleCancellationService $cancellationService): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subHours($hours);

        $transactions = Transaction::query()
            ->whereIn('payment_method', ['midtrans', 'xendit', 'qris', 'bank_transfer'])
            ->where('payment_status', 'pending')
            ->where('is_pay_later', false) // a pay-later bill settled by gateway and left pending: the food is already made, never restock it
            ->whereDoesntHave('dineOrder') // a dine-in bill is created at accept and paid later: its age says nothing about abandonment
            // A retried payment (payment_attempt > 0) is as old as its last retry, not as the sale: a retry late in a
            // long shift must not be expired and restocked minutes after the new charge was created.
            ->where(fn ($query) => $query
                ->where(fn ($q) => $q->where('payment_attempt', 0)->where('created_at', '<', $cutoff))
                ->orWhere(fn ($q) => $q->where('payment_attempt', '>', 0)->where('updated_at', '<', $cutoff)))
            ->orderBy('id')
            ->get();

        if ($transactions->isEmpty()) {
            $this->info('No pending gateway transactions older than '.$hours.' hours.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            foreach ($transactions as $transaction) {
                $this->line("[dry-run] {$transaction->invoice} ({$transaction->payment_method}, created {$transaction->created_at})");
            }
            $this->info($transactions->count().' transaction(s) would be expired.');

            return self::SUCCESS;
        }

        $expired = 0;

        foreach ($transactions as $transaction) {
            try {
                DB::transaction(function () use ($transaction, $cancellationService, &$expired) {
                    $locked = Transaction::query()
                        ->lockForUpdate()
                        ->findOrFail($transaction->id);

                    // Lost the race with a webhook: status changed meanwhile.
                    if ($locked->payment_status !== 'pending') {
                        return;
                    }

                    $cancellationService->cancel(
                        $locked,
                        "Auto-expire pembayaran {$locked->invoice}",
                        'Dibatalkan otomatis: pembayaran tidak diterima dalam batas waktu.',
                    );

                    $expired++;
                }, 3);
            } catch (\Throwable $e) {
                report($e);
                $this->error("Failed to expire {$transaction->invoice}: {$e->getMessage()}");
            }
        }

        $this->info("Expired {$expired} transaction(s).");

        return self::SUCCESS;
    }
}
