<?php

namespace App\Services;

use App\Models\DineOrderItem;
use App\Models\KitchenItem;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class KitchenIntakeService
{
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

    /** A pay-later sale goes to the kitchen at checkout, unpaid; paying its receivable later records nothing more. */
    public function intakeUnpaid(Transaction $transaction): void
    {
        if ($transaction->payment_method === 'pay_later' && $transaction->payment_status === 'unpaid') {
            $this->record($transaction);
        }
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

        $transaction->load('details.product:id,needs_preparation', 'dineOrder.items');
        // Dine-in lines carry a note ("no sugar"); match each sale line to the first unused order line of the same product and unit.
        /** @var list<DineOrderItem> $dineItems */
        $dineItems = $transaction->dineOrder?->items->all() ?? [];

        // All or nothing: a failure midway must not leave some lines recorded, or the once-only guard above would skip the rest forever.
        DB::transaction(function () use ($transaction, $dineItems) {
            foreach ($transaction->details as $detail) {
                // The flag is read now: a later product edit must not change items already in the kitchen.
                if (! $detail->product?->needs_preparation) {
                    continue;
                }

                $note = null;
                foreach ($dineItems as $i => $item) {
                    if ($item->product_id == $detail->product_id && $item->unit_id == $detail->unit_id) {
                        $note = $item->note;
                        unset($dineItems[$i]);
                        break;
                    }
                }

                KitchenItem::createOrFirst(['transaction_detail_id' => $detail->id], [
                    'transaction_id' => $transaction->id,
                    'product_id' => $detail->product_id,
                    'warehouse_id' => $transaction->warehouse_id,
                    'qty' => $detail->qty,
                    'note' => $note,
                    'status' => KitchenItem::STATUS_ACCEPTED,
                    'accepted_at' => now(),
                    'accepted_by' => auth()->id(), // null for webhooks and the scheduler
                ]);
            }
        });
    }
}
