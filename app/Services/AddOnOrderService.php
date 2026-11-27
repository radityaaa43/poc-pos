<?php

namespace App\Services;

use App\Models\CashierShift;
use App\Models\DineOrder;
use App\Models\DiningTable;
use App\Models\Transaction;
use Illuminate\Validation\ValidationException;

/**
 * Add-on orders (#251, REQ-003 AC-21): the one place for their rules. An add-on is its own sale, linked to the root of
 * the order it was made from. Nothing here writes to that order.
 */
class AddOnOrderService
{
    /** BR-11, the same words on the POS and the table QR. */
    public const CANCELLED_MESSAGE = 'Pesanan asal sudah dibatalkan — buat pesanan baru';

    public function __construct(private readonly AuditLogService $auditLogService) {}

    /**
     * POS: the root sale id a new sale links to, or null when the order is finished and a new one starts (BR-6).
     * Call it inside the checkout's DB::transaction, so the check and the sale are atomic; $lock is false outside one.
     *
     * @throws ValidationException an unknown order, another outlet's order, or a cancelled one (BR-11)
     */
    public function resolveForPos(string $invoice, CashierShift $shift, bool $lock = true): ?int
    {
        $original = Transaction::where('invoice', $invoice)->first() ?? $this->refuse('Pesanan asal tidak ditemukan');
        $root = $this->root($original, $lock);

        // Any warehouse of the same outlet, as on the kitchen display and the payment retry.
        $sameOutlet = (int) $root->warehouse_id === (int) $shift->warehouse_id
            || ($root->warehouse?->outlet_id !== null && $root->warehouse->outlet_id === $shift->warehouse?->outlet_id);

        if (! $sameOutlet) {
            $this->refuse('Buka shift di outlet pesanan ini');
        }

        return $this->linkTo($root, $original);
    }

    /**
     * What the POS says about an add-on before checkout; the checkout decides again.
     *
     * @return array{invoice: string, state: string, reason: ?string} state: linked, completed, cancelled or refused
     */
    public function preview(string $invoice, CashierShift $shift): array
    {
        try {
            $state = $this->resolveForPos($invoice, $shift, false) ? 'linked' : 'completed';
            $reason = null;
        } catch (ValidationException $e) {
            $reason = $e->getMessage();
            $state = $reason === self::CANCELLED_MESSAGE ? 'cancelled' : 'refused';
        }

        return compact('invoice', 'state', 'reason');
    }

    /**
     * QR submit: the order whose status page the guest ordered from. The token is guest input, so it must name an
     * accepted order of this table (BR-10) that was not cancelled (BR-11).
     *
     * @throws ValidationException
     */
    public function originalForGuest(string $accessToken, DiningTable $table): DineOrder
    {
        $original = DineOrder::where('access_token', $accessToken)->where('dine_table_id', $table->id)->first()
            ?? $this->refuse('Pesanan asal tidak ditemukan di meja ini');
        $this->resolveForDine($original, false);

        if (! $original->transaction_id) {
            $this->refuse('Pesanan asal belum diterima — tunggu konfirmasi staf');
        }

        return $original;
    }

    /**
     * QR accept: the root sale id, or null for a new order: nothing referenced, or the whole order is finished (BR-13).
     * A table is never enough to link two orders (BR-10). Call it inside the accept's DB::transaction.
     *
     * @throws ValidationException a cancelled original (BR-11, BR-12)
     */
    public function resolveForDine(?DineOrder $original, bool $lock = true): ?int
    {
        if (in_array($original?->status, [DineOrder::STATUS_REJECTED, DineOrder::STATUS_CANCELLED], true)) {
            $this->refuse(self::CANCELLED_MESSAGE);
        }

        $sale = $original?->transaction_id ? Transaction::when($lock, fn ($query) => $query->lockForUpdate())->find($original->transaction_id) : null;

        // A completed original is not enough to start afresh: an add-on of it may still be open (BR-13).
        return $sale ? $this->linkTo($this->root($sale, $lock), $sale) : null;
    }

    /** The receipt's "Tambah pesanan": hidden once the sale, or the order it belongs to, is cancelled (BR-12). */
    public function offersAddOn(Transaction $sale): bool
    {
        return ! $this->isCancelled($sale) && ! ($sale->parent && $this->isCancelled($sale->parent));
    }

    /** The trail of who linked what; call it once the add-on sale exists. */
    public function record(Transaction $addOn): void
    {
        $this->auditLogService->log(
            event: 'transaction.add_on_created',
            module: 'transactions',
            auditable: $addOn,
            description: "Pesanan tambahan {$addOn->invoice} dibuat.",
            meta: ['parent_transaction_id' => $addOn->parent_transaction_id],
        );
    }

    private function root(Transaction $sale, bool $lock): Transaction
    {
        return Transaction::with('warehouse')->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($sale->orderRootId());
    }

    private function linkTo(Transaction $root, Transaction $original): ?int
    {
        if ($this->isCancelled($root) || $this->isCancelled($original)) {
            $this->refuse(self::CANCELLED_MESSAGE);
        }

        // BR-13: open while any live sale of the order owes money (BR-9) or has kitchen work; else finished (BR-6).
        $open = Transaction::where('parent_transaction_id', $root->id)->get()->prepend($root)->contains(fn (Transaction $sale) => (
            $sale->payment_status !== 'paid' || $sale->kitchenItems()->open()->exists()
        ) && ! $this->isCancelled($sale));

        return $open ? $root->id : null;
    }

    /**
     * BR-12: expired and restocked, a cancelled or rejected dine order, or every line returned. A failed or expired
     * payment alone is not: that bill can still be paid.
     */
    private function isCancelled(Transaction $sale): bool
    {
        return $sale->wasRestocked()
            || in_array($sale->dineOrder?->status, [DineOrder::STATUS_REJECTED, DineOrder::STATUS_CANCELLED], true)
            || ! Transaction::whereKey($sale->id)->notFullyReturned()->exists();
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['add_on_to' => $message]);
    }
}
