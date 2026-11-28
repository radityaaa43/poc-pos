<?php

namespace App\Services;

use App\Models\DineOrderItem;
use App\Models\KitchenItem;
use App\Models\KitchenStation;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class KitchenIntakeService
{
    private const RECOVERY_WINDOW_MINUTES = 30;

    /**
     * Put the paid sale's lines that need preparation into the kitchen, once.
     * Safe to call repeatedly (and concurrently): the unique key on transaction_detail_id guards it.
     * Runs after the payment commits (see the callers), in its own transaction, so it makes no external calls.
     */
    public function intake(Transaction $transaction): void
    {
        if ($transaction->payment_status === 'paid') {
            $this->record($transaction);
        }
    }

    /**
     * A pay-later sale goes to the kitchen at checkout, or a QR pay-later order at accept, unpaid; paying it later records nothing more.
     * Marked by the flag, not payment_method: an accepted QR bill is an open_bill, not 'pay_later'.
     */
    public function intakeUnpaid(Transaction $transaction): void
    {
        if ($transaction->is_pay_later && $transaction->payment_status === 'unpaid') {
            $this->record($transaction);
        }
    }

    /**
     * Recovery of a pay-later sale whose intake at creation never ran, in whatever payment state it is in now (unpaid, a payment
     * attempt in flight, failed, expired or paid): the food is owed either way. Once-only like the others.
     */
    public function intakePayLater(Transaction $transaction): void
    {
        if ($transaction->is_pay_later) {
            $this->record($transaction);
        }
    }

    /** Takes in a sale found by missedSales(): a pay-later one in whatever payment state, any other only once paid. */
    public function recover(Transaction $sale): void
    {
        $sale->is_pay_later ? $this->intakePayLater($sale) : $this->intake($sale);
    }

    /**
     * The recent sales with something to make that have no kitchen item yet: paid Pay now sales, then pay-later ones.
     * The window is short: intake failures are transient, and it avoids phantom orders after products are re-flagged.
     *
     * @return array<int, Builder<Transaction>>
     */
    public function missedSales(): array
    {
        $since = now()->subMinutes(self::RECOVERY_WINDOW_MINUTES);
        // "Fully returned" = every line has its whole qty in completed returns (the rule the sales list uses for can_create_sales_return).
        $missed = fn () => Transaction::query()
            ->whereNotNull('warehouse_id')
            ->whereDoesntHave('kitchenItems')
            ->whereHas('details', fn ($detail) => $detail->goesToKitchen())
            ->whereHas('details', fn ($detail) => $detail->whereRaw(
                'transaction_details.qty > coalesce((select sum(sri.qty_return) from sales_return_items sri'
                .' join sales_returns sr on sr.id = sri.sales_return_id'
                .' where sri.transaction_detail_id = transaction_details.id and sr.status = ?), 0)',
                ['completed'],
            ));

        return [
            // Cancelled sales are not paid, so the status filter drops them. A pay-later sale is left to the second query:
            // it reached (or should have reached) the kitchen at checkout and the paid hook skips it.
            $missed()
                ->where('payment_status', 'paid')
                ->where('is_pay_later', false)
                ->where('created_at', '>=', now()->subHours(48)) // indexed prefilter; gateway sales can be paid long after they are created, so 48 h still covers them
                ->where('updated_at', '>=', $since),
            // Pay later (a POS credit sale, or a QR bill accepted as pay later): its intake belongs to its creation, not to its
            // payment, so the window runs on created_at and the payment status is ignored (unpaid, a payment attempt in flight,
            // failed, expired or paid): the food is owed in every state, and a bill paid after the window must not be left out.
            // A cancelled bill is fully returned (skipped above); a rejected order has no transaction.
            $missed()
                ->where('is_pay_later', true)
                ->where('created_at', '>=', $since),
        ];
    }

    private function record(Transaction $transaction): void
    {
        // A sale with no warehouse (a shift opened without one, or a legacy sale) has no outlet to cook for, and
        // kitchen_items.warehouse_id is required: skip it, like the release backfill, instead of failing the payment.
        if ($transaction->warehouse_id === null) {
            return;
        }

        // Once per sale: the flag is read at the first paid flip, and a paid sale never goes back to unpaid here.
        if ($transaction->kitchenItems()->exists()) {
            return;
        }

        $transaction->load('details.product:id,needs_preparation,kitchen_station_id', 'dineOrder.items');
        // Active stations in one query; a product pointing at a deleted or no station goes to the default one.
        $stations = KitchenStation::get(['id', 'is_default']);
        $defaultStationId = $stations->firstWhere('is_default', true)->id ?? KitchenStation::defaultId();
        /** @var list<DineOrderItem> $dineItems */
        $dineItems = $transaction->dineOrder?->items->all() ?? [];

        // All or nothing: a failure midway must not leave some lines recorded, or the once-only guard above would skip the rest forever.
        DB::transaction(function () use ($transaction, $dineItems, $stations, $defaultStationId) {
            foreach ($transaction->details as $detail) {
                // The line's sale-time snapshot decides (the product's flag only for lines written before it existed),
                // so a later product edit changes neither the items in the kitchen nor a retry's result.
                if (! $detail->goesToKitchen()) {
                    continue;
                }

                // The line's own note: the detail is the single source (POS checkout and dine accept both write it),
                // so two lines of one menu that differ by options never trade notes. Older dine sales fall back below.
                $note = $detail->note;
                if ($note === null) {
                    // shortcut: pre-deploy dine sales carry the note only on the dine item; drop this fallback when none are in flight
                    foreach ($dineItems as $i => $item) {
                        if ($item->product_id == $detail->product_id && $item->unit_id == $detail->unit_id) {
                            $note = $item->note;
                            unset($dineItems[$i]);
                            break;
                        }
                    }
                }

                KitchenItem::createOrFirst(['transaction_detail_id' => $detail->id], [
                    'transaction_id' => $transaction->id,
                    'product_id' => $detail->product_id,
                    'warehouse_id' => $transaction->warehouse_id,
                    'kitchen_station_id' => $stations->contains('id', $detail->product->kitchen_station_id) ? $detail->product->kitchen_station_id : $defaultStationId,
                    'qty' => $detail->qty,
                    'note' => $note,
                    'status' => KitchenItem::STATUS_ACCEPTED,
                    'accepted_at' => now(),
                    'accepted_by' => auth()->id(), // null for webhooks and the scheduler
                ]);
            }
        });

        // BR-1 for Pay now only: a pay-later sale (unpaid here) is served at accept in #279.
        if ($transaction->payment_status === 'paid') {
            app(KitchenItemService::class)->serveIfNoKitchenItems($transaction);
        }
    }
}
